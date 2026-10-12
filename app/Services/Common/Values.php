<?php

declare(strict_types=1);

namespace App\Services\Common;

use InvalidArgumentException;

final class Values
{
	public static function uuid(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 15) | 64);
		$bytes[8] = chr((ord($bytes[8]) & 63) | 128);
		return self::uuidText($bytes);
	}
	public static function uuidText(string $bytes): string
	{
		$hex = bin2hex($bytes);
		return substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20);
	}
	public static function binary(mixed $uuid): string
	{
		if (!is_string($uuid) || !preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $uuid)) throw new InvalidArgumentException('UUID inválido.');
		return hex2bin(str_replace('-', '', $uuid));
	}
	public static function text(mixed $value, int $maximum, bool $empty = false): string
	{
		if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0") || mb_strlen($value)>$maximum || (!$empty && trim($value)==='')) throw new InvalidArgumentException('Texto inválido o demasiado largo.');
		return trim($value);
	}
	public static function integer(mixed $value, int $minimum = 1, int $maximum = PHP_INT_MAX): int
	{
		if (filter_var($value,FILTER_VALIDATE_INT)===false || (int) $value<$minimum || (int) $value>$maximum) throw new InvalidArgumentException('Número fuera del intervalo permitido.');
		return (int) $value;
	}
	public static function json(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }
	public static function date(mixed $value): string
	{
		$date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d',$value) : false;
		if (!$date || $date->format('Y-m-d')!==$value) throw new InvalidArgumentException('Fecha inválida.');
		return $value;
	}
}
