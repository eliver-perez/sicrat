<?php

declare(strict_types=1);

// Proceso paralelo de prueba: se niega a escribir fuera de una base desechable.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
if (PHP_SAPI !== 'cli' || !preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/', (string) env('DB_DATABASE'))) exit(1);
$_SESSION = ['SICRAT_ID' => 1, 'SICRAT_GLOBAL_TYPE_CODE' => 'superadmin'];
$db = (new App\Core\Database())->getConnection();
$service = new App\Services\PersonIdentityService(new App\Repositories\PersonIdentityRepository($db));
$db->beginTransaction();
$db->query('SELECT COUNT(*) FROM personas')->fetchColumn();
usleep(200000);
$result = $service->create(['organization' => $argv[1], 'name' => 'Alta concurrente', 'curp' => $argv[2], 'confirm_duplicate' => true]);
$db->commit();
echo json_encode($result, JSON_THROW_ON_ERROR);
