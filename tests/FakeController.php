<?php

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\SS_HTTPRequest;
use SilverStripe\Control\SS_HTTPResponse;
use SilverStripe\Control\Controller;

// Fake a current controller. Way harder than it should be
class FakeController extends Controller {

	public function __construct() {
		parent::__construct();

		$session = Injector::inst()->create('SilverStripe\\Control\\Session', isset($_SESSION) ? $_SESSION : array());
		$this->setSession($session);

		$this->pushCurrent();

		$request = new SS_HTTPRequest(
			(isset($_SERVER['X-HTTP-Method-Override']))
				? $_SERVER['X-HTTP-Method-Override']
				: $_SERVER['REQUEST_METHOD'],
			'/'
		);
		$this->setRequest($request);

		$this->setResponse(new SS_HTTPResponse());

		$this->doInit();
	}
}
