<?php

declare(strict_types=1);

// Escritor concurrente restringido a la base desechable del ejecutor de pruebas.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
if (PHP_SAPI!=='cli' || !preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/',(string) env('DB_DATABASE'))) exit(1);
$_SESSION=['SICRAT_ID'=>1,'SICRAT_GLOBAL_TYPE_CODE'=>'superadmin','SICRAT_ORGANIZATION_PROCESS_ID'=>(int) $argv[4]];
$db=(new App\Core\Database())->getConnection();
try {
    if ($argv[1]==='link') (new App\Services\UserAccessService(new App\Repositories\UserAccessRepository($db)))->link($argv[2],$argv[3]);
    else (new App\Services\StructureService(new App\Repositories\StructureRepository($db)))->add($argv[2],['person'=>$argv[3]]);
    echo json_encode(['success'=>true],JSON_THROW_ON_ERROR);
} catch (App\Core\HttpError $error) { echo json_encode(['success'=>false,'status'=>$error->statusCode],JSON_THROW_ON_ERROR); }
