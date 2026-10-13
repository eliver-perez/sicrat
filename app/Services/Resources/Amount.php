<?php

declare(strict_types=1);

namespace App\Services\Resources;

use InvalidArgumentException;

final class Amount
{
	public const MAX=99999999999999;
	public static function parse(mixed $value,int $scale = 2,bool $zero = false): int
	{
		// JSON debe transportar dinero como texto; los floats nunca entran al cálculo.
		if (is_int($value)) $value=(string) $value;
		if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,'.$scale.'}))?$/D',$value,$parts)) throw new InvalidArgumentException('Importe inválido. Usa números sin separador de miles y hasta '.$scale.' decimales.');
		$units=(int) $parts[1]*(10**$scale)+(int) str_pad($parts[2] ?? '',$scale,'0');
		if ($units>self::MAX || (!$zero && $units===0)) throw new InvalidArgumentException('El importe debe ser positivo y estar dentro del límite permitido.');
		return $units;
	}

	public static function format(int $units,int $scale = 2): string
	{
		$sign=$units<0 ? '-' : '';
		$units=abs($units);
		$factor=10**$scale;
		return $sign.intdiv($units,$factor).'.'.str_pad((string) ($units%$factor),$scale,'0',STR_PAD_LEFT);
	}

	public static function add(int $a,int $b): int
	{
		$result=$a+$b;
		if (!is_int($result) || abs($result)>self::MAX) throw new InvalidArgumentException('La suma excede el límite permitido.');
		return $result;
	}

	public static function signed(string $value,int $scale = 2): int {
		return str_starts_with($value,'-') ? -self::parse(substr($value,1),$scale,true) : self::parse($value,$scale,true);
	}

	public static function ratio(int $a,int $b,int $denominator,bool $round = true): int
	{
		if ($a<0 || $b<0 || $denominator<1) throw new InvalidArgumentException('Proporción inválida.');
		// División binaria con cociente/resto: evita desbordar a*b y nunca utiliza coma flotante.
		$quotient=0;
		$remainder=0;
		$whole=intdiv($a,$denominator);
		$fraction=$a%$denominator;
		foreach (str_split(decbin($b)) as $bit) {
			$quotient=self::add($quotient,$quotient);
			$remainder*=2;
			if ($bit==='1') {
				$quotient=self::add($quotient,$whole);
				$remainder+=$fraction;
			}
			$quotient=self::add($quotient,intdiv($remainder,$denominator));
			$remainder%=$denominator;
		}
		return self::add($quotient,$round && $remainder*2>=$denominator ? 1 : 0);
	}
}
