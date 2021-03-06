<?php
namespace DreamCommerce;

use DreamCommerce\Exception\ClientException;
use DreamCommerce\Exception\ResourceException;

/**
 * Class Resource
 * @package DreamCommerce
 */
abstract class Resource
{
    /**
     * @var ClientInterface|null
     */
    public $client = null;

    /**
     * @var string|null resource name
     */
    protected $name = null;

    /**
     * @var null|string chosen filters placeholder
     */
    protected $filters = null;
    /**
     * @var null|int limiter value
     */
    protected $limit = null;
    /**
     * @var null|string ordering value
     */
    protected $order = null;
    /**
     * @var null|int page number
     */
    protected $page = null;
    /**
     * @var bool specifies whether resource has no collection at all
     */
    protected $isSingleOnly = false;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @param ClientInterface $client
     * @param $name
     * @return Resource
     * @throws ResourceException
     */
    public static function factory(ClientInterface $client, $name)
    {
        $class = "\\DreamCommerce\\Resource\\".ucfirst($name);
        if(class_exists($class)){
            return new $class($client);
        } else {
            throw new ResourceException("Unknown Resource '".$name."'");
        }
    }

    /**
     * returns resource name
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $response
     * @param bool $isCollection should transform response as a collection?
     * @throws ResourceException
     * @return mixed
     */
    protected function transformResponse($response, $isCollection)
    {
        $code = $response['headers']['Code'];

        // everything is okay when 200-299 status code
        if($code>=200 && $code<300){
            // for example, last insert ID
            if($isCollection){
                $list = $response['data']['list'];
                if($list == null){
                    return new ResourceList();
                }

                $result = new ResourceList($list);
                // add meta properties (eg. count, page, etc) as a ArrayObject properties
                $result->setPage($response['data']['page']);
                $result->setCount($response['data']['count']);
                $result->setPageCount($response['data']['pages']);

                return $result;
            }else{
                return new \ArrayObject(
                    ResourceList::transform($response['data'])
                );
            }

        }else{

            $msg = '';

            // look up for error
            if(isset($response['data']['error'])){
                $msg = $response['data']['error'];
            }

            throw new ResourceException($msg, $code);
        }
    }

    /**
     * reset filters object state
     */
    protected function reset()
    {
        $this->filters = array();
        $this->limit = null;
        $this->order = null;
        $this->page = null;
    }

    /**
     * get an array with specified criteria
     * @return array
     */
    protected function getCriteria()
    {
        $result = array();

        if($this->filters){
            $result['filters'] = $this->filters;
        }

        if($this->limit!==null){
            $result['limit'] = $this->limit;
        }

        if($this->order!==null){
            $result['order'] = $this->order;
        }

        if($this->page!==null){
            $result['page'] = $this->page;
        }

        // reset object state, we don't need it for further requests
        $this->reset();

        return $result;
    }

    /**
     * set records limit
     * @param int $count collection's items limit in range 1-50
     * @return $this
     * @throws ResourceException
     */
    public function limit($count)
    {
        if($count<1 || $count>50){
            throw new ResourceException('Limit beyond 1-50 range', ResourceException::LIMIT_BEYOND_RANGE);
        }

        $this->limit = $count;

        return $this;
    }

    /**
     * set filters for finding
     * @param array $filters
     * @return $this
     * @throws ResourceException
     */
    public function filters($filters){
        if(!is_array($filters)){
            throw new ResourceException('Filters not specified', ResourceException::FILTERS_NOT_SPECIFIED);
        }

        $this->filters = json_encode($filters);

        return $this;
    }

    /**
     * specify page
     * @param int $page
     * @return $this
     * @throws ResourceException
     */
    public function page($page)
    {
        $page = (int)$page;

        if($page<0){
            throw new ResourceException('Invalid page specified', ResourceException::INVALID_PAGE);
        }

        $this->page = $page;

        return $this;
    }

    /**
     * order record by column
     * @param string $expr syntax:
     * <field> (asc|desc)
     * or
     * (+|-)<field>
     * @return $this
     * @throws ResourceException
     */
    public function order($expr)
    {
        $matches = array();

        // basic syntax, with asc/desc suffix
        if(preg_match('/([a-z_0-9.]+) (asc|desc)$/i', $expr)) {
            $this->order = $expr;
        } else if(preg_match('/([\+\-]?)([a-z_0-9.]+)/i', $expr, $matches)) {

            // alternative syntax - with +/- prefix
            $result = $matches[2];
            if($matches[1]=='' || $matches[1]=='+'){
                $result .= ' asc';
            } else {
                $result .= ' desc';
            }
            $this->order = $result;
        } else {
            // something which should never happen but take care [;
            throw new ResourceException('Cannot understand ordering expression', ResourceException::ORDER_NOT_SUPPORTED);
        }

        return $this;

    }

    /**
     * Read Resource
     * @param mixed $args,... params
     * @return \ArrayObject
     * @throws ResourceException
     */
    public function get()
    {
        $query = $this->getCriteria();

        $args = func_get_args();
        if(empty($args)){
            $args = null;
        }

        $isCollection = !$this->isSingleOnly && count($args)==0;

        try {
            $response = $this->client->request($this, 'get', $args, array(), $query);
        } catch(ClientException $ex) {
            throw new ResourceException($ex->getMessage(), ResourceException::CLIENT_ERROR, $ex);
        }

        return $this->transformResponse($response, $isCollection);
    }

    /**
     * Create Resource
     * @param array $data
     * @return integer
     * @throws ResourceException
     */
    public function post($data)
    {
        if($this->getCriteria()){
            throw new ResourceException('Filtering not supported in POST', ResourceException::FILTERS_IN_UNSUPPORTED_METHOD);
        }

        $args = func_get_args();
        if(count($args) == 1) {
            $args = null;
        } else {
            $data = array_pop($args);
        }

        try {
            $response = $this->client->request($this, 'post', $args, $data);
        } catch (ClientException $ex) {
            throw new ResourceException($ex->getMessage(), ResourceException::CLIENT_ERROR, $ex);
        }

        return $response['data'];
    }

    /**
     * Update Resource
     * @param null|int $id
     * @param array $data
     * @return bool
     * @throws ResourceException
     */
    public function put($id = null, $data = array()){

        if($this->getCriteria()){
            throw new ResourceException('Filtering not supported in PUT', ResourceException::FILTERS_IN_UNSUPPORTED_METHOD);
        }

        $args = func_get_args();
        if(count($args) == 2){
            $args = $id;
        }else{
            $data = array_pop($args);
        }

        try {
            $this->client->request($this, 'put', $args, $data);
        } catch(ClientException $ex) {
            throw new ResourceException($ex->getMessage(), ResourceException::CLIENT_ERROR, $ex);
        }

        return true;
    }

    /**
     * Delete Resource
     * @param int $id
     * @return bool
     * @throws ResourceException
     */
    public function delete($id = null){

        if($this->getCriteria()){
            throw new ResourceException('Filtering not supported in DELETE', ResourceException::FILTERS_IN_UNSUPPORTED_METHOD);
        }

        $args = func_get_args();
        if(count($args) == 1){
            $args = $id;
        }

        try {
            $this->client->request($this, 'delete', $args);
        }catch(ClientException $ex){
            throw new ResourceException($ex->getMessage(), ResourceException::CLIENT_ERROR, $ex);
        }

        return true;
    }

}
