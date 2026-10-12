<?php

declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/Core/bootstrap.php';

$options=getopt('',['backup:','prior:','actor:','confirmation:']);
$repository=new App\Repositories\CommonRepository((new App\Core\Database())->getConnection());
(new App\Services\Common\RestoreService($repository))->offlineActive(
	App\Services\Common\Values::text($options['backup'] ?? null,36),
	App\Services\Common\Values::text($options['prior'] ?? null,36),
	App\Services\Common\Values::text($options['confirmation'] ?? null,100),
	App\Services\Common\Values::integer($options['actor'] ?? null)
);
echo "Restauración offline terminada. Revisar integridad y retirar mantenimiento explícitamente.\n";
