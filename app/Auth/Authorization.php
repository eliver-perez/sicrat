<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Auth;
use App\Core\HttpError;

class Authorization
{
    // Las operaciones identifican permisos; los servicios comprueban el contexto objetivo.
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
        'persons.read' => ['personas.ver'],
        'persons.create' => ['personas.crear'],
        'persons.update' => ['personas.editar'],
        'persons.participate' => ['personas.participar'],
        'persons.import' => ['personas.importar'],
        'users.read' => ['usuarios.administrar'],
        'users.create' => ['usuarios.administrar'],
        'structure.read' => ['estructura.ver'],
        'structure.admin' => ['estructura.administrar'],
        'structure.form' => ['estructura.formar'],
        'structure.transfer' => ['estructura.transferir_integrante','estructura.transferir_equipo'],
        'structure.approve' => ['estructura.aprobar_transferencias'],
        'levels.admin' => ['niveles.administrar'],
        'permissions.admin' => ['permisos.administrar'],
        'modules.select' => ['modulos.seleccionar'],
        'profiles.self' => [],
        'audit.read' => ['auditoria.ver'],
        'approvals.read' => ['aprobaciones.ver'],
        'approvals.resolve' => ['aprobaciones.resolver'],
        'files.upload' => ['archivos.subir'],
        'files.read' => ['archivos.ver','exportaciones.descargar'],
        'files.delete' => ['archivos.eliminar'],
        'notifications.admin' => ['notificaciones.administrar'],
        'exports.create' => ['exportaciones.generar'],
        'exports.download' => ['exportaciones.descargar'],
        'jobs.admin' => ['trabajos.administrar'],
        'jobs.read' => ['trabajos.ver'],
        'backups.admin' => ['superadmin'],
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
        // Compatibilidad con comprobaciones puras y páginas anteriores a la ampliación.
        if (!isset($_SESSION['SICRAT_PERMISSION_SCOPES'])) {
            $rights = $_SESSION['SICRAT_RIGHTS'] ?? [];
            foreach (\App\Repositories\PermissionRepository::LEGACY as $legacy => $expanded) {
                if (in_array($legacy,$rights,true)) $rights = [...$rights,...$expanded];
            }
            return array_intersect($permissions,$rights) !== [];
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
