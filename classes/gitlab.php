<?php

class Gitlab extends Base {

	// Gitlab API URL.
	static $gitlab_api = '';

	// Gitlab Token.
	static $gitlab_token = '';

	// Gitlab Web URL.
	static $gitlab_web_url = '';

	public function __construct() {

		parent::__construct();

		self::$gitlab_api     = getenv( 'GITLAB_API_ENDPOINT' );
		self::$gitlab_token   = getenv( 'GITLAB_TOKEN' );
		self::$gitlab_web_url = getenv( 'GITLAB_WEB_URL' );

	}

	// Initialize a request and set token in header.
	public function init_request() {

		$gitlab                           = new Requests_Session( self::$gitlab_api );
		$gitlab->options['timeout']       = $this->request_timeout;
		$gitlab->headers['Private-Token'] = self::$gitlab_token;

		return $gitlab;

	}

}