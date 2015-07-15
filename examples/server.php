<?php

# Uses default / sample Dependency Injected "Request" Container
#  by not passing 3rd argument to constructor

require_once(dirname(__DIR__).'/vendor/autoload.php');

use Faster\Faster;

$f = new Faster('127.0.0.1','9998');
$f->loop();

function E($val){
	error_log(var_export($val,true));
}
