<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/app/Support/helpers.php';loadEnv(dirname(__DIR__).'/.env');date_default_timezone_set('America/Mexico_City');
if(!preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/D',(string)env('DB_DATABASE','')))throw new RuntimeException('Requiere una base desechable.');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$repository=new App\Repositories\CommonRepository((new App\Core\Database())->getConnection());
if(!str_starts_with($input['barrier'],sys_get_temp_dir().'/'.env('DB_DATABASE').'/') || str_contains($input['barrier'],'..'))throw new RuntimeException('Barrera fuera del directorio desechable.');
echo "READY\n";flush();$deadline=microtime(true)+10;while(!is_file($input['barrier'])){if(microtime(true)>$deadline)throw new RuntimeException('La barrera no llegó.');usleep(1000);}
try {
    $result=(new App\Services\Common\ActorContext($repository))->run($input['actor'],$input['process'],fn()=>match($input['action']) {
        'capture'=>(new App\Services\Jornada\Captures($repository))->submit($input['data']),
        'correct'=>(new App\Services\Jornada\Captures($repository))->submit($input['data'],true),
        'decide'=>(new App\Services\Jornada\Captures($repository))->decide($input['uuid'],$input['data']),
        'state'=>(new App\Services\Jornada\Configuration($repository))->state($input['transition'],$input['data']),
        'assign'=>(new App\Services\Jornada\Configuration($repository))->assign($input['data']),
        'delete'=>(new App\Services\Common\FileService($repository))->delete($input['uuid']),
    });echo json_encode(['status'=>200,'data'=>$result],JSON_THROW_ON_ERROR);
} catch(App\Core\HttpError $error){echo json_encode(['status'=>$error->statusCode,'message'=>$error->getMessage()]);}
catch(InvalidArgumentException $error){echo json_encode(['status'=>422,'message'=>$error->getMessage()]);}
