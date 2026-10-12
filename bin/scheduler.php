<?php

declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/Core/bootstrap.php';
$repository=new App\Repositories\CommonRepository((new App\Core\Database())->getConnection());
$scheduled=(new App\Services\Common\SchedulerService($repository))->run();
echo 'Respaldos programados: '.count($scheduled)."\n";
