<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/Support/helpers.php';
loadEnv(dirname(__DIR__).'/.env');
if (!preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/D',(string) env('DB_DATABASE',''))) throw new RuntimeException('Requiere base desechable.');
$repository=new App\Repositories\CommonRepository((new App\Core\Database())->getConnection());
if (in_array('--claim-only',$argv,true)) {
	$job=(new App\Services\Common\QueueService($repository))->claim('worker-que-finaliza','prueba_concurrencia');
	echo json_encode(['claimed'=>$job['id'] ?? null]); exit;
}
$worker=new App\Services\Common\WorkerService($repository);
$worker->register('prueba_concurrencia',function ($payload,$job) use ($repository) {
	usleep(150000);
	$repository->execute('INSERT INTO test_job_effects (job,valor) VALUES (?,?)',[$job['id'],$payload['value']]);
});
echo json_encode(['processed'=>$worker->run(2,10,'prueba_concurrencia')]);
