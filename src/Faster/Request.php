<?php

namespace Faster;

use \EventBase;
use \EventBuffer;
use \EventUtil;
use \Event;
use \EventListener;
use \EventHttp;
use \EventHttpRequest;
use \SimpleBinary\SimpleBinary;

class Request implements RequestInterface
{
	const FCGI_RESPONDER = 1;
	const FCGI_AUTHORIZER = 2;
	const FCGI_FILTER = 3;

	const FCGI_KEEP_CONN = 1;

	private $flags;
	private $role;

	public function __construct($record){
		$this->type = $record['type'];
		$this->requestid = $record['requestId'];
		$this->processBeginRequestContent($record['content']);

		$this->paramsReady = $this->stdinReady = $this->dataReady = false;
		$this->params = $this->stdin = $this->data = '';
	}

	public function __destruct(){}

	/**
	 *  @desc The application sets the appStatus component to the status code
	 *  that the CGI program would have returned via the exit system call
	 *
	 *  @return unsigned 32-bit integer
	 */
	public function getAppStatus(){
		return 0;
	}

	public function getRequestId(){
		return $this->requestid;
	}

	public function appendParams($content){
		if(empty($content)){
			$this->paramsReady = true;
		}
		else{
			$this->params .= $content;
		}
	}

	public function appendStdin($content){
		if(empty($content)){
			$this->stdinReady = true;
		}
		else{
			$this->stdin .= $content;
		}
		
	}

	public function appendData($content){
		if(empty($content)){
			$this->dataReady = true;
		}
		else{
			$this->data .= $content;
		}
	}

	private function processBeginRequestContent($content){
		$b = new SimpleBinary($content);
		$this->role = $b->getInt16();
		$this->flags = $b->getInt8();
	}

	public function processRequest(){
		$rand=  rand(1,100);
		$this->response =<<<RESPONSE
Content-Type: text/html\r\n\r\nHello World {$rand}\n
RESPONSE;
	}

	public function getResponse(){
		return $this->response;
	}

	public function isError(){
		return false;
	}

	public function keepConnection(){
		return $this->flags & self::FCGI_KEEP_CONN;
	}

	public function readyToProcess(){
		switch($this->role){
			case self::FCGI_RESPONDER:
				return $this->paramsReady && $this->stdinReady;
				break;
			case self::FCGI_AUTHORIZER:
				return $this->paramsReady;
				break;
			case self::FCGI_FILTER:
				return $this->paramsReady && $this->stdinReady && $this->dataReady;
				break;
		}
	}

}
