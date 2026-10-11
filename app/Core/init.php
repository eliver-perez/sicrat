<?php
	use App\Auth\WebSession;
	use App\Core\Database;

	require_once __DIR__ . '/bootstrap.php';

	$session = new WebSession();
	$conn = (new Database())->getConnection();
