<?php
	require __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../app/Support/helpers.php';
	
	loadEnv(dirname(__DIR__) . '/../.env');
	$config = require __DIR__ . '/../../app/Core/config.php';

    use App\Core\Database;

    $database = new Database();
    $conn = $database->getConnection();
	
	require  __DIR__ . '/../../app/Views/auth/index.php';
?>