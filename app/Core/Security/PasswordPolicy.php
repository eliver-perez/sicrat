<?php

declare(strict_types=1);

namespace App\Core\Security;

use InvalidArgumentException;

class PasswordPolicy
{
    public static function read(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Es necesario capturar una contraseña válida.');
        }
        return $value;
    }

    public static function newPassword(mixed $value): string
    {
        $password = self::read($value);
        if (strlen($password) > 72) {
            throw new InvalidArgumentException('La contraseña debe ocupar como máximo 72 bytes.');
        }
        return $password;
    }

    public static function verify(string $password, string $hash): bool
    {
        if (password_verify($password, $hash)) {
            return true;
        }
        // Compatibilidad con altas antiguas que recortaban espacios antes de generar el hash.
        return $password !== trim($password) && password_verify(trim($password), $hash);
    }
}
