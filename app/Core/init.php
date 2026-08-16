<?php
	use App\Auth\WebSession;
    use App\Core\Database;
    use App\Core\Security\EncryptionService;

    define('APP_PATH', dirname(__DIR__));
    define('PUBLIC_PATH', APP_PATH . '/../public');
    define('STORAGE_PATH', APP_PATH . '/../storage');

	require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../Support/helpers.php';
	
	loadEnv(dirname(__DIR__) . '/../.env');
	$config = require __DIR__ . '/config.php';

    $encryptionKey = env('APP_ENCRYPTION_KEY');

    if (!is_string($encryptionKey) || trim($encryptionKey) === '') {
        throw new RuntimeException(
            'No se encontró APP_ENCRYPTION_KEY.'
        );
    }

    $encryptionService = new EncryptionService(
        $encryptionKey
    );

	$session = new WebSession();
    $database = new Database();
    $conn = $database->getConnection();