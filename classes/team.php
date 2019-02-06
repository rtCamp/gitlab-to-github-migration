<?php

/**
 * GitHub Team functions
 *
 * Class Team
 */
class Team extends Base {

	protected $keyword;
	protected $team_slug;

	/**
	 * Team constructor.
	 */
	public function __construct() {
		parent::__construct();
		$team    = get_param_value( '--team' );
		$keyword = get_param_value( '--keyword' );
		if ( $team && $keyword ) {
			$this->team_slug = $team;
			$this->keyword   = $keyword;
		} else {
			$this->_error( "Please set pass --team={team_slug} and --keyword={keyword_slug}" );
			exit(1);
		}
	}

	public function add_team_to_repos() {
		$gh = new Github();
		$repos = $gh->get_all_repo();
		$keyword = $this->keyword;
		$filtered_repo = array_filter( $repos, function ( $repo ) use ( $keyword ) {
			return false !== strpos( strtolower( $repo['name'] ), strtolower( $keyword ) );
		} );
		$teams = $gh->get_all_team();
		$team_slug = $this->team_slug;
		$filtered_team = array_filter( $teams, function ( $team ) use ( $team_slug ) {
			return false !== strpos( strtolower( $team['slug'] ), strtolower( $team_slug ) );
		} );
		if ( count( $filtered_team ) !== 1 ) {
			$this->_error( "More than 1 or zero team found. Check below:" );
			var_dump( $filtered_team );
			exit( 1 );
		}
		$team = array_values( $filtered_team )[0];
		foreach ( $filtered_repo as $repo ) {
			$url      = sprintf( 'teams/%u/repos/%s/%s', $team['id'], $this->github_organisation, $repo['name'] );
			$response = $gh->put( $url, [], json_encode( [ 'permission' => 'push' ] ) );
			if ( $response->status_code === 204 ) {
				$this->_write_log( sprintf( "Group: %s added to repo - %s", $team['slug'], $repo['name'] ) );
			} else {
				$this->_error( sprintf( "Something went wrong while adding - Group: %s added to repo - %s", $team['slug'], $repo['name'] ) );
				var_dump( $response );
			}
		}
	}
}
