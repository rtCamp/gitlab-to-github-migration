<?php

class Base {

	// Github Organisation name.
	public $github_organisation;

	// Request Timeout.
	public $request_timeout = 30;

	public function __construct() {

		$this->github_organisation = getenv( 'GITHUB_ORGANISATION' );
	}

	/**
	 * Method to add a log entry and to output message on screen
	 *
	 * @param string $msg             Message to add to log and to outout on screen
	 * @param int    $msg_type        Message type - 0 for normal line, -1 for error, 1 for success, 2 for warning
	 * @return void
	 */
	public function _write_log( $msg, $msg_type = 0 ) {

		// backward compatibility
		if ( $msg_type === true ) {
			// its an error
			$msg_type = -1;
		} elseif ( $msg_type === false ) {
			// normal message
			$msg_type = 0;
		}

		$msg_type = intval( $msg_type );

		switch ( $msg_type ) {

			case -1:
				\cli\err( $msg );
				break;
			default:
				\cli\line( $msg );
				break;

		}

	}

	/**
	 * Method to log an error message
	 *
	 * @param string $msg Message to add to log and to outout on screen
	 * @return void
	 */
	public function _error( $msg ) {
		$this->_write_log( $msg, -1 );
	}

	/**
	 * Method to return a new instance of \cli\Table.
	 *
	 * @return \cli\Table
	 */
	public function _create_table() {

		return new \cli\Table();
	}

}