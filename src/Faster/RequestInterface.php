<?php

namespace Faster;

interface RequestInterface
{
	public function __construct($record);
	public function isError();
	public function keepConnection();
	public function processRequest();
	public function getAppStatus();
	public function getRequestId();
	public function appendParams($content);
	public function appendStdin($content);
	public function appendData($content);
	public function getResponse();
	public function readyToProcess();
}
