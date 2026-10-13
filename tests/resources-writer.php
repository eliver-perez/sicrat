<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/Support/helpers.php';
loadEnv(dirname(__DIR__).'/.env'); date_default_timezone_set('America/Mexico_City');
if(!preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/D',(string) env('DB_DATABASE',''))) throw new RuntimeException('Requiere una base desechable.');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $repository=new App\Repositories\CommonRepository((new App\Core\Database())->getConnection());
try {
	$result=(new App\Services\Common\ActorContext($repository))->run($input['actor'],$input['process'],fn()=>match($input['action']) {
		'expense'=>(new App\Services\Resources\ExpenseService($repository))->create($input['data']),
		'pay'=>(new App\Services\Resources\ExpenseService($repository))->pay($input['uuid'],$input['data']),
		'movement'=>(new App\Services\Resources\InventoryService($repository))->create($input['data']),
		'transfer'=>(new App\Services\Resources\InventoryService($repository))->transfer($input['data']),
		'justify'=>(new App\Services\Resources\AdvanceService($repository))->justify($input['uuid'],$input['data']),
		'advance-return'=>(new App\Services\Resources\AdvanceService($repository))->returnMoney($input['uuid'],$input['data']),
		'contribution-receive'=>(new App\Services\Resources\ContributionService($repository))->receive($input['uuid'],$input['data']),
		'resolve'=>(new App\Services\Common\ApprovalService($repository))->resolve($input['uuid'],true,'Resolución concurrente comprobada'),
		'edit'=>(new App\Services\Resources\ExpenseService($repository))->update($input['uuid'],$input['data']),
	}); echo json_encode(['status'=>200,'data'=>$result],JSON_THROW_ON_ERROR);
} catch(App\Core\HttpError $error) { echo json_encode(['status'=>$error->statusCode],JSON_THROW_ON_ERROR); }
catch(InvalidArgumentException) { echo json_encode(['status'=>422]); }
