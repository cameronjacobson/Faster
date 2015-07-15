<?php

# Dependency Injected "Request" Service Container

require_once(dirname(__DIR__).'/vendor/autoload.php');

use Faster\Faster;
use Faster\Request;

$request_service = function($content){
	return new Request($content);
};

$f = new Faster('127.0.0.1','9998',$request_service);
$f->loop();

function E($val){
	error_log(var_export($val,true));
}
