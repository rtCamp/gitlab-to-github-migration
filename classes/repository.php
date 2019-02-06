<?php

use League\Csv\Reader;

class Repository extends Base {

	// Temporary folder for cloning and pushing repositories to Github.
	private $temporary_project_dir = 'temporarygitdir';

	static $repo_name;

	static $repo_group;

	// Object of User.
	private $user;

	public function __construct() {

		parent::__construct();

		$this->user = new User();

	}

	/**
	 * Get Project Data and Create Repository.
	 *
	 * @param array $gitlab_path Namespace OR Project_Name.
	 */
	public function _archive_repo( $gitlab_path ) {

		[ 'group' => $group, 'name' => $project_name ] = $gitlab_path; // php 7.1 🚀

		if ( ! empty( $project_name ) ) {

			archive_repo_by_id( rawurlencode( $group . '/' . $project_name ) );

		} else {

			$projects_data = get_projects( $gitlab_path );

			foreach ( $projects_data as $project ) {

				$this->_display_project_data( $project, 'Archiving' );

				archive_repo_by_id( $project['id'] );
			}
		}
	}

	/**
	 * Get Project Data and Create Repository.
	 *
	 * @param array $gitlab_path   Namespace/Repo Name.
	 * @param array $include_values Includes Array.
	 * @param bool $is_public       Repo Visibility.
	 * @param string $gh_repo_name  GitHub repo name.
	 */
	public function _create_single_repo( $gitlab_path, $include_values, $is_public, $gh_repo_name ) {

		if ( 'all' === $include_values || in_array( 'all', $include_values ) ) {

			$migrate_data = [ 'issues', 'wiki', 'pr', 'snippets' ];

		} else {

			$migrate_data = $include_values;

		}

		[ 'group' => $group, 'name' => $project_name ] = $gitlab_path; // php 7.1 🚀

		$projects_data = get_projects( $gitlab_path );

		foreach ( $projects_data as $project ) {
			if ( strtolower( $group ) === strtolower( $project['namespace']['full_path'] ) ) {
				if ( ! empty( get_param_value( '--yes' ) || ask_yes_no( $project['path_with_namespace'] ) ) ) {
					$this->_create_repositories( $project, $migrate_data, $is_public, $gh_repo_name );
				}
			}

		}

	}

	/**
	 * Display Project Data and Call Method to clone push repo.
	 *
	 * @param array $project      Project Data.
	 * @param array $migrate_data Data to be migrated.
	 * @param bool $is_public     Repo Visibility.
	 * @param string $repo_name   Repo name.
	 */
	public function _create_repositories( $project, $migrate_data, $is_public, $repo_name  ) {

		if ( ! empty( $project ) ) {

			$this->_display_project_data( $project );

			// Do not duplicate group name in repo name.
			if ( get_param_value( '--use-repo-name-as-github-repo') || false !== strpos( $project['path'], strtolower( $project['namespace']['name'] ) ) || false !== strpos( $project['path'], $project['namespace']['name'] ) || false !== strpos( $project['name'], strtoupper( $project['namespace']['name'] ) ) ) {
				$gitlab_repo_name = $project['path'];
			} else {
				$gitlab_repo_name = $project['namespace']['name'] . '-' . $project['path'];
			}

			if ( empty( $repo_name ) ) {
				$repo_name = $gitlab_repo_name;
			}

			$this->_clone_push_repo( $repo_name, $project, $migrate_data, $is_public, $gitlab_repo_name );

		}

	}

	/**
	 * Display project data in table format.
	 *
	 * @param array  $project  Project Data.
	 * @param string $activity Activity that are doing.
	 */
	private function _display_project_data( $project, $activity = 'Importing' ) {

		$projecttable = $this->_create_table();

		$projecttable->setHeaders(
			[
				'ID',
				'Name',
				'Visibility',
				'Default Branch',
				'SSH URL'
			]
		);

		$projecttable->addRow(
			[
				$project["id"],
				$project["name"],
				$project["visibility"],
				$project["default_branch"],
				$project["ssh_url_to_repo"],
			]
		);

		$projecttable->sort( 1 );

		$this->_write_log( '' );
		$this->_write_log( sprintf( '%s %s repo From %s group', $activity, $project["path"], $project['namespace']['name'] ) );

		$projecttable->display();

	}

	/**
	 * Get issues from Gitlab and Create on Github Repo.
	 *
	 * @param array  $project    Project Data.
	 * @param string $repo_name  Repo Name.
	 * @param array  $milestones Milestone Data.
	 * @param string $gitlab_repo_name Gitlab repo name with namespace.
	 *
	 * @return array
	 */
	private function _add_issues( $project, $repo_name, $milestones, $gitlab_repo_name ) {

		$this->_write_log( PHP_EOL . sprintf( 'Creating Issue(s) for %s', $repo_name ) );

		$github            = new Github();
		$issues            = $this->_get_issues( $project );
		$issue_map_old     = array_column( $issues, 'iid' );
		$issue_map_temp    = [];
		$issue_status_data = [];

		$count = 1;

		foreach ( $issue_map_old as $id ) {

			$issue_map_temp[ $id ] = $count++;

		}

		// comment function that calls api to avoid hitting rate limit.
		//$users  = $this->user->get_user_map();

		// get user map from csv for creating issues etc.
		$users  = $this->user->get_user_map_from_csv();

		foreach ( $issues as $issue ) {

			// Store response of api and extra details for issue import.
			$issue_status_data[] = $github->create_issue( $issue, $repo_name, $gitlab_repo_name, $users, $milestones, $issue_map_temp );

		}

		// Get final issue map after checking / re-importing issues.
		$issue_map = $github->check_status_and_process( $issue_status_data );

		$this->_write_log( sprintf( 'Total %1$s Issue(s) created for %2$s', count( $issues ), $repo_name ) );

		return $issue_map;

	}

	/**
	 * Get Snippets from Gitlab and Create on Github Repo.
	 *
	 * @param array  $project   Project Data.
	 * @param string $repo_name Repo Name.
	 */
	private function _create_snippets( $project, $repo_name ) {

		$this->_write_log( PHP_EOL . sprintf( 'Creating Snippet(s) for %s', $repo_name ) . PHP_EOL );

		$github   = new Github();
		$snippets = $this->_get_snippets( $project );

		if ( ! empty( $snippets ) ) {

			$created_count = 0;

			$gist = [];

			$gist['public'] = false;

			$gist['description'] = $project['path'];

			foreach ( $snippets as $key => $snippet ) {

				$content = $this->_get_snippet_content( $project['id'], $snippet );

				if ( ! empty( $content ) ) {

					$file_name = $project['path'] . uniqid();
					if ( isset( $snippet['file_name'] ) && ! empty( $snippet['file_name'] ) ) {
						$file_name = $snippet['file_name'];
					}

					$gist['files'][ $file_name ]['content'] = $content;

					$created_count++;
				}
			}

			$gists[] = $github->create_gist( $gist );

			$this->_write_log( PHP_EOL . sprintf( 'Total %d Gist(s) created from %s Snippet(s) for %s', $created_count, count( $snippets ), $repo_name ) . PHP_EOL );

			if ( ! empty( $gists ) ) {
				$github->update_readme( $this->github_organisation, $repo_name, $gists );
			}
		}
	}

	/**
	 * Get all public/private content in one file.
	 * Note: two separate files for private & public snippets.
	 *
	 * @param int $project_id Project id.
	 * @param array $snippet
	 *
	 * @return array|bool|Requests_Response
	 */
	private function _get_snippet_content( $project_id, $snippet ) {

		if ( ! empty( $snippet ) ) {

			$request = new Gitlab();
			$gitlab  = $request->init_request();

			$response     = $gitlab->get( 'projects/' . $project_id . '/snippets/' . $snippet['id'] . '/raw/' );
			$content = $response->body;

			if ( ! empty( $content ) ) {
				return $content;
			}
		}

		return '';
	}

	/**
	 * Get all project snippets.
	 *
	 * @param array $project Project Data.
	 *
	 * @return array
	 */
	private function _get_snippets( $project ) {

		$request = new Gitlab();
		$gitlab  = $request->init_request();

		$snippets   = [];
		$page_index = 1;
		$total      = 0;

		do {

			$response     = $gitlab->get( 'projects/' . $project['id'] . "/snippets?per_page=50&page=$page_index&sort=asc" );
			$response_arr = json_decode( $response->body, true );

			$snippets = array_merge( $snippets, $response_arr );

			$page_index++;
			$total += count( $response_arr );

		} while ( count( $response_arr ) > 0 );

		return $snippets;

	}

	/**
	 * Clone repo from GITLAB and push to GITHUB.
	 *
	 * @param string $repo_name Name of project to be cloned and pushed.
	 * @param array  $project   Project Data.
	 * @param bool   $is_public Repo Privacy.
	 * @param string $gitlab_repo_name Gitlab repo name with namespace.
	 * @param array  $migrate_data Migration data array.
	 */
	private function _clone_push_repo( $repo_name, $project, $migrate_data, $is_public = false, $gitlab_repo_name = '' ) {

		$issue_map = [];
		$pr_map    = [];

		// Create Repository.
		$github = new Github();
		$repo   = $github->create_repo(
			$repo_name,
			str_replace( [ "\n", "\r\n", "\r" ], '', $project['description'] ), // Fix error: Validation Failed: description control characters are not allowed.
			$project['web_url'],
			$is_public,
			$this->github_organisation,
			$project['issues_enabled'],
			$project['wiki_enabled']
		);

		// Dirty way i know, but don't want to refactor code right now.
		self::$repo_name  = $project['path'];
		self::$repo_group = $project['namespace']['full_path'];

		if ( false !== $repo ) {

			$gitlab_clone_url = $project['ssh_url_to_repo'];
			$github_clone_url = $repo['ssh_url'];

			$base_dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . $this->temporary_project_dir;
			exec( "rm -rf $base_dir" );
			mkdir( $base_dir );
			chdir( $base_dir );
			exec("git clone --bare $gitlab_clone_url");
			chdir( $base_dir . DIRECTORY_SEPARATOR . $project['path'] . '.git' );
			exec("git remote add github $github_clone_url");
			exec("git push --mirror github");

			$this->_create_labels( $project, $repo_name );

			$milestone_map = array_column( $this->_create_milestones( $project, $repo_name ), 'github_milestone_id',  'gitlab_milestone_id' );

			if ( true === $project['snippets_enabled'] && in_array( 'snippets', $migrate_data, true ) ) {

				$this->_create_snippets( $project, $repo_name );

			}

			$issue_map = [];

			if ( true === $project['issues_enabled'] && in_array( 'issues', $migrate_data ) ) {

				$issue_map = $this->_add_issues( $project, $repo_name, $milestone_map, $gitlab_repo_name );

			}

			if ( in_array( 'pr', $migrate_data ) ) {

				$pr_map = $this->_create_merge_requests( $project, $repo_name, $milestone_map, $issue_map, $gitlab_repo_name );

			}

			$github->_update_prs( $issue_map, $pr_map, $repo_name );

			// Archive repo.
			archive_repo_by_id( $project['id'] );
		}

	}

	/**
	 * Get issues based on project data.
	 *
	 * @param array $project Project Data.
	 *
	 * @return array
	 */
	private function _get_issues( $project ) {

		if ( ! empty( $project['_links']['issues'] ) ) {

			$issues_link = $project['_links']['issues'];

			$request = new Gitlab();
			$gitlab  = $request->init_request();

			$issues     = [];
			$page_index = 1;
			$total      = 0;

			do {

				$response     = $gitlab->get( "$issues_link?per_page=50&page=$page_index&sort=asc" );
				$response_arr = json_decode( $response->body, true );

				$issues = array_merge( $issues, $response_arr );

				$page_index ++;
				$total += count( $response_arr );

			} while ( count( $response_arr ) > 0 );

			return $issues;

		}

	}

	/**
	 * Get Labels from Gitlab and Create on Github Repo.
	 *
	 * @param array  $project   Project Data.
	 * @param string $repo_name Repo Name.
	 */
	private function _create_labels( $project, $repo_name ) {

		$this->_write_log( PHP_EOL . sprintf( 'Creating Label(s) for %s', $repo_name ) . PHP_EOL );

		$github = new Github();
		$labels = $this->_get_labels( $project );

		foreach ( $labels as $label ) {

			$github->create_label( $label, $this->github_organisation, $repo_name );

		}

		$this->_write_log( PHP_EOL . sprintf( 'Total %1$s Label(s) created for %2$s', count( $labels ), $repo_name ) . PHP_EOL );

	}

	/**
	 * Get all project labels.
	 *
	 * @param array $project Project Data.
	 *
	 * @return array
	 */
	private function _get_labels( $project ) {

		if ( ! empty( $project['_links']['labels'] ) ) {

			$labels_link = $project['_links']['labels'];

			$request = new Gitlab();
			$gitlab  = $request->init_request();

			$labels     = [];
			$page_index = 1;
			$total      = 0;

			do {

				$response     = $gitlab->get( "$labels_link?per_page=50&page=$page_index&sort=asc" );
				$response_arr = json_decode( $response->body, true );

				$labels = array_merge( $labels, $response_arr );

				$page_index ++;
				$total += count( $response_arr );

			} while ( count( $response_arr ) > 0 );

			return $labels;

		}

	}

	/**
	 * Create Merge Requests for given Repo.
	 *
	 * @param array  $project    Project Data.
	 * @param string $repo_name  Repo Name.
	 * @param array  $milestones Milestone Data.
	 * @param array  $issues Issue array.
	 * @param string $gitlab_repo_name Gitlab repo name with namespace.
	 *
	 * @return array
	 */
	private function _create_merge_requests( $project, $repo_name, $milestones, $issues, $gitlab_repo_name ) {

		$this->_write_log( PHP_EOL . sprintf( 'Creating PR(s) for %s', $repo_name ) . PHP_EOL );

		$github    = new Github();
		$pr_map    = [];
		$issue_map = array_column( $issues, 'github_issue_id', 'gitlab_issue_id' );

		// comment function that calls api to avoid hitting rate limit.
		//$users  = $this->user->get_user_map();

		// get user map from csv for creating issues etc.
		$users  = $this->user->get_user_map_from_csv();

		$prs = $this->_get_merge_requests( $project );

		foreach ( $prs as $pr ) {

			$pr_map[] = $github->create_merge_request( $pr, $repo_name, $users, $milestones, $issue_map, $gitlab_repo_name );

		}
		$pr_map = array_filter( $pr_map );

		$this->_write_log( PHP_EOL . sprintf( 'Total %1$s PR(s) created for %2$s', count( $pr_map ), $repo_name ) . PHP_EOL );

		return $pr_map;

	}

	/**
	 * Get List of Project Merge Requests.
	 *
	 * @param array $project Project Data.
	 *
	 * @return array
	 */
	private function _get_merge_requests( $project ) {

		if ( ! empty( $project['_links']['merge_requests'] ) ) {

			$labels_link = $project['_links']['merge_requests'];

			$request = new Gitlab();
			$gitlab  = $request->init_request();

			$prs     = [];
			$page_index = 1;
			$total      = 0;

			do {

				$response     = $gitlab->get( "$labels_link?per_page=50&page=$page_index&sort=asc" );
				$response_arr = json_decode( $response->body, true );

				$prs = array_merge( $prs, $response_arr );

				$page_index ++;
				$total += count( $response_arr );

			} while ( count( $response_arr ) > 0 );

			return $prs;

		}

	}

	/**
	 * Get Milestones from Gitlab and Create on Github Repo.
	 *
	 * @param array  $project   Project Data.
	 * @param string $repo_name Repo Name.
	 *
	 * @return array
	 */
	private function _create_milestones( $project, $repo_name ) {

		$this->_write_log( PHP_EOL . sprintf( 'Creating Milestone(s) for %s', $repo_name ) . PHP_EOL );

		$milestone_map = [];

		$github     = new Github();
		$milestones = $this->_get_milestones( $project );

		foreach ( $milestones as $milestone ) {

			$milestone_map[] = $github->create_milestone( $milestone, $this->github_organisation, $repo_name );

		}

		$this->_write_log( PHP_EOL . sprintf( 'Total %1$s Milestone(s) created for %2$s', count( $milestones ), $repo_name ) . PHP_EOL );

		return $milestone_map;

	}

	/**
	 * Get all project milestones.
	 *
	 * @param array $project Project Data.
	 *
	 * @return array
	 */
	private function _get_milestones( $project ) {

		$request = new Gitlab();
		$gitlab  = $request->init_request();

		$milestones = [];
		$page_index = 1;
		$total      = 0;

		do {

			$response     = $gitlab->get( 'projects/' . $project['id'] . "/milestones?per_page=50&page=$page_index&sort=asc" );
			$response_arr = json_decode( $response->body, true );

			$milestones = array_merge( $milestones, $response_arr );

			$page_index ++;
			$total += count( $response_arr );

		} while ( count( $response_arr ) > 0 );

		return $milestones;

	}

	/**
	 * Method to delete repos given in a file.
	 *
	 * @param $file_path string Path to File Containing List of Repos to be deleted.
	 */
	public function _delete_repos( $file_path ) {

		$repos = $this->_get_repo_list_from_csv( $file_path );

		if ( ! empty( $repos ) ) {

			$this->_write_log( PHP_EOL . 'Searching Repo to be Deleted.' . PHP_EOL );

			foreach ( $repos as $repo ) {

				$this->_delete_repo( $repo );

			}

		}

	}

	/**
	 * Reads CSV File and Returns an array of repos.
	 *
	 * @param $csv_path string Path to File Containing List of Repos to be deleted.
	 *
	 * @return array
	 */
	public function _get_repo_list_from_csv( $csv_path ) {

		$this->_write_log( PHP_EOL . 'Reading from CSV.' . PHP_EOL );

		$map = [];

		try {

			$reader = Reader::createFromPath( $csv_path, 'r');

			$reader->setHeaderOffset(0);

			$records = $reader->getRecords( ['Namespace/Reponame'] );

			foreach ( $records as $offset => $record ) {

				$map[] = $record['Namespace/Reponame'];

			}

			return $map;


		} catch ( Exception $e ) {

			$this->_error( $e->getMessage() );

		}

		return $map;

	}

	/**
	 * Deletes a repo from Gitlab.
	 *
	 * @param $project string Project Data.
	 */
	public function _delete_repo( $project ) {


		$this->_write_log( sprintf( 'Repo \'%1$s\' will be Deleted.',  $project ) . PHP_EOL );

		if ( empty( get_param_value( '--yes' ) ) ) {

			$this->_write_log( "Are you sure you want delete $project?  Type 'yes' to continue: " );

			$handle = fopen( "php://stdin", "r" );
			$line   = fgets( $handle );
			fclose( $handle );
			if ( trim( $line ) != 'yes' ) {

				$this->_write_log( PHP_EOL . 'ABORTING!!!' . PHP_EOL );
				return;

			}
			$this->_write_log( PHP_EOL . 'Alrighty then, continuing...' . PHP_EOL  );
		}

		$request = new Gitlab();
		$gitlab  = $request->init_request();

		$response = $gitlab->delete( 'projects/' . rawurlencode( $project ) );

		if ( true === $response->success ) {

			$this->_write_log( sprintf( 'Repo \'%1$s\' has been Deleted.', $project ) . PHP_EOL );

		} else {

			$this->_write_log( 'Something Went Wrong! '. $response->body );

		}

	}


	/**
	 * Get Wiki Enabled Projects
	 */
	public function get_wiki_enabled_projects() {

		// Get a list of namespaces.
		$groups       = $this->_get_groups();
		$projecttable = $this->_create_table();

		$projecttable->setHeaders(
			[
				'ID',
				'Name',
				'Wiki Count',
			]
		);

		foreach ( $groups as $group ) {

			// Get projects in namespace.
			$projects = $this->_get_group_projects( $group['id'] );

			foreach ( $projects as $project ) {

				if ( true === $project['wiki_enabled'] ) {

					$wikis = $this->_get_project_wikis( $project['id'] );

					if ( count( $wikis ) > 0 ) {

						$this->_write_log( sprintf( 'Adding %s with %d wiki(s) to list', $project['path'], count( $wikis ) ) );

						$projecttable->addRow(
							[
								$project["id"],
								$project["name"],
								count( $wikis ),
							]
						);

					}

				}

			}

		}

		$projecttable->sort( 1 );
		$projecttable->display();

	}

	/**
	 * Get a list of available Groups.
	 *
	 * @return array
	 */
	private function _get_groups() {

		$request = new Gitlab();
		$gitlab  = $request->init_request();

		$groups = [];
		$page_index = 1;

		do {

			$response     = $gitlab->get( "groups?per_page=100&page=$page_index" );
			$response_arr = json_decode( $response->body, true );

			$groups = array_merge( $groups, $response_arr );

			$page_index = get_next_page( $response );

		} while ( ! empty( $page_index ) );

		return $groups;

	}

	/**
	 * Get list of projects in group.
	 *
	 * @param int $group_id Group Id.
	 *
	 * @return array
	 */
	private function _get_group_projects( $group_id ) {

		$request  = new Gitlab();
		$gitlab   = $request->init_request();

		$projects = [];
		$page_index = 1;

		do {

			$response     = $gitlab->get( "groups/$group_id/projects?per_page=100&page=$page_index" );
			$response_arr = json_decode( $response->body, true );

			$projects = array_merge( $projects, $response_arr );

			$page_index = get_next_page( $response );
		} while ( ! empty( $page_index ) );

		do {
			$response     = $gitlab->get( "groups/$group_id/projects?per_page=100&archived=true&page=$page_index" );
			$response_arr = json_decode( $response->body, true );
			$projects = array_merge( $projects, $response_arr );
			$page_index = get_next_page( $response );
		} while ( ! empty( $page_index ) );

		return $projects;

	}

	/**
	 * Get list of wikis in project.
	 *
	 * @param int $project_id Project Id.
	 *
	 * @return array
	 */
	private function _get_project_wikis( $project_id ) {

		$request  = new Gitlab();
		$gitlab   = $request->init_request();

		$response = $gitlab->get( "projects/$project_id/wikis" );
		$wikis    = json_decode( $response->body, true );

		return $wikis;

	}

	/**
	 * Get Snippet Enabled Projects
	 */
	public function get_snippet_enabled_projects() {

		// Get a list of namespaces.
		$groups       = $this->_get_groups();
		$projecttable = $this->_create_table();

		$projecttable->setHeaders(
			[
				'ID',
				'Name',
				'Group',
				'Snippet Count',
			]
		);

		foreach ( $groups as $group ) {

			// Get projects in namespace.
			$projects = $this->_get_group_projects( $group['id'] );

			foreach ( $projects as $project ) {

				if ( true === $project['snippets_enabled'] ) {

					$snippets = $this->_get_project_snippets( $project['id'] );

					if ( count( $snippets ) > 0 ) {

						$this->_write_log( sprintf( 'Adding %s with %d snippets to list', $project['path'], count( $snippets ) ) );

						$projecttable->addRow(
							[
								$project['id'],
								$project['path'],
								$project['namespace']['full_path'],
								count( $snippets ),
							]
						);

					}

				}

			}

		}

		$projecttable->sort( 1 );
		$projecttable->display();

	}

	public function get_all_users() {
		$this->_write_log( 'Getting all users.' );
		$request  = new Gitlab();
		$gitlab   = $request->init_request();

		$users = [];
		$page = 1;
		do {
			$response     = $gitlab->get( 'users?per_page=100&page=' . $page );
			$response_arr = json_decode( $response->body, true );
			$users        = array_merge( $users, $response_arr );
			$page         = get_next_page( $response );
		} while ( ! empty( $page ) );

		return $users;
	}

	public function migrate_all_snippets() {
		$users = $this->get_all_users();
		$ids = array_column( $users, 'id');
		$user_snippets = [];
		$request  = new Gitlab();
		$gitlab   = $request->init_request();
		$this->_write_log( "Getting all snippets from users." );
		foreach ( $ids as $user_id ) {
			$response = $gitlab->get( 'snippets?per_page=100&sudo=' . $user_id );
			$response_arr = json_decode( $response->body, true );
			$user_snippets = array_merge( $user_snippets, $response_arr );
		}
		if ( ! empty( $user_snippets ) ) {
			$snippet_repo_url = getenv( 'SNIPPET_REPO_GIT_URL' );
			if ( empty( $snippet_repo_url ) ) {
				$this->_write_log( 'Please set SNIPPET_REPO_GIT_URL in env to migrate snippets', 1 );
				exit;
			}
			$base_dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . $this->temporary_project_dir;
			exec( "rm -rf $base_dir" );
			mkdir( $base_dir );
			chdir( $base_dir );
			exec( "git clone $snippet_repo_url snippets" ); // Clone dir
			chdir( 'snippets');
			$pwd = getcwd();
			$file_resource = fopen('README.md','a');
		}

		$project_snippets = $this->get_project_snippets();

		$all_snippets = array_merge( $project_snippets, $user_snippets );

		$unique_snippets = [];
		foreach ( $all_snippets as $s ) {
			$unique_snippets[ $s['id'] ] = $s;
		}
		unset( $all_snippets, $project_snippets, $user_snippets );
		foreach ( $unique_snippets as $snippet ) {
			if ( ! isset( $snippet['raw_url'] ) ) {
				$snippet['raw_url'] = $snippet['web_url'] . "/raw";
			}
			$parse_url = parse_url($snippet['raw_url']);
			$split_url = explode( '/', ltrim( $parse_url['path'], '/' ) );
			if ( 5 !== count( $split_url ) ) {
				// User snippets
				$dir_path = $snippet['author']['username'];
			} else {
				// Project snippets
				// Find path
				$dir_path = $split_url[0]. DIRECTORY_SEPARATOR . $split_url[1];
			}
			echo $snippet['raw_url'] . ' -- ' . $dir_path . PHP_EOL; // Todo remove this.
			// Add snippet to given path.
			if ( ! file_exists( $dir_path ) ) {
				mkdir( $dir_path, 0777, true );
			}
			chdir( $dir_path );
			// Download snippet to given path.
			$response = $gitlab->get( $snippet['raw_url'] );
			$content = $response->body;
			$file_name = ( ! empty( $snippet['file_name'] ) ) ? $snippet['file_name'] : 'snippet-' . $snippet['id'].'.txt';
			file_put_contents( $file_name, $content );
			$markdown_content = "# {$snippet['title']}

{$snippet['description']}
";
			file_put_contents( $file_name . '.md', $markdown_content );
			chdir( $pwd );
			$snippet_path = $dir_path . DIRECTORY_SEPARATOR . $file_name;
			// Update readme
			$snippet_relative_url = rawurlencode( $snippet_path );
			$readme_string = "| {$snippet['title']} | [$snippet_path]($snippet_relative_url) | {$snippet['web_url']} |" . PHP_EOL;
			fwrite( $file_resource, $readme_string );
			exec( "git add .");
			exec( "git commit -m 'Add snippet of $dir_path - {$snippet['web_url']}'"); // Commit
		}
		if ( ! empty( $unique_snippets ) ) {
			fclose( $file_resource );
			if ( empty( get_param_value( '--dry-run' ) ) ) {
				exec( 'git push origin master' );
			}
		}
	}

	/**
	 * Get list of snippets in project.
	 *
	 * @param int $project_id Project Id.
	 *
	 * @return array
	 */
	private function _get_project_snippets( $project_id ) {

		$request  = new Gitlab();
		$gitlab   = $request->init_request();

		$snippets   = [];
		$page_index = 1;

		do {

			$response     = $gitlab->get( "projects/$project_id/snippets?per_page=100&page=$page_index" );
			$response_arr = json_decode( $response->body, true );

			$snippets = array_merge( $snippets, $response_arr );

			$page_index = get_next_page( $response );

		} while ( ! empty( $page_index ) );

		return $snippets;

	}

	public function get_project_snippets() {
		// Get a list of namespaces.
		$groups = $this->_get_groups();
		$snippets_list = [];
		foreach ( $groups as $group ) {

			// Get projects in namespace.
			$projects = $this->_get_group_projects( $group['id'] );

			foreach ( $projects as $project ) {

				if ( true === $project['snippets_enabled'] ) {
					$this->_write_log( $project['path'] );
					$snippets = $this->_get_project_snippets( $project['id'] );
					$snippets_list = array_merge( $snippets_list, $snippets );
				}
			}
		}
		return $snippets_list;
	}

}
