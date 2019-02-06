<?php

class Github extends Base {

	// Github Token.
	static $github_token = '';

	// Object of Github Client.
	private $client;

	// Github Endpoint.
	static $new_github_endpoint = 'https://api.github.com/';

	static $mapping_collaborators = [];

	public function __construct() {

		parent::__construct();

		self::$github_token = getenv( 'GITHUB_OAUTH_TOKEN' );

		// Create a client object and set token.
		$this->client = new \Github\Client();
		$this->client->authenticate( self::$github_token, '', Github\Client::AUTH_URL_TOKEN );

	}

	/**
	 * Create a repository.
	 *
	 * @param string $name         Name.
	 * @param string $description  Description.
	 * @param string $homepage     Homepage.
	 * @param bool   $public       Visibility
	 * @param string $organisation Organisation Name.
	 *
	 * @return mixed
	 */
	public function create_repo( $name, $description = '', $homepage = '', $public = true, $organisation = '', $issues_enabled = false, $wiki_enabled = false ) {

		if ( $this->_proceed_request() ) {

			// Create a github repository.
			try {

				$repo = $this->client->api( 'repo' )->create( $name, $description, $homepage, $public, $organisation, $issues_enabled, $wiki_enabled );
				return $repo;

			} catch ( Exception $e ) {

				$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
				return false;

			}
		}

	}

	/**
	 * Checks rate limit via github client.
	 *
	 * @return bool
	 */
	private function _proceed_request() {

		$rateLimits = $this->client->api( 'rate_limit' )->getRateLimits();

		if ( ! empty( $rateLimits['rate'] ) ) {

			$remaining = $rateLimits['rate']['remaining'];
			$reset     = $rateLimits['rate']['reset'];

			if ( $remaining < 5 ) {

				$this->_write_log( sprintf( 'Okies.. Going to sleep till %s', date( 'd-m-Y H:i:s', $reset ) ) . PHP_EOL );
				time_sleep_until( $reset );

			} else {

				return true;

			}

		}

	}

	/**
	 * Checks rate limit via github api.
	 *
	 * @return bool
	 */
	public function rate_limit_expired() {

		$rate_limit_request                     = new Requests_Session( 'https://api.github.com/rate_limit' );
		$rate_limit_request->options['timeout'] = $this->request_timeout;

		$response           = $rate_limit_request->get( '' );
		$data               = json_decode( $response->body, true );

		if ( ! empty( $data['rate'] ) ) {

			$remaining = $data['rate']['remaining'];
			$reset     = $data['rate']['reset'];

			if ( $remaining < 5 ) {

				$this->_write_log( sprintf( 'Okies.. Going to sleep till %s', date( 'd-m-Y H:i:s', $reset ) ) . PHP_EOL );
				time_sleep_until( $reset );

			} else {

				return true;

			}

		}

	}

	/**
	 * Create label.
	 *
	 * @param string $label        Label Name.
	 * @param string $organisation Organisation Name.
	 * @param string $reponame     Repo Name.
	 *
	 * @return bool
	 */
	public function create_label( $label, $organisation, $reponame ) {

		if ( $this->_proceed_request() ) {

			// Create a label.
			try {

				$label = $this->client->api('issue')->labels()->create( $organisation, $reponame, [
					'name'  => $label['name'],
					'color' => ltrim( $label['color'], '#' ),
				]);

				return $label;

			} catch ( Exception $e ){
				if ( 422 == $e->getCode() ) {
					$this->_write_log( PHP_EOL . "{$label['name']} - label already exists on GitHub. 🙅" . PHP_EOL );
					return $label;
				} else {
					var_dump( $label );
					var_dump( $e );
					return false;
				}

			}

		}

	}

	/**
	 * Create Issue.
	 *
	 * @param array  $issue      Issue Data.
	 * @param string $repo_name  Repo Name.
	 * @param string $gitlab_repo_name Gitlab repo name with namespace.
	 * @param array  $users      Users Data.
	 * @param array  $milestones Milestones Data.
	 * @param array  $issue_map Gitlab-github issue map array.
	 *
	 * @return array
	 */
	public function create_issue( $issue, $repo_name, $gitlab_repo_name, $users, $milestones, $issue_map ) {

		if ( $this->rate_limit_expired() ) {

			try {

				$this->_write_log( "🚢ing GL #" . $issue['iid'] );
				$comments        = [];
				$gitlab_assignee = '';

				$github                     = new Requests_Session();
				$github->options['timeout'] = $this->request_timeout;

				$headers = [
					'Authorization' => 'token ' . self::$github_token,
					'Accept'        => 'application/vnd.github.golden-comet-preview+json',
				];

				$reporter = ( ! empty( $users[ $issue['author']['username'] ] ) ? '@' . $users[ $issue['author']['username'] ] : $issue['author']['username'] );

				if ( ! empty( $issue['assignees'][0] ) ) {

					$gitlab_assignee = '';
					if ( empty( $users[ $issue['assignees'][0]['username'] ] ) ) {
						$gitlab_assignee = $issue['assignees'][0]['username'];
					} elseif ( get_param_value( '--force-assignee' ) ) {
						$gitlab_assignee = $users[ $issue['assignees'][0]['username'] ];
					}

				}

				$template = sprintf( '> **Migration Note:** This issue was originally created by %1$s %2$s', $reporter, ( ! empty( $gitlab_assignee ) ? 'and Assigned to ' . $gitlab_assignee : '' ) );
				$template = add_end_of_line( $template );
				$notes = $this->_get_comments( $issue, 'issue' );

				foreach ( $notes as $note ) {

					if ( false === $note['system'] ) {

						$note_reporter = ( ! empty( $users[ $note['author']['username'] ] ) ? '@' . $users[ $note['author']['username'] ] : $note['author']['username'] );

						$comment_body     = $this->_filter_description( $note['body'], $users, $gitlab_repo_name );
						$comment_template = sprintf( '> **Migration Note:** This comment was originally added by %1$s', $note_reporter );
						$comment          = add_end_of_line( $comment_template ) . $comment_body;
						$comment          = $this->_filter_comment_description( $comment, $issue_map, count( $issue_map ) );
						$comments[]       = [
							'body'       => $comment,
							'created_at' => $note['created_at'],
						];

					}

				}

				$issue_data = [];

				if ( ! empty( $issue['assignees'][0] ) ) {

					$assignee_id = ( ! empty( $users[ $issue['assignees'][0]['username'] ] ) ? $users[ $issue['assignees'][0]['username'] ] : '' );

					if ( ! empty( $assignee_id ) ) {
						if ( get_param_value( '--force-assignee' ) ) {
							$issue_data['assignee'] = $assignee_id;
							$this->add_user_to_repo( $repo_name, $assignee_id );
						}
					}

				}

				if ( null !== $issue['closed_at'] ) {

					$issue_data['closed_at'] = $issue['closed_at'];

				}

				if ( null !== $issue['updated_at'] ) {

					$issue_data['updated_at'] = $issue['updated_at'];

				}

				if ( 'closed' === $issue['state'] ) {

					$issue_data['closed'] = true;

				}

				if ( ! empty( $issue['milestone'] ) ) {

					$issue_data['milestone'] = $milestones[ $issue['milestone']['iid'] ];

				}

				$description = $this->_filter_description( $issue['description'], $users , $gitlab_repo_name );

				$issue_data['title']      = $issue['title'];
				$issue_data['body']       = $this->_filter_comment_description( $template . $description, $issue_map, count( $issue_map ) );
				$issue_data['created_at'] = $issue['created_at'];
				$labels = [];
				$fix_labels = ['bug','duplicate','enhancement','good first issue','help wanted','invalid','question','wontfix'];
				foreach ( $issue['labels'] as $label ) {
					$label = trim( $label );
					if ( in_array( strtolower( $label ), $fix_labels ) ) {
						$label = strtolower( $label );
					}
					$labels[] = $label;
				}
				$issue_data['labels']     = $labels;
				$data = [
					'issue'    => $issue_data,
					'comments' => $comments,
				];

				$response     = $github->post( self::$new_github_endpoint . 'repos/' . $this->github_organisation . "/$repo_name/import/issues", $headers, json_encode( $data ) );
				$response_arr = json_decode( $response->body, true );

				$issue_status_data = [
					'gitlab_issue_id' => $issue['iid'],
					'response_data'   => $response_arr,
					'reporter'        => $reporter,
					'description'     => $description,
					'issue_data'      => $data,
					'repo_name'       => $repo_name,
				];

				return $issue_status_data;

			} catch ( Exception $e ) {

				$this->_write_log( $e->getMessage() );

			}

		}

	}

	private function add_user_to_repo( $repo_name, $user ) {
		if ( ! empty( self::$mapping_collaborators[ $repo_name ] ) && in_array( $user, self::$mapping_collaborators[ $repo_name ], true ) ) {
			return true;
		}
		$github                     = new Requests_Session();
		$github->options['timeout'] = $this->request_timeout;

		$headers      = [
			'Authorization' => 'token ' . self::$github_token,
			'Accept' => 'application/vnd.github.hellcat-preview+json',
		];
		$data         = [
			'permission' => 'push',
		];
		$response     = $github->put( self::$new_github_endpoint . 'repos/' . $this->github_organisation . "/$repo_name/collaborators/$user", $headers, json_encode( $data ) );

		if ( $response->status_code === 204 || $response->status_code === 201 ) {
			if ( ! empty( self::$mapping_collaborators[ $repo_name ] ) ) {
				self::$mapping_collaborators[ $repo_name ] = [ $user ];
			} else {
				self::$mapping_collaborators[ $repo_name ] [] = $user;
			}

			switch ( $response->status_code ) {
				case 204:
					$this->_write_log( sprintf( '%s added as collaborator in repo: %s', $user, $repo_name ) );
					break;
				case 201:
					$this->_write_log( sprintf( 'Invite sent to %s for joining repo: %s', $user, $repo_name ) );
					break;
			}

			return true;
		}

		return false;
	}

	/**
	 * Get comments.
	 *
	 * @param array  $resource Issue/PR Data.
	 * @param string $type  Type of which comment has to be fetched.
	 *
	 * @return array
	 */
	private function _get_comments( $resource, $type ) {

		if ( 'issue' === $type ) {

			$request_type = 'issues';

		} elseif ( 'pr' === $type ) {

			$request_type = 'merge_requests';
		}

		$request = new Gitlab();
		$gitlab  = $request->init_request();

		$comments   = [];
		$page_index = 1;
		$total      = 0;

		do {

			$response     = $gitlab->get( 'projects/' . $resource['project_id'] . "/$request_type/" . $resource['iid'] . '/notes?per_page=10&page=' . $page_index . '&sort=asc' );
			$response_arr = json_decode( $response->body, true );

			$comments = array_merge( $comments, $response_arr );

			$page_index ++;
			$total += count( $response_arr );

		} while ( count( $response_arr ) > 0 );

		return $comments;

	}

	/**
	 * Get filtered data with user mapping.
	 *
	 * @param string $description Text which has to be filtered.
	 * @param array  $users       Users Data.
	 * @param string $repo_name   Repo Name.
	 *
	 * @return string
	 */
	private function _filter_description( $description, $users, $repo_name ) {

		if ( empty( $description ) ) {

			return '';
		}

		$pattern = '/@[A-Za-z]*[\S]*/';

		preg_match_all( $pattern, $description, $user_matches, PREG_SET_ORDER, 0 );

		if ( ! empty( $user_matches ) ) {

			foreach ( $user_matches as $user_match ) {

				$user_name       = ltrim( $user_match[0], '@' );
				$github_username = ( ! empty( $users[ $user_name ] ) ? '@' . $users[ $user_name ] : $user_name );

				$description = str_replace( $user_match[0], $github_username, $description );

			}

		}

		$description = $this->_process_image( $description, $repo_name );

		return $description;

	}

	/**
	 * Create a Merge Request on Github.
	 *
	 * @param array  $pr        PR Data.
	 * @param string $repo_name Repo Name.
	 * @param array  $users     User Data.
	 * @param array  $milestones Milestone Data.
	 * @param array  $issue_map array of issue ids mapping.
	 * @param string $gitlab_repo_name Gitlab repo name with namespace.
	 *
	 * @return array|string
	 */
	public function create_merge_request( $pr, $repo_name, $users, $milestones, $issue_map, $gitlab_repo_name ) {

		if ( $pr['state'] === 'merged' ) {
			$this->_write_log( 'PR already merged - skipping' );

			return '';
		}


		if ( $this->rate_limit_expired() ) {

			try {

				$comments = [];
				$assignee = '';
				$data     = [];

				$github                     = new Requests_Session();
				$github->options['timeout'] = $this->request_timeout;

				$headers = [
					'Authorization' => 'token ' . self::$github_token,
					'Accept'        => 'application/vnd.github.golden-comet-preview+json',
				];

				$reporter   = ( ! empty( $users[ $pr['author']['username'] ] ) ? '@' . $users[ $pr['author']['username'] ] : $pr['author']['username'] );

				if ( ! empty( $pr['assignee'] ) ) {

					$assignee = ( ! empty( $users[ $pr['assignee']['username'] ] ) ? '@' . $users[ $pr['assignee']['username'] ] : $pr['assignee']['username'] );

				}

				$template = sprintf( '> **Migration Note:** This PR was originally opened by %1$s %2$s', $reporter, ( ! empty( $assignee ) ? 'and assigned to ' . $assignee : '' ) );
				$template = add_end_of_line( $template );
				$notes = $this->_get_comments( $pr, 'pr' );

				foreach ( $notes as $note ) {

					if ( false === $note['system'] && 'DiffNote' !== $note['type'] ) {

						$reporter = ( ! empty( $users[ $note['author']['username'] ] ) ? '@' . $users[ $note['author']['username'] ] : $note['author']['username'] );


						$date = new DateTime( $note['created_at'], new DateTimeZone( 'GMT' ) );
						$date->setTimezone( new DateTimeZone( 'Asia/Kolkata' ) );

						$comment_body     = $this->_filter_description( $note['body'], $users, $gitlab_repo_name );
						$comment_template = sprintf( '> **Migration Note:** This comment was originally added by %1$s on **%2$s**', $reporter, $date->format( 'j M Y \a\t h:ia \I\S\T' ) );
						$comment_template = add_end_of_line( $comment_template );
						$comments[] = [
							'created_at' => $note['created_at'],
							'body'       => $this->_filter_comment_description( $comment_template . $comment_body, $issue_map, count( $issue_map ) ),
						];

					}

				}

				$description = $this->_filter_description( $pr['description'], $users, $gitlab_repo_name );

				$data['title'] = $pr['title'];
				$data['body']  = $this->_filter_comment_description( $template . $description, $issue_map, count( $issue_map ) );
				$data['head']  = $pr['source_branch'];
				$data['base']  = $pr['target_branch'];

				$response     = $github->post( self::$new_github_endpoint . 'repos/' . $this->github_organisation . "/$repo_name/pulls", $headers, json_encode( $data ) );
				$response_arr = json_decode( $response->body, true );

				if( isset( $response_arr['errors'] ) && ! empty( $response_arr['errors'] ) ) {

					$this->_write_log( 'Below errors while adding PR - ' );

					if ( isset( $response_arr['errors'] ) ) {
						foreach ( $response_arr['errors'] as $error ) {
							if ( isset( $error['message'] ) ) {
								$this->_write_log( $error['message'] );
							} else {
								var_dump( $error );
							}
						}
					}

					return '';

				} elseif (isset( $response_arr['message']) && 'Not Found' === $response_arr['message']){
					$this->_write_log( 'PR ref not found - skipping');
					return '';
				} else {

					$this->_write_log( sprintf( 'PR Added %s', $response_arr['url'] ) );


					if ( 'closed' === $pr['state'] ) {

						$this->_close_pr( $response_arr, $repo_name );

					}

					$this->_update_pr_attributes( $pr, $response_arr, $repo_name, $milestones );

					$pr_map = [
						'github_pr_id'       => $response_arr['number'],
						'gitlab_pr_id'       => $pr['iid'],
						'gitlab_pr_comments' => $comments,
					];

					return $pr_map;

				}

			} catch ( Exception $e ) {

				$this->_write_log( $e->getMessage() );

			}

		}

	}

	/**
	 * Close PR.
	 *
	 * @param array  $pr        PR Data.
	 * @param string $repo_name Repo Name.
	 */
	private function _close_pr( $pr, $repo_name ) {

		$pr_id = substr( $pr['url'], -1 );

		$github                     = new Requests_Session();
		$github->options['timeout'] = $this->request_timeout;

		$headers = [
			'Authorization' => 'token ' . self::$github_token,
			'Accept'        => 'application/vnd.github.golden-comet-preview+json',
		];

		$data = [
			'state' => 'closed',
		];

		$github->post( self::$new_github_endpoint . 'repos/' . $this->github_organisation . "/$repo_name/pulls/$pr_id", $headers, json_encode( $data ) );

		$this->_write_log( sprintf( 'Updated PR %s', $pr_id ) );

	}

	/**
	 * Update pr with correct mapping.
	 *
	 * @param array  $issues    Issues array.
	 * @param array  $prs       PR's array.
	 * @param string $repo_name Repo Name.
	 *
	 * @return bool
	 */
	public function _update_prs( $issues, $prs, $repo_name ) {

		$issue_map = array_column( $issues, 'github_issue_id',  'gitlab_issue_id' );
		$pr_map    = array_column( $prs, 'github_pr_id',  'gitlab_pr_id' );

		foreach ( $prs as $pr ) {

			$updated_comments    = $this->_get_filtered_comments( $pr['gitlab_pr_comments'], $issue_map, $pr_map );

			$this->_write_log( sprintf( 'Updating description for pr %s ', $pr['github_pr_id'] ) );

			foreach ( $updated_comments as $comment ) {

				if ( $this->_proceed_request() ) {

					$this->_write_log( sprintf( 'Adding comment for pr %s',  $pr['github_pr_id'] ) );

					// Create a comment on PR.
					try {

						$this->client->api( 'issue' )->comments()->create( $this->github_organisation, $repo_name, abs( $pr['github_pr_id'] ), $comment );

					} catch ( Exception $e ){

						$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
						return false;

					}

				}

			}

		}

	}

	/**
	 * Update PR attributes.
	 *
	 * @param array  $old_pr_data Gitlab PR Data.
	 * @param array  $new_pr_data Github PR Response.
	 * @param string $repo_name   Repo Name.
	 * @param string $milestones  Milestones Data.
	 *
	 * @return bool
	 */
	private function _update_pr_attributes( $old_pr_data, $new_pr_data, $repo_name, $milestones ) {

		$milestone = '';
		$pr_id     = substr( $new_pr_data['url'], -1 );
		$labels    = $old_pr_data['labels'];

		if ( ! empty( $old_pr_data['milestone'] ) ) {

			$milestone = $milestones[ $old_pr_data['milestone']['iid'] ];

		}

		if ( ! empty( $milestone ) ) {

			if ( $this->_proceed_request() ) {

				// Add milestone to a pr.
				try {

					$this->client->api('issue')->update( $this->github_organisation, $repo_name, $pr_id,
						[
							'milestone' => $milestone,
						] );
					$this->_write_log( sprintf( 'Added Milestone For PR %s', $pr_id ) );

				} catch ( Exception $e ){

					$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
					return false;

				}

			}

		}

		foreach ( $labels as $label ) {

			if ( $this->_proceed_request() ) {

				// Add label to a pr.
				try {

					$this->client->api('issue')->labels()->add( $this->github_organisation, $repo_name, $pr_id, $label );
					$this->_write_log( sprintf( 'Added Label For PR %s', $pr_id ) );

				} catch ( Exception $e ){

					$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
					return false;

				}

			}

		}

	}

	/**
	 * Check status of given issue.
	 *
	 * @param array $issue Issue import data.
	 *
	 * @return mixed
	 */
	private function _check_issue_status( $issue ) {

		echo '👀';

		$github                     = new Requests_Session();
		$github->options['timeout'] = $this->request_timeout;

		$headers = [
			'Authorization' => 'token ' . self::$github_token,
			'Accept'        => 'application/vnd.github.golden-comet-preview+json',
		];

		$response     = $github->get( $issue['url'], $headers );
		$response_arr = json_decode( $response->body, true );

		if ( 'imported' === $response_arr['status'] ) {
			return $response_arr;
		} elseif ( 'pending' === $response_arr['status']){
			return false;
		} else {

			$errors = array_column( $response_arr['errors'], 'code', 'field' );

			if ( isset( $errors['assignee'] ) && 'invalid' === $errors['assignee'] ) {

				$this->_write_log( 'Error while assigning user for issue. Please check function _check_issue_status' );

				return [ 'code' => 'assignee_failed', 'data' => '' ];

			} elseif ( isset( $errors['name'] ) && 'invalid' === $errors['name'] && isset( $response_arr['errors'][0]['value'] ) ) {

				return [ 'code' => 'label_failed', 'data' => $response_arr['errors'][0]['value'] ];

			} else {
				var_dump( $response_arr );
				$this->_write_log( 'Some issue on import issue check function _check_issue_status' );
				exit;
			}
		}

	}

	/**
	 * Update issue with correct mapping.
	 *
	 * @param array  $issues    Issues array.
	 * @param array  $prs       PR's array.
	 * @param string $repo_name Repo Name.
	 *
	 * @return bool
	 */
	public function _update_issues( $issues, $prs, $repo_name ) {

		$issue_map = array_column( $issues, 'github_issue_id',  'gitlab_issue_id' );
		$pr_map    = array_column( $prs, 'github_pr_id',  'gitlab_pr_id' );

		foreach ( $issues as $issue ) {

			$issue_data          = $this->get_github_issue_pr_data( $issue['github_issue_id'], $repo_name );
			$updated_comments    = $this->_get_filtered_comments( $issue['gitlab_issue_comments'], $issue_map, $pr_map );
			$updated_description = $this->_filter_comment_description( $issue_data['body'], $issue_map, $pr_map );

			$this->_write_log( sprintf( 'Updating description for issue %s ', $issue['github_issue_id'] ) );

			try {

				// update body description.
				$this->client->api( 'issue' )->update( $this->github_organisation, $repo_name, $issue['github_issue_id'], array( 'body' => $updated_description ) );

			} catch ( Exception $e ){

				$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );

			}

			foreach ( $updated_comments as $comment ) {

				if ( $this->_proceed_request() ) {

					$this->_write_log( sprintf( 'Adding comment for issue %s',  $issue['github_issue_id'] ) );

					// Create a comment on Issue.
					try {

						$this->client->api( 'issue' )->comments()->create( $this->github_organisation, $repo_name, abs( $issue['github_issue_id'] ), $comment );

					} catch ( Exception $e ){

						$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
						return false;

					}

				}

			}

		}

	}

	/**
	 * Get filtered comments for an issue/pr.
	 *
	 * @param array $resource_comments Issue/PR Comments.
	 * @param array $issue_map         Issue Map.
	 * @param array $pr_map            PR Map.
	 *
	 * @return array
	 */
	private function _get_filtered_comments( $resource_comments, $issue_map, $pr_map ) {

		$comments = [];

		foreach ( $resource_comments as $comment ) {

			// get filtered comments with points to new data.
			$comments[] = array(
				'body'       => $this->_filter_comment_description( $comment['body'], $issue_map, $pr_map ),
				'created_at' => $comment['created_at'],
			);

		}

		return $comments;

	}

	/**
	 * Filter given text and map according to new data.
	 *
	 * @param string    $description Text to be filtered.
	 * @param array     $issue_map Issue mapping data.
	 * @param array|int $pr_map PR mapping data or issue count.
	 *
	 * @return mixed
	 */
	private function _filter_comment_description( $description, $issue_map, $pr_map ) {

		$issue_pattern = '/\#[0-9]+/';

		preg_match_all( $issue_pattern, $description, $issue_matches, PREG_SET_ORDER, 0 );

		if ( ! empty( $issue_matches ) ) {

			foreach ( $issue_matches as $issue_match ) {

				$gitlab_issue_id        = ltrim( $issue_match[0], '#' );
				$may_be_github_issue_id = ( ! empty( $issue_map[ $gitlab_issue_id ] ) ? '#' . $issue_map[ $gitlab_issue_id ] : 'Issue No ' . $gitlab_issue_id );

				$description = str_replace( $issue_match[0], $may_be_github_issue_id, $description );

			}

		}

		$pr_pattern = '/\![0-9]+/';

		preg_match_all( $pr_pattern, $description, $pr_matches, PREG_SET_ORDER, 0 );

		if ( ! empty( $pr_matches ) ) {

			foreach ( $pr_matches as $pr_match ) {

				$gitlab_pr_id        = ltrim( $pr_match[0], '!' );

				if ( is_array( $pr_map ) ) {
					$may_be_github_pr_id = ( ! empty( $pr_map[ $gitlab_pr_id ] ) ? '#' . $pr_map[ $gitlab_pr_id ] : 'PR No ' . $gitlab_pr_id );
				} else{
					$may_be_github_pr_id = '#' . ( (int) $gitlab_pr_id + (int)$pr_map );
				}

				$description = str_replace( $pr_match[0], $may_be_github_pr_id, $description );

			}

		}

		return $description;

	}

	/**
	 * Get issue/pr data.
	 *
	 * @param int    $resource_id Issue/PR id.
	 * @param string $repo_name   Repo Name.
	 *
	 * @return bool
	 */
	public function get_github_issue_pr_data( $resource_id, $repo_name ) {

		if ( $this->_proceed_request() ) {

			try {

				// Get issue/pr data.
				return $this->client->api( 'issue' )->show( $this->github_organisation, $repo_name, $resource_id );

			} catch ( Exception $e ){

				$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
				return false;

			}

		}

	}

	/**
	 * Create Milestone.
	 *
	 * @param array $milestone     Milestone Name and data.
	 * @param string $organisation Organisation Name.
	 * @param string $reponame     Repo Name.
	 *
	 * @return bool
	 */
	public function create_milestone( $milestone, $organisation, $reponame ) {

		$data = [];

		if ( $this->_proceed_request() ) {

			// Create a milestone.
			try {

				if ( ! empty( $milestone['due_date'] ) ) {

					$datetime       = new DateTime( $milestone['due_date'] );
					$data['due_on'] = $datetime->format(DateTime::ATOM);

				}

				$data['title']       = $milestone['title'];
				$data['state']       = $milestone['state'];
				$data['description'] = $milestone['description'];

				$github_milestone = $this->client->api('issue')->milestones()->create( $organisation, $reponame, $data );

				$milestone_map = [
					'github_milestone_id' => $github_milestone['number'],
					'gitlab_milestone_id' => $milestone['iid'],
				];

				return $milestone_map;

			} catch ( Exception $e ){

				$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
				return false;

			}

		}

	}

	/**
	 * Updating README.md file with master branch.
	 *
	 * @param string $organisation Github organization name.
	 * @param string $name Github repo name.
	 * @param array  $gists List of gist page links.
	 */
	public function update_readme( $organisation, $name, $gists ) {

		$branch = 'master';
		$path = 'README.md';

		// Create a snippet.
		try {

			$readme = $this->client->api('repo')->contents()->show( $organisation, $name, $path, $branch );

			if ( ! empty( $readme ) && isset( $readme['sha'] ) ) {

				$this->_write_log( PHP_EOL . sprintf( 'Updating README.md with gists links for %s', $name ) . PHP_EOL );

				$content = '';
				if ( isset( $readme['content'] ) && ! empty( $readme['content'] ) ) {
					$content .= base64_decode( $readme['content'] );
				}

				$content .= PHP_EOL . PHP_EOL . '### Snippets' . PHP_EOL . PHP_EOL;
				$content .= implode( PHP_EOL, $gists );

				$committer = array( 'name' => 'rtBot', 'email' => '43742164+rtBot@users.noreply.github.com' );

				$this->client->api( 'repo' )->contents()->update( $organisation, $name, $path, $content, 'Update README with gist links', $readme['sha'], $branch, $committer );

				$this->_write_log( PHP_EOL . sprintf( 'Updated README.md with gists links for %s', $name ) . PHP_EOL );

			} else {

				$this->_write_log( PHP_EOL . '' . PHP_EOL );
			}

		} catch ( Exception $e ){

			$this->_write_log( PHP_EOL . sprintf( 'Looks like README.md file is not found in %s repo, below all are gist links you can update it manually in README.md', $name ) . PHP_EOL );

			$this->_write_log( implode( PHP_EOL, $gists ) . PHP_EOL );
		}
	}

	/**
	 * Create Gist from snippets.
	 *
	 * @param array $gist Snippet data.
	 *
	 * @return bool
	 */
	public function create_gist( $gist ) {

		if ( $this->_proceed_request() ) {

			// Create a snippet.
			try {

				$gist_page = $this->client->api( 'gists' )->create( $gist );

				if ( isset( $gist_page['html_url'] ) ) {
					return $gist_page['html_url'];
				}

				return '';

			} catch ( Exception $e ){

				$this->_write_log( PHP_EOL . $e->getMessage() . PHP_EOL );
				return false;

			}

		}

	}

	/**
	 * Get filtered data with new image url.
	 *
	 * @param string $description   Text which has to be filtered.
	 * @param string $new_repo_name Repo Name.
	 *
	 * @return string
	 */
	private function _process_image( $description, $new_repo_name ) {

		if ( empty( $description ) ) {

			return '';
		}

		$pattern = '/(.*)\[(.*)\]\(\/uploads\/(.*)\)/';

		preg_match_all( $pattern, $description, $image_matches, PREG_SET_ORDER, 0 );

		if ( ! empty( $image_matches ) ) {

			$gitlab = new Gitlab();

			foreach ( $image_matches as $image_match ) {

				$image_name = $image_match[2];
				$prefix     = ( ! empty( $image_match[1] && '!' == $image_match[1] ) ? '!' : '' );
				$file_name  = $image_match[3];
				//$repo_data  = explode( '--', $new_repo_name );
				$repo_group = Repository::$repo_group;
				$repo_name  = Repository::$repo_name;

				$image_ref = sprintf( '%1$s[%2$s](%3$s)', $prefix, $image_name, Gitlab::$gitlab_web_url . $repo_group . "/$repo_name" . '/uploads/' . $file_name );
				$description   = str_replace( $image_match[0], "\n\n" . $image_ref, $description );

			}

		}

		return $description;

	}

	/**
	 * @param array $meta_response_data Contains current Status of issue and other details for importing.
	 *
	 * @return array
	 */
	public function check_status_and_process( $meta_response_data ) {

		$final_map = [];

		foreach ( $meta_response_data as $response_data ) {

			$status_response = false;

			$response_arr = $response_data['response_data'];
			$reporter     = $response_data['reporter'];
			$description  = $response_data['description'];
			$data         = $response_data['issue_data'];
			$repo_name    = $response_data['repo_name'];
			$issue_iid    = $response_data['gitlab_issue_id'];
			$comments     = $data['comments'];
			$this->_write_log( 'Status 👁 #' . $issue_iid . ' - ' );


			$github                     = new Requests_Session();
			$github->options['timeout'] = $this->request_timeout;

			$headers = [
				'Authorization' => 'token ' . self::$github_token,
				'Accept'        => 'application/vnd.github.golden-comet-preview+json',
			];

			if ( 'pending' === $response_arr['status'] ) {

				do {

					$status_response = $this->_check_issue_status( $response_arr );
					if ( is_array( $status_response) && isset( $status_response['code'],$status_response['data']) ) {
						[
							'code' => $status_error_code,
							'data' => $error_data,
						] = $status_response;
						$is_reimporting = false;

						if ( 'assignee_failed' === $status_error_code ) {
							$is_reimporting = true;

							$template = sprintf( '> **Migration Note:** This issue was originally created by %1$s %2$s', $reporter, ( ! empty( $data['issue']['assignee'] ) ? 'and Assigned to ' . $data['issue']['assignee'] : '' ) );
							$template = add_end_of_line( $template );

							$data['issue']['body'] = $template . $description;
							unset( $data['issue']['assignee'] );

						} elseif ( 'label_failed' === $status_error_code ) {
							// Todo handle infinite loop case if label doesn't fall in this case and GH changes it.
							$is_reimporting = true;
							$labels_arr     = $data['issue']['labels'];
							$search_key = array_search( $error_data, $labels_arr );
							if ( false === $search_key ) {
								$this->_write_log( 'Issue with project labels. My boss didn\'t tell me how to handle this case, Check project labels' );
								exit;
							}
							unset( $labels_arr[ $search_key ] );
							$labels_arr = array_values( $labels_arr );

							if ( is_starts_with_upper( $error_data ) ) {
								$labels_arr[] = strtolower( $error_data );
							} else {
								$labels_arr[] = ucfirst( $error_data );
							}
							$data['issue']['labels'] = $labels_arr;
						}

						if ( $is_reimporting ) {
							$response        = $github->post( self::$new_github_endpoint . 'repos/' . $this->github_organisation . "/$repo_name/import/issues", $headers, json_encode( $data ) );
							$response_arr    = json_decode( $response->body, true );
							$status_response = false;
						}
					}

					if ( false === $status_response ) {
						sleep( 2 );
					}

				} while ( false === $status_response );

				if ( 'assignee_failed' !== $status_response ) {

					$split_url = explode( '/', $status_response['issue_url'] ) ;
					$issue_id = end( $split_url );

				} else {
					$issue_id = 0;
				}

			}

			if ( 0 === $issue_id ) {

				printf( 'GL import %1$s %2$s!', $issue_iid, $status_response );

			} else{

				printf( 'GH #%1$s :- %2$s ✅',$issue_id, $status_response['status'] );

			}

			$issue_map = [
				'github_issue_id'       => $issue_id,
				'gitlab_issue_id'       => $issue_iid,
				'gitlab_issue_comments' => $comments,
			];

			$final_map[] = $issue_map;

		}

		return $final_map;

	}

	public function get_all_repo() {
		$params = [
			'type' => 'all',
		];
		return $this->get( self::$new_github_endpoint . 'orgs/' . rawurlencode( $this->github_organisation ) . '/repos', [], $params );
	}

	public function get_all_team() {
		return $this->get( self::$new_github_endpoint . 'orgs/' . rawurlencode( $this->github_organisation ) . '/teams' );
	}

	public function get( $url, $headers = [], $params = [] ) {
		$items = [];
		$page = 1;
		$next = true;
		do {
			if ( $this->rate_limit_expired() ) {
				$github                     = new Requests_Session();
				$github->options['timeout'] = $this->request_timeout;
				$headers = array_merge( [
					'Authorization' => 'token ' . self::$github_token,
				], $headers );
				$response = $github->get( $url . '?page=' . $page, $headers, $params );
				if ( $response->status_code === 200 ) {
					$items = array_merge( $items, json_decode( $response->body, true ) );
					$links = $response->headers->offsetGet( 'link' );
					if ( false !== strpos( $links, "rel=\"next\"" ) ) {
						$next = true;
						$page ++;
					} else {
						$next = false;
					}

				} else {
					$next = false;
				}

			}
		} while ( $next === true );

		return $items;
	}

	public function put( $url, $headers = [], $params = [] ) {
		$github                     = new Requests_Session();
		$github->options['timeout'] = $this->request_timeout;
		$headers = array_merge( [
			'Authorization' => 'token ' . self::$github_token,
		], $headers );

		return $github->put( self::$new_github_endpoint . $url, $headers, $params );
	}

}
