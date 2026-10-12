<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/Support/helpers.php';
loadEnv(dirname(__DIR__).'/.env');date_default_timezone_set('America/Mexico_City');
if (!preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/D',(string) env('DB_DATABASE',''))) throw new RuntimeException('Requiere base desechable.');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$repository=new App\Repositories\CommonRepository((new App\Core\Database())->getConnection());
try {
    $result=(new App\Services\Common\ActorContext($repository))->run($input['actor'],$input['process'],function() use($input,$repository) {
        return match($input['action']) {
            'answer'=>(new App\Services\Operations\SurveyService($repository))->answer($input['uuid'],$input['data']),
            'petition'=>(new App\Services\Operations\CaseService($repository,'peticiones'))->create($input['data']),
            'send'=>(new App\Services\Operations\MessagingService($repository))->send($input['uuid'],$input['data']),
            'recur'=>(new App\Services\Operations\TaskService($repository))->recur($input['id'],$input['day']),
            'resolve'=>(new App\Services\Common\ApprovalService($repository))->resolve($input['uuid'],true,'Verificación concurrente'),
            'reassign'=>(new App\Services\Operations\CaseService($repository,'incidencias'))->reassign($input['uuid'],$input['data']),
        };
    });
    echo json_encode(['status'=>200,'data'=>$result],JSON_THROW_ON_ERROR);
} catch(App\Core\HttpError $error) { echo json_encode(['status'=>$error->statusCode]); }
