<?php

require 'vendor/autoload.php';

//init
$table = new \cli\Table();

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$gitlab = new Requests_Session( getenv( 'GITLAB_API_ENDPOINT' ) );
$gitlab->headers['Private-Token'] = getenv('GITLAB_TOKEN');

//hold all projects
$projects = array();
$page_index = 1;
$total = 0;
do {
    $response = $gitlab->get("projects?per_page=100&page=$page_index&statistics=yes");
    $response_arr = json_decode($response->body, true);
    $projects = array_merge($projects , $response_arr);
    $page_index++;
    $total += count($response_arr);
} while (count($response_arr) == 100);

$table->setHeaders(array(   'ID',
                            'URL',
                            'Namespace',
                            'Path',
                            'Issues',
                            'Commits',
                            'Size',
                            'Forked From',
                            'Last Active'
));

$k = 1;
foreach ($projects as $project) {
  //handle empty fork
  if(!isset($project["forked_from_project"])){
    $project["forked_from_project"]["path_with_namespace"] = '';
  }

  if (!$project["archived"]){
    $table->addRow(array(   $project["id"],
                            $project["path_with_namespace"],
                            $project["namespace"]["path"],
                            $project["path"],
                            $project["open_issues_count"],
                            $project["statistics"]["commit_count"],
                            $project["statistics"]["repository_size"],
                            $project["forked_from_project"]["path_with_namespace"],
                            $project["last_activity_at"]
    ));
  }//end if
}//end for

//sort repos by number of open issues
$table->sort(4);
$table->display();
