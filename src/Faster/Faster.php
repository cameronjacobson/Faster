<?php

namespace Faster;

use \EventBase;
use \EventBuffer;
use \EventUtil;
use \Event;
use \EventListener;
use \EventHttp;
use \EventHttpRequest;
use \Faster\Request;
use \Faster\RequestInterface;
use \SimpleBinary\SimpleBinary;
use \ReflectionClass;

class Faster
{
	public function __construct($addr, $port, callable $request = null){
		$this->base = new EventBase();
		$this->addr = $addr;
		$this->port = $port;

		if(empty($request)){
			$this->request_service = function($content){
				return new Request($content);
			};
		}
		else{
			$binary = new SimpleBinary('');
			$binary->setInt16(1); // type
			$binary->setInt8(0);  // flags
			$binary->setInt8(0);
			$binary->setInt32(0);

			$mock_request = array(
				'type'=>1,
				'requestId'=>99,
				'content'=>$binary->getBinary()
			);
			$tmp_request_obj = $request($mock_request);
			$reflection_class = new ReflectionClass($tmp_request_obj);
			if(!$reflection_class->implementsInterface('\Faster\RequestInterface')){
				die('3rd parameter does not implement RequestInterface'.PHP_EOL);
			}
			$this->request_service = $request;
		}

		$this->listener = new EventListener($this->base,
			array($this, "callback"), $this->base,
			EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE, -1,
			$this->addr.':'.$this->port);
		$this->listener->setErrorCallback(array($this, "error"));
	}

	public function callback($listener,$fd,$address,$ctx){
		$base = $this->base;
		new \Faster\Connection($base, $fd, $this->request_service);
	}

	public function error($listener, $ctx) {
		$base = $this->base;

		fprintf(STDERR, "Got an error %d (%s) on the listener. "
			."Shutting down.\n",
			EventUtil::getLastSocketErrno(),
			EventUtil::getLastSocketError());

		$base->exit(NULL);
	}

	private function dispatch(){
		$this->base->dispatch();
	}

	public function loop(){
		$this->base->loop();
	}

	public static function E($val){
		error_log(var_export(json_encode($val,JSON_PRETTY_PRINT),true));
	}

}
