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
        var $stateDesc = "Disabled";
        var $gpuDesc = "All GPU";

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

	
}
