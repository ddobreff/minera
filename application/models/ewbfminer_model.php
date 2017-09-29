<?php
/*
 * Cgminer_model
 * CGminer model for minera
 *
 * @author michelem
 */
class Ewbfminer_model extends CI_Model {

	public function __construct()
	{
		parent::__construct();
	}

	function getsock($addr, $port)
	{
		$socket = null;
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($socket === false || $socket === null)
		{
			$error = socket_strerror(socket_last_error());
			$msg = "socket create(TCP) failed";
			log_message("error", "$msg '$error'");
			return null;
		}

		$res = socket_connect($socket, $addr, $port);
		if ($res === false)
		{
			$error = socket_strerror(socket_last_error());
			$msg = "socket connect($addr,$port) failed";
			log_message("error", "$msg '$error'");
			socket_close($socket);
			return null;
		}

		return $socket;
	}
	
	function readsockline($socket)
	{
		$line = '';
		while (true)
		{
			$byte = @socket_read($socket, 1);
			if ($byte === false || $byte === '')
				break;
			if ($byte === "\0")
				break;
			$line .= $byte;
		}
		return $line;
	}
	
	
	function callMinerd($cmd = false, $network = false)
	{
        
		if (!$cmd)
            $cmd = '{"id":1,"jsonrpc":"2.0","method":"getstat"}';
            
        $parse = json_decode($cmd);
 
		log_message("error", "Called Minerd with command: ".$cmd);
		
		$ip = "127.0.0.1"; $port = 42000;

		if ($network) list($ip, $port) = explode(":", $network);
		
		$host = 'http://'.$ip.':'.$port;
		
		//PULL IN EWBF DATA
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $cmd);

		$result = curl_exec($ch);
		if (curl_errno($ch)) {
			log_message("error",curl_error($ch));
			return array("error" => true, "msg" => "Miner error");
		}
		curl_close ($ch);	
		return json_decode($result,TRUE);		
/*   		
        $host = 'http://'.$ip.':'.$port.'/'.$parse->method;
        $ctx = stream_context_create(array('http' => array('timeout' => 10)));
        $result = @file_get_contents($host, 0, $ctx);

        if ($result !=null) {
            return json_decode($result);
        }

		return array("error" => true, "msg" => "Miner error");
*/
    }

    function isOnline($network) {
		$ip = "127.0.0.1"; $port = 42000;
		
		if ($network) list($ip, $port) = explode(":", $network);	

		return $this->checkNetworkDevice($ip, $port);
    }
    
    function checkNetworkDevice($ip, $port=42000) {
/*        
        $network = $ip.':'.$port;
       // $result = $this->callMinerd(false, $network);
       $ctx = stream_context_create(array('http' => array('timeout' => 10)));
       $host = 'http://'.$ip.':'.$port.'/';
       $result = @file_get_contents($host, 0, $ctx);

       if($result) {
           return true;
       }
       else {
           return false;
       }
        // // if is object for error mean fail to connect
        // return !is_object($result->error);
 */
       return true;
    }


	/*
    Respond example: {"id":1, "method":"getstat", "error":null, "result":[{
		"gpuid":0, 
		"cudaid":0, 
		"busid":"0000:01:00.0", 
		"gpu_status":2, 
		"solver":0, 
		"temperature":64, 
		"gpu_power_usage":150, 
		"speed_sps":420, 
		"accepted_shares":1000, 
		"rejected_shares":1
	},{
		"gpuid":1, 
		"cudaid":1, 
		"busid":"0000:04:00.0", 
		"gpu_status":2, 
		"solver":0, 
		"temperature":70, 
		"gpu_power_usage":100, 
		"speed_sps":410, 
		"accepted_shares":1111, 
		"rejected_shares":2
	}
    ]}\n
	
	*/
	public function getParsedStats($stats, $network = false) {
		$d = 0; $tdevice = array(); $tdtemperature = 0; $tdfrequency = 0; $tdaccepted = 0; $tdrejected = 0; $tdhwerrors = 0; $tdshares = 0; $tdhashrate = 0; $devicePoolActives = false;
		$tdhashrate_2nd = 0;
		$return = false;

		if (isset($stats->start_time))
		{
			$return['start_time'] = $stats->start_time;
		}
		elseif (isset($stats->result[1]))
		{
			$return['start_time'] = $stats->start_time;
		}	
		
		$poolHashrate = 0;
		
		if (isset($stats->result)) {
            foreach ($stats->result as $name => $device) {
				$d++; $c = 0; $tcfrequency = 0; $tcaccepted = 0; $tcrejected = 0; $tchwerrors = 0; $tcshares = 0; $tchashrate = 0; $tclastshares = array();
                
                $name = 'GPU #'.(int)$device->gpuid;
				$return['devices'][$name]['index'] = $device->gpuid;
				$return['devices'][$name]['temperature'] = (isset($device->temperature)) ? $device->temperature : false;
                $return['devices'][$name]['fanspeed'] = false;	  
                $return['devices'][$name]['watt'] = $device->gpu_power_usage;	
                $return['devices'][$name]['hashrate'] = $device->speed_sps;      
                $return['devices'][$name]['hashrate_2nd'] = 0;   
                $return['devices'][$name]['disabled'] = ($device->speed_sps != 0) ? false : true;    

                $return['devices'][$name]['accepted'] = $device->accepted_shares;     
                $return['devices'][$name]['rejected'] = $device->rejected_shares;   
                $return['devices'][$name]['hw_errors'] = 0;   
                                
				$tdtemperature += $return['devices'][$name]['temperature'];					
				//$tdshares += $return['devices'][$name]['shares'];
				$tdhashrate += $return['devices'][$name]['hashrate'];
                $tdhashrate_2nd += $return['devices'][$name]['hashrate_2nd']; 
                
                $tdaccepted += $return['devices'][$name]['accepted'];
                $tdrejected += $return['devices'][$name]['rejected'];
                $tdhwerrors += $return['devices'][$name]['hw_errors'];
                
            }
		}
		
		if (is_object($stats)) {
			$return['totals']['temperature'] = ($tdtemperature) ? round(($tdtemperature/$d), 2) : false;				
			$return['totals']['accepted'] = intval($tdaccepted);
			$return['totals']['rejected'] = intval($tdrejected);
			$return['totals']['hw_errors'] = intval($tdhwerrors);
			//$return['totals']['shares'] = ($tdshares) ? $tdshares : ($totalAccepted + $totalRejected + $totalHwerrors);
			$return['totals']['hashrate'] = intval($tdhashrate);
			$return['totals']['shares'] = 0;	
			$return['totals']['last_share'] = time();	
			$return['totals']['has_2nd'] = false;
			$return['totals']['hashrate_2nd'] = 0;
			$return['totals']['accepted_2nd'] = 0;
			$return['totals']['rejected_2nd'] = 0;
			$return['totals']['hw_errors_2nd'] = 0;		

			$features['has_dualmine'] = false;
			$features['is_dualmine'] = false;
			$features['restart'] = false;
			$features['reboot'] = false;
			$features['controlGPU'] = false; //temporary disable due to api not working
			
			$return['features'] = $features;		
			
			
            $url_1 = $stats->current_server;
            $url_2 = false;

			$return['pool']['hashrate'] = $return['totals']['hashrate'];
			$return['pool']['url'] = $url_1;
			$return['pool']['alive'] = 1;

			$return['pool']['hashrate_2nd'] = $return['totals']['hashrate_2nd'];
			$return['pool']['url_2nd'] = $url_2;
			$return['pool']['alive_2nd'] = ($return['totals']['has_2nd']) ? 1 : 0;

		}
		return json_encode($return);	
	}

}
