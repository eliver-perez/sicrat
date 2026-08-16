<?php
	require('functions.php');
	require __DIR__ . '/../vendor/autoload.php';
	
	loadEnv(dirname(__DIR__) . '/.env');
	$config = require __DIR__ . '/config.php';

	use App\Auth\WebSession;
    use App\Core\Database;

	$session = new WebSession();
    $database = new Database();
    $conn = $database->getConnection();