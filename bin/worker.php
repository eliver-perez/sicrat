<?php

declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/Core/bootstrap.php';

use App\Core\Database;
use App\Repositories\CommonRepository;
use App\Services\Common\WorkerService;
use App\Services\Common\Values;

$options=getopt('',['once','max:','seconds:','type:']);
$maximum=isset($options['once']) ? 1 : Values::integer($options['max'] ?? 10,1,1000);
$seconds=Values::integer($options['seconds'] ?? 60,1,14400);
$count=(new WorkerService(new CommonRepository((new Database())->getConnection())))->run($maximum,$seconds,$options['type'] ?? null);
echo "Trabajos procesados: $count\n";
