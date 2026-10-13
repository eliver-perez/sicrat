<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\HttpError;
use App\Services\Common\Values;

final class Allocation
{
	public static function resolve(Context $context,array $data,int $amount,int $organization,?int $process,string $permission): array
	{
		$mode=$data['distribucion'] ?? 'montos';
		if (!in_array($mode,['montos','porcentajes'],true)) throw new \InvalidArgumentException('Distribución inválida.');
		$definitions=$data['elecciones'] ?? ($process===null ? [] : [['proceso'=>$process,'monto'=>Amount::format($amount)]]);
		if (!is_array($definitions) || count($definitions)>100) throw new \InvalidArgumentException('Elecciones inválidas.');
		$rows=[];
		$sum=0;
		foreach($definitions as $entry) {
			$election=$context->process($entry['proceso'] ?? throw new \InvalidArgumentException('Falta elección.'));
			if($election===null || isset($rows[$election])) throw new \InvalidArgumentException('Elección repetida o inválida.');
			$ctx=$context->require('gastos',$permission,$election,true,null,$organization);
			if((int) $ctx['organization']!==$organization) throw new HttpError('Elección de otra organización.',404);
			$value=Amount::parse($mode==='porcentajes' ? ($entry['porcentaje'] ?? null) : ($entry['monto'] ?? null),2,true);
			$sum=Amount::add($sum,$value);
			$rows[$election]=['proceso'=>$election,'value'=>$value];
		}
		if($rows===[]) return [];
		if($process!==null && !isset($rows[$process])) throw new \InvalidArgumentException('Incluye la elección principal en la distribución.');
		if($sum!==($mode==='porcentajes' ? 10000 : $amount)) throw new \InvalidArgumentException($mode==='porcentajes' ? 'Los porcentajes deben sumar 100.' : 'Los montos deben sumar exactamente el gasto.');
		ksort($rows);
		$allocated=0;
		$remainders=[];
		foreach($rows as $key=>&$row) {
			$row['monto']=$mode==='porcentajes' ? Amount::ratio($amount,$row['value'],10000,false) : $row['value'];
			$row['porcentaje']=$mode==='porcentajes' ? Amount::format($row['value']) : null;
			$allocated+=$row['monto'];
			$remainders[$key]=$mode==='porcentajes' ? (($amount%10000)*$row['value'])%10000 : 0;
		}
		unset($row);
		arsort($remainders,SORT_NUMERIC);
		foreach(array_keys($remainders) as $key) {
			if($allocated===$amount) break;
			$rows[$key]['monto']++;
			$allocated++;
		}
		return array_values(array_map(static fn($row)=>['proceso'=>$row['proceso'],'monto'=>Amount::format($row['monto']),'porcentaje'=>$row['porcentaje']],$rows));
	}
}
