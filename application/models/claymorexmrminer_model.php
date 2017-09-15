<?php
/*
 * Cgminer_model
 * CGminer model for minera
 *
 * @author michelem
 */
class ClaymoreXmrminer_model extends CI_Model {

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
			$cmd = '{"id":0,"jsonrpc":"2.0","method":"miner_getstat1"}';
 
		log_message("error", "Called Minerd with command: ".$cmd);
		
		$ip = "127.0.0.1"; $port = 3333;

		if ($network) list($ip, $port) = explode(":", $network);

		$socket = $this->getsock($ip, $port);
		if ($socket != null)
		{
			socket_write($socket, $cmd, strlen($cmd));
			$line = $this->readsockline($socket);
			socket_close($socket);

			if (strlen($line) == 0)
			{
				$msg = "WARN: '$cmd' returned nothing\n";
				return array("error" => true, "msg" => $msg);
			}
		
			//print "$cmd returned '$line'\n";
			if (substr($line,0,1) == '{') {
				// log_message("error", var_dump(json_decode($line)));
				return json_decode($line);
			}
			
			$data = array();
			
			$objs = explode('|', $line);
			foreach ($objs as $obj)
			{
				if (strlen($obj) > 0)
				{
					$items = explode(',', $obj);
					$item = $items[0];
					$id = explode('=', $items[0], 2);
					if (count($id) == 1 or !ctype_digit($id[1]))
						$name = $id[0];
					else
						$name = $id[0].$id[1];
			
					if (strlen($name) == 0)
						$name = 'null';
			
					if (isset($data[$name]))
					{
						$num = 1;
						while (isset($data[$name.$num]))
							$num++;
						$name .= $num;
					}
			
					$counter = 0;
					foreach ($items as $item)
					{
						$id = explode('=', $item, 2);
						if (count($id) == 2)
							$data[$name][$id[0]] = $id[1];
						else
							$data[$name][$counter] = $id[0];
			
						$counter++;
					}
				}
			}
			
			if (isset($data->STATUS->STATUS) && $data->STATUS->STATUS == 'E') {
				return array("error" => true, "msg" => $data->STATUS->Msg);				
			}
			
			return $data;
		}
		
		return array("error" => true, "msg" => "Miner error");
	}
    
    /*
        GPU index, or "-1" for all GPUs
        State, 0 - disabled, 1 - ETH-only mode, 2 - dual mode.
    */
	public function ControlGPU($gpuIndex, $state)
	{
        $stateDesc = "Disabled";
        $gpuDesc = "All GPU";

        if ($state==1) {
            $stateDesc = "Monero mode";  
        }
        else if ($state==2) {
            $stateDesc = "Dual mode";  
        }

        if ($gpuIndex !=-1) {
            $gpuDesc = "GPU #".(int)$gpuIndex;
        }

		log_message("error", "Trying to control gpu. ".$gpuDesc." (".$stateDesc.")");
		$o = $this->callMinerd('{"id":0,"jsonrpc":"2.0","method":"control_gpu", "params":['.$gpuIndex.','.$state.']}', $network);
		log_message("error", var_export($o, true));
		return $o;
	}

	/*
	RESPONSE: result[0-8]
	{"result": ["9.3 - ETH", "21", "182724;51;0", "30502;30457;30297;30481;30479;30505", "0;0;0", "off;off;off;off;off;off", "53;71;57;67;61;72;55;70;59;71;61;70", "eth-eu1.nanopool.org:9999", "0;0;0;0"]}
	result[0] - "9.3 - ETH"				- miner version.
	result[1] - "21"					- running time, in minutes.
	result[2] - "182724;51;0"				- total ETH hashrate in MH/s, number of ETH shares, number of ETH rejected shares.
	result[3] - "30502;30457;30297;30481;30479;30505"	- detailed ETH hashrate for all GPUs.
	result[4] - "0;0;0"					- total DCR hashrate in MH/s, number of DCR shares, number of DCR rejected shares.
	result[5] - "off;off;off;off;off;off"		- detailed DCR hashrate for all GPUs.
	result[6] - "53;71;57;67;61;72;55;70;59;71;61;70"	- Temperature and Fan speed(%) pairs for all GPUs.
	result[7] - "eth-eu1.nanopool.org:9999"		- current mining pool. For dual mode, there will be two pools here.
	result[8] - "0;0;0;0"				- number of ETH invalid shares, number of ETH pool switches, number of DCR invalid shares, number of DCR pool switches.
	
	*/
	public function getParsedStats($stats, $network = false) {
		$d = 0; $tdevice = array(); $tdtemperature = 0; $tdfrequency = 0; $tdaccepted = 0; $tdrejected = 0; $tdhwerrors = 0; $tdshares = 0; $tdhashrate = 0; $devicePoolActives = false;
		$return = false;

		if (isset($stats->start_time))
		{
			$return['start_time'] = $stats->start_time;
		}
		elseif (isset($stats->result[1]))
		{
			$return['start_time'] = round((time() - ($stats->result[1] * 60 * 1000)), 0);
		}	
		
		$poolHashrate = 0;
		
		if (isset($stats->result[3])) {
			
			$devsHashrates = explode(';',($stats->result[3]));

			for($index = 0; $index < count($devsHashrates); $index++)
			{
				$d++; $c = 0; $tcfrequency = 0; $tcaccepted = 0; $tcrejected = 0; $tchwerrors = 0; $tcshares = 0; $tchashrate = 0; $tclastshares = array();
								
				$name = 'GPU #'.(int)$index;
				
				$return['devices'][$name]['temperature'] = (isset($device->result[6])) ? explode(';',($device->result[6]))[$index * 2 + 1] : false;
				$return['devices'][$name]['frequency'] = false;
				$return['devices'][$name]['accepted'] = 0;
				$return['devices'][$name]['rejected'] = 0;
				$return['devices'][$name]['hw_errors'] = 0;

				// difficulty
				$return['devices'][$name]['shares'] = 0;	
				// hashrate in Mh, convert to h
				$return['devices'][$name]['hashrate'] = ($devsHashrates[$index]*1000*1000);
				
				// make it always running due to the last_share checking in app.php
				$return['devices'][$name]['last_share'] = time();
				$return['devices'][$name]['serial'] = false;

				$tdtemperature += $return['devices'][$name]['temperature'];					
				$tdfrequency += $return['devices'][$name]['frequency'];
				$tdshares += $return['devices'][$name]['shares'];
				$tdhashrate += $return['devices'][$name]['hashrate'];

			}						
		}

		if (is_object($stats)) {
			$totalsHash = explode(";",$stats->result[2]); 
			$return['totals']['temperature'] = ($tdtemperature) ? round(($tdtemperature/$d), 2) : false;				
			$return['totals']['frequency'] = ($tdfrequency) ? round(($tdfrequency/$d), 0) : false;
			$return['totals']['accepted'] = $totalsHash[1];
			$return['totals']['rejected'] = $totalsHash[2];
			$return['totals']['hw_errors'] = 0;
			$return['totals']['shares'] = $tdshares;
			$return['totals']['hashrate'] = ($tdhashrate) ? $tdhashrate : $totalsHash[0];
			$return['totals']['last_share'] = time();	
		}

		if (isset($stats->pools))
		{
			$return['pool']['hashrate'] = 0;
			$return['pool']['url'] = null;
			$return['pool']['alive'] = 0;

			foreach ($stats->pools as $poolIndex => $pool)
			{

					if ((isset($pool->active) && $pool->active == 1) || (isset($pool->{'Stratum Active'}) && $pool->{'Stratum Active'} == 1) )
					{
						$return['pool']['url'] = $pool->url;
						$return['pool']['alive'] = $pool->alive;
					}
					$return['pool']['hashrate'] = $tdhashrate;
			}
		}	
		return json_encode($return);	
	}	

	
}
