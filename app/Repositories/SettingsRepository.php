<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SettingsRepository
{
    public function __construct(private PDO $db) {}

    // El esquema actual no implementa ajustes. SettingsService conserva sus valores por defecto.
    public function getGlobalById(string $id): ?array
    {
        return null;
    }

    public function getById(string $id, int $organization): ?array
    {
        return null;
    }
}
