<?php

declare(strict_types=1);

namespace App\Core;

class Pagination
{
    public static function limit(mixed $value = 25): int
    {
        return max(1, min((int) $value, 100));
    }

    public static function offset(mixed $value = 0): int
    {
        return max(0, (int) $value);
    }

    // Se consulta una fila adicional para saber si hay otra página sin contar toda la tabla.
    public static function response(array $rows, int $limit, int $offset): array
    {
        return [
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => count($rows) > $limit,
        ];
    }
}
