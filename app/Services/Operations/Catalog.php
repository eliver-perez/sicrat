<?php

declare(strict_types=1);

namespace App\Services\Operations;

use InvalidArgumentException;

final class Catalog
{
	// Nombres internos cerrados: nunca se interpolan tablas o columnas recibidas del cliente.
	public const ENTITIES = [
		'interacciones'=>['module'=>'interacciones','permission'=>'interacciones','title'=>'Interacciones','name'=>'resultado','date'=>'f_interaccion','creator'=>'usuario'],
		'encuestas'=>['module'=>'encuestas','permission'=>'encuestas','title'=>'Encuestas','name'=>'encuesta','date'=>'fecha','creator'=>'creado_por'],
		'tareas'=>['module'=>'tareas_metas','permission'=>'tareas','title'=>'Tareas','name'=>'titulo','date'=>'f_registro','creator'=>'creado_por'],
		'metas'=>['module'=>'tareas_metas','permission'=>'metas','title'=>'Metas','name'=>'titulo','date'=>'fecha_inicio','creator'=>'asignado_por'],
		'eventos'=>['module'=>'eventos','permission'=>'eventos','title'=>'Eventos','name'=>'evento','date'=>'fecha_inicio','creator'=>'creado_por'],
		'peticiones'=>['module'=>'peticiones_incidencias','permission'=>'peticiones','title'=>'Peticiones','name'=>'descripcion','date'=>'fecha','creator'=>'capturado_por'],
		'incidencias'=>['module'=>'peticiones_incidencias','permission'=>'incidencias','title'=>'Incidencias','name'=>'descripcion','date'=>'f_reporte','creator'=>'reportado_por'],
		'transporte'=>['module'=>'transporte','permission'=>'transporte','title'=>'Transporte','name'=>'observaciones_logisticas','date'=>'fecha','creator'=>'editado_por'],
		'conversaciones'=>['module'=>'mensajeria','permission'=>'mensajes','title'=>'Mensajería','name'=>'titulo','date'=>'f_registro','creator'=>'creado_por'],
	];
	public static function entity(string $entity): array { return self::ENTITIES[$entity] ?? throw new InvalidArgumentException('Módulo operativo inválido.'); }
	public static function choice(mixed $value,array $allowed): string
	{
		if (!is_string($value) || !in_array($value,$allowed,true)) throw new InvalidArgumentException('Selecciona una opción válida.');
		return $value;
	}
	public static function dateTime(mixed $value,bool $optional = false): ?string
	{
		if ($optional && ($value===null || $value==='')) return null;
		if (!is_string($value)) throw new InvalidArgumentException('Fecha y hora inválidas.');
		$value=str_replace('T',' ',$value); if (strlen($value)===16) $value.=':00';
		$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
		if (!$date || $date->format('Y-m-d H:i:s')!==$value) throw new InvalidArgumentException('Fecha y hora inválidas.');
		return $value;
	}
	public static function list(mixed $values,int $maximum = 100): array
	{
		if (!is_array($values) || count($values)>$maximum || !array_is_list($values)) throw new InvalidArgumentException('Lista inválida o demasiado extensa.');
		return $values;
	}
}
