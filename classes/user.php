<?php

use League\Csv\CannotInsertRecord;
use League\Csv\Writer;
use League\Csv\Reader;


class User extends Base {

	public function __construct() {

		parent::__construct();

	}

	/**
	 * Create Repositories.
	 */
	public function _get_users() {

		$request = new Gitlab();
		$gitlab  = $request->init_request();

		$users      = [];
		$page_index = 1;
		$total      = 0;

		do {

			$response     = $gitlab->get( "users?per_page=50&page=$page_index" );
			$response_arr = json_decode( $response->body, true );

			$users = array_merge( $users, $response_arr );

			$page_index ++;
			$total += count( $response_arr );

		} while ( count( $response_arr ) > 0 );

		return $users;

	}

	public function csv_generate() {

		$csv_path = dirname( __DIR__ ) . '/all-users.csv';

		try {
			$writer = Writer::createFromPath( $csv_path, 'w+' );
			$users  = $this->_get_users();

			$writer->insertOne( [ 'Name','Username', 'Email', 'State', 'External', 'is_admin' ] );
			foreach ( $users as  $user ){
				$writer->insertOne( [ $user['name'], $user['username'], $user['email'], $user['state'], $user['external'], $user['is_admin'] ] );
			}
		} catch ( CannotInsertRecord $e ) {
			$e->getRecords();
		}
	}

	/**
	 * Returns mapping of gitlab = github username, in case mapping is not provided it will return username.
	 */
	public function get_user_map() {

		$this->_write_log( PHP_EOL . 'Mapping Users' . PHP_EOL );

		$map   = []; // gitlab.userid => github.userid or gitlab user normal name in case of no mapping.
		$users = $this->_get_users();

		foreach ( $users as $user ) {

			$map[ $user['username'] ] = '';

			if ( ! empty( $user['identities'] ) ) {

				$key = array_search( 'github', array_column( $user['identities'], 'provider' ) );

				if ( false === $key ) {

					$this->_error( 'GitHub integration pending for: ' . $user['email'] . PHP_EOL );

				} else {

					$map[ $user['username'] ] = '';
					$github = new Github();

					if ( $github->rate_limit_expired() ) {

						$github_key = $user['identities'][ $key ]['extern_uid'];

						$user_details_github_request                      = new Requests_Session( 'https://api.github.com/user/' . intval( $github_key ) );
						$user_details_github_request->options['timeout']  = $this->request_timeout;

						$response                    = $user_details_github_request->get( '' );
						$data                        = json_decode( $response->body, true );

						if ( isset( $data['login'] ) ) {

							$map[ $user['username'] ] = $data['login'];

						} else {

							$map[ $user['username'] ] = '';

						}

					}

				}

			}
		}

		return $map;

	}

	/**
	 * Creates a csv for mapped users.
	 *
	 * @return array
	 *
	 */
	public function csv_generate_mapped_users() {

		$this->_write_log( PHP_EOL . 'Creating CSV for Mapped Users' . PHP_EOL );

		$csv_path = dirname( __DIR__ ) . '/all-mapped-users.csv';

		try {

			$writer = Writer::createFromPath( $csv_path, 'w+' );
			$users  = $this->get_user_map();

			$writer->insertOne( [ 'Gitlab Username','Github Username' ] );

			foreach ( $users as  $key => $value ){

				$writer->insertOne( [ $key, $value ] );

			}

		} catch ( CannotInsertRecord $e ) {

			$e->getRecords();

		}
	}

	public function get_user_map_from_csv() {

		$map = [];

		$csv_path = dirname( __DIR__ ) . '/all-mapped-users.csv';
		$reader   = Reader::createFromPath( $csv_path, 'r');

		$reader->setHeaderOffset(0);

		$records = $reader->getRecords( ['gitlab_username', 'github_username'] );

		foreach ($records as $offset => $record) {

			$map[ $record['gitlab_username'] ] = $record['github_username'];

		}

		return $map;

	}

}
