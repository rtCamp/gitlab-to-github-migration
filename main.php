<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

$base = new Base();

// ex: php main.php --migrate-repo --gitlab-path=test-github-import/test-repo-1 --includes=all
global $argv;

if ( in_array( '--migrate-repo', $argv ) ) {

	$migrate = new Repository();
	$public  = false;

	$gitlab_group = array_find( '--gitlab-group=', $argv );

	if ( false !== $gitlab_group ) {
		$gitlab_path = [];
		$gitlab_path['group'] = get_param_value( '--gitlab-group=');

		$include_args = array_find( '--includes=', $argv );

		if ( false !== $include_args ) {

			$include_info   = explode( '=', $include_args );
			$include_values = explode( ',', $include_info[1] );

		} else {

			$include_values = 'all';

		}

		if ( in_array( '--public', $argv ) ) {

			$public = true;

		}
		$gitlab_path['name'] = get_param_value( '--gitlab-project-name=' );

		$repo_name = get_param_value( '--github-name=' );

		$migrate->_create_single_repo( $gitlab_path, $include_values, $public, $repo_name );

	}

} elseif ( in_array( '--user', $argv ) && in_array( '--create-csv', $argv ) ) {

	// Generates 'all-users.csv' with data of all Users in Gitlab. Admin Token should be used for getting all users.
	$user = new User();
	$user->csv_generate();

} elseif ( in_array( '--user', $argv ) && in_array( '--create-mapped-csv', $argv ) ) {

	/**
	 * Generates 'all-mapped-users.csv' with a mapping of Github and Gitlab Username of users who connected their
	 * Github account using Social sign-in in profile 'Account'.
	 */
	$user = new User();
	$user->csv_generate_mapped_users();

} elseif ( in_array( '--delete', $argv ) ) {

	$repo_csv_path = array_find( '--path=', $argv );

	$file_info  = explode( '=', $repo_csv_path );
	$repo_names = $file_info[1];

	$repo = new Repository();
	$repo->_delete_repos( $repo_names );

} elseif ( in_array( '--list-repo', $argv ) ) {
	list_repo_by_group();
} elseif ( in_array( '--get-wiki-info', $argv ) ) {
	$repo = new Repository();
	$repo->get_wiki_enabled_projects();
} elseif ( in_array( '--get-snippet-info', $argv ) ) {
	$repo = new Repository();
	$repo->get_snippet_enabled_projects();
} elseif ( get_param_value( '--migrate-snippets' ) ) {
	$repo = new Repository();
	$repo->migrate_all_snippets();
} elseif (get_param_value( '--add-team' )){
	$team = new Team();
	$team->add_team_to_repos();

}  elseif ( in_array( '--archive', $argv ) ) {

	$gitlab_path = [];

	$group = get_param_value( '--gitlab-group=' );
	$name  = get_param_value( '--gitlab-project-name=' );

	if ( ! empty( $group ) ) {

		$gitlab_path['group'] = get_param_value( '--gitlab-group=' );
		$gitlab_path['name']  = get_param_value( '--gitlab-project-name=' );

		$migrate = new Repository();
		$migrate->_archive_repo( $gitlab_path );

	} else {

		$base->_write_log( PHP_EOL . 'Parameter missing pass [--gitlab-group=your_group_namespace]' . PHP_EOL );
	}

} else {

	$base->_write_log( PHP_EOL . 'No Parameters Passed to Script!!' . PHP_EOL );
	$base->_write_log( 'Check Following Commands for Help.' . PHP_EOL );

	$projecttable = $base->_create_table();

	$projecttable->setHeaders(
		[
			'Feature',
			'Command',
		]
	);

	$commands = [
		'Migrate Repo'                        => 'php main.php --migrate-repo --gitlab-group=test-github-import --gitlab-project-name=test-repo-1 --includes=all --force-assignee --github-name=test-repo-1 --yes',
		'Create User CSV'                     => 'php main.php --user --create-csv',
		'Create Mapped User CSV'              => 'php main.php --user --create-mapped-csv',
		'Delete Repos Given in CSV'           => 'php main.php --delete --path=path/to/file.csv',
		'Migrate all snippets'                => 'php main.php --migrate-snippets',
		'List Repos by Group'                 => 'php main.php --list-repo',
		'Export Repos by Group'               => 'php main.php --list-repo --export=csv/json',
		'List Wiki Enabled Projects'          => 'php main.php --get-wiki-info',
		'List Snippet Enabled Projects'       => 'php main.php --get-snippet-info',
		'Add team to groups'                  => 'php main.php --add-team --team=rtMedia --keyword=rtmedia',
		'Archive repos by namespace or group' => 'php main.php --archive --gitlab-group=test-github-import',
		'Get Gitlab statistics'               => 'php stats.php',
		'Export Gitlab statistics to csv'     => 'php stats.php > stats.csv'
	];

	foreach ( $commands as $info => $command ) {
		$projecttable->addRow( [
			$info,
			$command,
		] );
	}

	$projecttable->sort( 1 );
	$projecttable->display();

}
