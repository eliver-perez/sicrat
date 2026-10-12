<?php

declare(strict_types=1);

namespace App\Core;

class PersonCode
{
    // Se omiten caracteres visualmente ambiguos. Diez caracteres aportan 50 bits aleatorios.
    public function generate(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($position = 0; $position < 10; $position++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }
}
