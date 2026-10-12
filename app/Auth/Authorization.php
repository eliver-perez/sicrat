<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Auth;
use App\Core\HttpError;

class Authorization
{
    // Reproduce los accesos de las pantallas actuales. El ámbito electoral se ampliará en Fase 3.
    private const OPERATIONS = [
        'authenticated' => [],
        'organizations.read' => [],
        'organizations.create' => ['superadmin'],
        'processes.read' => [],
        'processes.create' => ['superadmin'],
        'catalogs.read' => [],
        'catalogs.create' => ['superadmin'],
        'sections.read' => ['superadmin', 'captura-personas'],
        'sections.create' => ['superadmin'],
        'persons.read' => ['superadmin', 'captura-personas'],
        'persons.create' => ['superadmin', 'captura-personas'],
        'users.read' => ['superadmin'],
        'users.create' => ['superadmin'],
        'profiles.self' => [],
        'identity.configure' => ['superadmin', 'admin'],
    ];

    public static function requireOperation(string $operation): void
    {
        if (!array_key_exists($operation, self::OPERATIONS)) {
            throw new HttpError('Operación no autorizada.', 403);
        }
        if (Auth::id() === null) {
            throw new HttpError('No autenticado.', 401);
        }
        $permissions = self::OPERATIONS[$operation];
        if ($permissions !== [] && !self::canAny($permissions)) {
            throw new HttpError('No tienes permiso para realizar esta operación.', 403);
        }
    }

    public static function canAny(array $permissions): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }
        return array_intersect($permissions, $_SESSION['SICRAT_RIGHTS'] ?? []) !== [];
    }

    public static function scopeOrganizationId(): ?int
    {
        if (Auth::id() === null) {
            throw new HttpError('No autenticado.', 401);
        }
        if (Auth::isSuperAdmin()) {
            return null;
        }
        $organization = Auth::organizationId();
        if ($organization === null || $organization <= 0) {
            throw new HttpError('No hay una organización autorizada para esta sesión.', 403);
        }
        return $organization;
    }

    public static function scopeProcessId(): ?int
    {
        return Auth::isSuperAdmin() ? null : (Auth::processId() ?? 0);
    }

    public static function assertOrganization(?int $organization): void
    {
        if ($organization === null || $organization <= 0) {
            throw new HttpError('El recurso no está disponible.', 404);
        }
        $scope = self::scopeOrganizationId();
        if ($scope !== null && $scope !== $organization) {
            throw new HttpError('El recurso no está disponible.', 404);
        }
    }

    // Las denegaciones explícitas prevalecen, incluso si otro origen concede el permiso.
    public static function effectivePermissions(array $inherited, array $individual): array
    {
        $permissions = [];
        foreach ($inherited as $row) {
            if ((int) $row['valor'] === 1) {
                $permissions[$row['permiso']] = true;
            }
        }
        $denied = [];
        foreach ($individual as $row) {
            if ((int) $row['valor'] === 1) {
                $permissions[$row['permiso']] = true;
            } else {
                $denied[$row['permiso']] = true;
            }
        }
        return array_keys(array_diff_key($permissions, $denied));
    }
}
