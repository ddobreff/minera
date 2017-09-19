<?php
/*
 * ethnanopool_model
 * eth.nanopool.org pool model for minera
 *
 * @author skywills
 */
class Etherminepool_model extends CI_Model {


	public function __construct()
	{
		parent::__construct();
    }
    
    public function callAPI($address,$method)
    {
        if (!isset($address)) return false;
        $api_url = 'https://api.nanopool.org/v1/eth/'.$method.'/'.$address;       
        $ctx = stream_context_create(array('http' => array('timeout' => 10)));
        
        $result = json_decode(@file_get_contents($api_url, 0, $ctx));

        if(is_object($result) && $result->status =='OK'){
            return $result->data;
        }

        return false;
    }


    public function getWorkers($address)
    {
       $method = 'workers';
       return $this->callAPI($address, $method);
    }
}
