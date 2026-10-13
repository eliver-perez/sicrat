<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Services\Jornada\Votes;
use App\Services\Jornada\Configuration;
use App\Services\Common\Values;
$checks=0;
function unit7(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
foreach([0,1,200,2147483647,'0','200','0002'] as $value)unit7(Votes::integer($value)===(int)$value,'Enteros válidos.');
foreach([-1,1.0,1.5,true,false,null,'-1','1.0','1e3','1,000','texto',2147483648,'99999999999999999999'] as $value){try{Votes::integer($value);throw new RuntimeException('Aceptó un voto inválido.');}catch(InvalidArgumentException){$checks++;}}
$ids=[Values::uuid(),Values::uuid(),Values::uuid()];$options=[];foreach($ids as $index=>$uuid)$options[]=['id'=>$index+1,'uuid'=>Values::binary($uuid)];
unit7(array_sum(Votes::exact($options,array_combine($ids,[100,80,20])))===200,'Combinación exacta no duplica votos crudos.');
foreach([[$ids[0]=>100],array_combine([Values::uuid(),$ids[1],$ids[2]],[100,80,20])] as $data){try{Votes::exact($options,$data);throw new RuntimeException('Aceptó filas incompletas o ajenas.');}catch(InvalidArgumentException){$checks++;}}
unit7(Configuration::utc('2026-10-13T12:00','America/Monterrey')==='2026-10-13 18:00:00','Hora local se almacena como UTC.');
foreach([['2026-11-01T01:30','America/New_York'],['2026-03-08T02:30','America/New_York'],['2026-02-30T12:00','UTC'],['2026-10-13T12:00','Zona inventada']] as [$date,$zone]){try{Configuration::utc($date,$zone);throw new RuntimeException('Aceptó fecha ambigua, inexistente o zona inválida.');}catch(InvalidArgumentException){$checks++;}}
echo 'OK: '.$checks." comprobaciones unitarias de votos, acta exacta y horario de Jornada.\n";
