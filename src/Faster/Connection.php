<?php

namespace Faster;

use \EventBase;
use \EventBuffer;
use \EventBufferEvent;
use \EventUtil;
use \Event;
use \EventListener;
use \EventHttp;
use \EventHttpRequest;
use \SimpleBinary\SimpleBinary;

class Connection
{
	const FCGI_BEGIN_REQUEST = 1;
	const FCGI_ABORT_REQUEST = 2;
	const FCGI_END_REQUEST = 3;
	const FCGI_PARAMS = 4;
	const FCGI_STDIN = 5;
	const FCGI_STDOUT = 6;
	const FCGI_STDERR = 7;
	const FCGI_DATA = 8;
	const FCGI_GET_VALUES = 9;
	const FCGI_GET_VALUES_RESULT = 10;
	const FCGI_UNKNOWN_TYPE = 11;

	const FCGI_NULL_REQUEST_ID = 0;

	const FCGI_KEEP_CONN = 1;

	const FCGI_RESPONDER = 1;
	const FCGI_AUTHORIZER = 2;
	const FCGI_FILTER = 3;

	const FCGI_REQUEST_COMPLETE = 0;
	const FCGI_CANT_MPX_CONN = 1;
	const FCGI_OVERLOADED = 2;
	const FCGI_UNKNOWN_ROLE = 3;

	public function __construct($base, $fd, callable $request_service){
		$this->base = $base;
		$this->service = array('request'=>$request_service);
		$this->bev = new EventBufferEvent($this->base,$fd,EventBufferEvent::OPT_CLOSE_ON_FREE);
		$this->connections = array();
		$this->bev->setCallbacks(
			array($this, "read"),
			array($this, "write"),
			array($this, "event"), NULL
		);
		$this->bev->enable(Event::READ | Event::WRITE);
		$this->read($this->bev,array());
	}

	public function __destruct(){
		$this->bev->free();
	}

	public function read($bev,$ctx){
		while($record = $this->getRecord($bev)){
			if(empty($this->connections[$record['requestId']])){
				$this->connections[$record['requestId']] = $this->service['request']($record);
			}
			switch($record['type']){
				case self::FCGI_BEGIN_REQUEST:
					$this->connections[$record['requestId']] = $this->service['request']($record);
					break;
				case self::FCGI_PARAMS:
					$this->connections[$record['requestId']]->appendParams($record['content']);
					break;
				case self::FCGI_STDIN:
					$this->connections[$record['requestId']]->appendStdin($record['content']);
					break;
				case self::FCGI_DATA:
					$this->connections[$record['requestId']]->appendData($record['content']);
					break;
				case self::ABORT_REQUEST:
					$this->connections[$record['requestId']]->__destruct();
					unset($this->connections[$record['requestId']]);
					break;
				default:
					$bev->output->add($this->wrapResponse(
						$this->connections[$record['requestId']],
						self::FCGI_UNKNOWN_TYPE,
						$this->getUnknownTypeResponse($record['type']
					)));
					break;
			}
			if($this->connections[$record['requestId']]->readyToProcess()){
				$this->sendResponse($bev, $this->connections[$record['requestId']]);
				if(!$this->connections[$record['requestId']]->keepConnection()){
					$this->closeConnection($bev);
				}
			}
		}
	}

	private function closeConnection($bev){
		$e = Event::timer($this->base,function() use(&$e){
			$this->__destruct();
		});
		$e->addTimer(0);
	}

	private function sendResponse($bev, $conn){
		$conn->processRequest();
		$bev->output->add($this->wrapResponse($conn,self::FCGI_STDOUT));
		$bev->output->add($this->wrapResponse($conn,self::FCGI_STDOUT,'')); // empty response
		if($conn->isError()){
			$bev->output->add($this->wrapResponse($conn,self::FCGI_STDERR));
			$bev->output->add($this->wrapResponse($conn,self::FCGI_STDERR,'')); // empty response
		}
		$bev->output->add($this->wrapResponse($conn, self::FCGI_END_REQUEST, $this->getEndRequestResponse($conn)));
	}

	private function getEndRequestResponse($conn){
        $b = new SimpleBinary('');
        $b->setInt32($conn->getAppStatus());
        $b->setInt8($this->getProtocolStatus());
        $b->setInt8(0);
        $b->setInt8(0);
        $b->setInt8(0);
        return $b->getBinary();
	}

	private function getProtocolStatus(){
		/*
			FCGI_REQUEST_COMPLETE 0
			FCGI_CANT_MPX_CONN    1
			FCGI_OVERLOADED       2
			FCGI_UNKNOWN_ROLE     3
		*/
		return 0;
	}

	private function wrapResponse($conn,$type,$content = null){
		$response = isset($content) ? $content : $conn->getResponse();
		$responselength = strlen($response);
		$paddinglength = 8 - ($responselength % 8 ?: 8);

		$b = new SimpleBinary('');
		$b->setInt8(1);                       // version
		$b->setInt8($type);                   // type
		$b->setInt16($conn->getRequestId());  // requestid
		$b->setInt16($responselength);        // content length
		$b->setInt8($paddinglength);          // padding length
		$b->setInt8(0);                       // reserved
		$b->setString($response);
		$b->setString(str_repeat(' ',$paddinglength));

		return $b->getBinary();
	}

	public function getRecord($bev){
		if($bev->input->length >= 8 && $bev->input->copyout($data,8)){
			if(strlen($data) === 8){
				$b = new SimpleBinary($data);
				$version = $b->getInt8();
				$type = $b->getInt8();
				$requestId = $b->getInt16();
				$contentLength = $b->getInt16();
				$paddingLength = $b->getInt8();
				$reserved = $b->getInt8();
				$length = $contentLength + $paddingLength + 8;
				if($bev->input->length >= $length){

					$header = $bev->input->read(8);

					$return = [
						'type'=>$type,
						'requestId'=>$requestId,
						'content' => $bev->input->read($contentLength)
					];

					$padding = $bev->input->read($paddingLength);
					return $return;
				}
			}
		}
		return false;
	}

	private function getUnknownTypeResponse($type){
		$b = new SimpleBinary('');
		$b->setInt8($type);
		$b->setInt8(0);
		$b->setInt8(0);
		$b->setInt8(0);
		$b->setInt8(0);
		$b->setInt8(0);
		$b->setInt8(0);
		$b->setInt8(0);
		return $b->getBinary();
	}

	public function write($bev,$ctx){

	}

	public function event($bev,$events,$ctx){
		if ($events & EventBufferEvent::ERROR) {
			echo "Error from bufferevent\n";
		}
		if ($events & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) {
			$this->__destruct();
		}
	}

	public function whichPacker($len1,$len2){
		if($len1 <= 127 && $len2 <= 127){
			return 1;
		}
		else if($len1 <= 127){
			return 2;
		}
		else if($len2 <= 127){
			return 3;
		}
		else{
			return 4;
		}
	}

	public function packNameValuePair($name,$value){
		$len1 = strlen($name);
		$len2 = strlen($value);
		$b = new SimpleBinary('');
		switch($this->whichPacker($len1,$len2)){
			case 1:
				$b->setInt8($len1);
				$b->setInt8($len2);
				$b->setString($name);
				$b->setString($value);
				break;
			case 2:
				$len2 = $len2 & 0x7fffffff;
				$b->setInt8($len1);
				$b->setInt32($len2 | 0x80000000);
				$b->setString($name);
				$b->setString(substr($value,0,$len2));
				break;
			case 3:
				$len1 = $len1 & 0x7fffffff;
				$b->setInt32($len1 | 0x80000000);
				$b->setInt8($len2);
				$b->setString(substr($name,0,$len1));
				$b->setString($value);
				break;
			case 4:
				$len1 = $len1 & 0x7fffffff;
				$len2 = $len2 & 0x7fffffff;
				$b->setInt32($len1 | 0x80000000);
				$b->setInt32($len2 | 0x80000000);
				$b->setString(substr($name,0,$len1));
				$b->setString(substr($value,0,$len2));
				break;
		}
		return $b->getBinary();
	}

	public function unpackNameValuePair(&$data){
		if(empty($data)){
			return false;
		}
		$b = new SimpleBinary($data);
		$namelength = $b->getInt8();
		if($namelength >> 7){
			$b->decOffset(1);
			$namelength = $b->getInt32() & 0x7fffffff;
		}
		$vallength = $b->getInt8();
		if($vallength >> 7){
			$b->decOffset(1);
			$vallength = $b->getInt32() & 0x7fffffff;
		}
		$name = $b->getString($namelength);
		$value = $b->getString($vallength);
		$data = substr($data,$namelength+$vallength);
		return [$name,$value];
	}
}
