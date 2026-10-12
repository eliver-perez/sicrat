<?php

declare(strict_types=1);

// Ejecución explícita; nunca se migra automáticamente al atender una petición web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
foreach ((new App\Core\Migrations((new App\Core\Database())->getConnection()))->run() as $name) {
    echo $name . PHP_EOL;
}
