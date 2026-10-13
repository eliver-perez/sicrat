<?php

declare(strict_types=1);

namespace App\Services\Jornada;

final class Votes
{
    public static function integer(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D',(string)$value) || strlen(ltrim((string)$value,'0'))>10 || (int)$value>2147483647) throw new \InvalidArgumentException('Los votos deben ser enteros no negativos, sin decimales ni separadores.');
        return (int)$value;
    }
    public static function exact(array $options,mixed $values): array
    {
        if (!is_array($values) || count($values)!==count($options) || !$options) throw new \InvalidArgumentException('Captura todas las filas activas del acta, exactamente una vez.');
        $votes=[];
        foreach($options as $option) {
            $uuid=\App\Services\Common\Values::uuidText($option['uuid']);
            if(!array_key_exists($uuid,$values)) throw new \InvalidArgumentException('Falta una fila del acta o hay una opción ajena a esta elección.');
            $votes[$option['id']]=self::integer($values[$uuid]);
        }
        return $votes;
    }
}
