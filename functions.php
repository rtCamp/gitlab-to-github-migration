<?php

function array_find( $needle, $haystack ) {

	foreach ( $haystack as $item ) {

		if ( false !== strpos( $item, $needle ) ) {

			return $item;

		}

	}

	return false;
}

/**
 * Archive repo.
 *
 * @param int/string $project_id
 */
function archive_repo_by_id( $project_id ) {

	// Archive repo.
	$request = new Gitlab();
	$gitlab = $request->init_request();
	$response = $gitlab->post( 'projects/' . $project_id . '/archive' );
	$response_arr = json_decode( $response->body, true );

	$archived = ( ! empty( $response_arr['archived'] ) ? ( bool )$response_arr['archived'] : false );

	$Base = new Base();

	if ( $archived ) {
		$Base->_write_log( PHP_EOL . 'Project archived! ✅' );
	} else {
		$Base->_write_log( PHP_EOL . 'Something wrong with archive' );
	}
}

function list_repo_by_group() {

	$request       = new Gitlab();
	$map_repo_proj = [];
	$nextpage = 1;

	$type = get_param_value( '--export' );

	do {
		$gitlab = $request->init_request();

		$data = $gitlab->get( 'projects?page=' . $nextpage );

		$response_arr = json_decode( $data->body, true );

		foreach ( $response_arr as $k => $value ) {
			$split = explode( '/', $value['path_with_namespace'] );
			if ( $value['archived'] ) {
				continue;
			}
			$group_name = $split[0];
			unset( $split[0] );
			$split = array_values( $split );
			$repo  = join( '/', $split );
			if ( isset( $map_repo_proj[ $group_name ] ) ) {
				$map_repo_proj[ $group_name ][] = $repo;
			} else {
				$map_repo_proj[ $group_name ] = [ $repo ];
			}
		}
		$nextpage = get_next_page( $data );
	} while ( ! empty( $nextpage ) );

	array_multisort( array_map( 'count', $map_repo_proj ), SORT_DESC, $map_repo_proj );

	if ( $type === 'json' ) {
		$map_json    = json_encode( $map_repo_proj );
		$file_handle = fopen( 'gitlab-projects.json', 'w' );
		fwrite( $file_handle, $map_json );
		fclose( $file_handle );
	}
	$count = array_sum( array_map( 'count', $map_repo_proj ) );
	echo "Active projects:" . $count . PHP_EOL . PHP_EOL;
	echo "Check gitlab-projects.json or gitlab-projects.csv for project and group mapping" . PHP_EOL;

	if ( empty( $type ) ) {
		$base  = new Base();
		$table = $base->_create_table();
		$table->setHeaders( [ 'Group', 'Project Name' ] );
	}

	if ( $type === 'csv' ) {
		$file_handle = fopen( 'gitlab-projects.csv', 'w' );
		$csv_string  = 'Group,Project Name' . PHP_EOL;
	}

	foreach ( $map_repo_proj as $group => $projects ) {
		foreach ( $projects as $project ) {
			if ( $type === 'csv' ) {
				$csv_string .= $group . ',' . $project . PHP_EOL;
			} elseif ( empty( $type ) ) {
				$table->addRow( [ $group, $project ] );
			}
		}
	}

	if ( empty( $type ) ) {
		$table->display();
	}

	if ( $type === 'csv' ) {
		fwrite( $file_handle, $csv_string );
		fclose( $file_handle );
	}
}

function add_end_of_line( $msg ) {
	return $msg . PHP_EOL . PHP_EOL;
}

function get_param_value( $key ) {
	global $argv;
	$val = array_find( $key, $argv );
	if ( ! empty( $val ) ) {
		$split = explode( '=', $val );
		if ( isset( $split[1] ) ) {
			return $split[1];
		} else {
			return true; // for key exists;
		}
	}

	return '';
}

function is_starts_with_upper($str) {
	$chr = mb_substr ($str, 0, 1, "UTF-8");
	return mb_strtolower($chr, "UTF-8") != $chr;
}

function get_next_page( Requests_Response $response ) {
	$next_page = $response->headers->getValues( 'x-next-page' );

	return is_array( $next_page ) ? $next_page[0] : null;
}

function ask_yes_no( $project_name ) {
	$ask = "Do you want to migrate $project_name to GitHub? y/n";
	try {
		$data = \cli\prompt( $ask );
		if ( empty( $data ) || strtolower( $data ) === 'n' || strtolower( $data ) === 'no' ) {
			return false;
		}

		return true;
	} catch ( Exception $e ) {
		$Base = new Base();
		$Base->_write_log( PHP_EOL . 'Error while getting input from user.' . PHP_EOL . $e->getMessage() . PHP_EOL );
	}
}

function get_projects( array $gitlab_path ) {
	[ 'group' => $group, 'name' => $project_name ] = $gitlab_path;
	if ( empty( $group ) && empty( $project_name ) ) {
		echo 'Please specify either group or group and project.';
		exit;
	}

	$request = new Gitlab();
	$gitlab  = $request->init_request();
	if ( empty( $project_name ) ) {
		$url = 'groups/' . urlencode( $group ) . '/projects';
	} else {
		$url = "projects?search=$project_name";
	}

	$projects_data = [];
	$next_page     = 1;
	do {
		if ( false !== strpos( $url, '?' ) ) {
			$page_url = $url . '&page=' . $next_page;
		} else {
			$page_url = $url . '?page=' . $next_page;
		}
		$response     = $gitlab->get( $page_url );
		$response_arr = json_decode( $response->body, true );

		if ( ! empty( $response_arr ) && is_array( $response_arr ) ) {

			if ( isset( $response_arr['message'] ) ) {

				$Base = new Base();
				$Base->_write_log( PHP_EOL . $response_arr['message'] . PHP_EOL );

			} else {
				$projects_data = array_merge( $projects_data, $response_arr );
			}
		}
		$next_page = get_next_page( $response );
	} while ( ! empty( $next_page ) );

	return $projects_data;
}
