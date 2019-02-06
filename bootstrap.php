<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'autoloader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor/autoload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor/league/csv/autoload.php';

$dotenv = new Dotenv\Dotenv( __DIR__ );
$dotenv->load();

spl_autoload_register(  'Autoloader::loader' );
