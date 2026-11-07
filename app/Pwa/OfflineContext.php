<?php

declare(strict_types=1);

namespace App\Pwa;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpError;
use App\Repositories\ModuleRepository;

/** This describes an already validated PHP session; it never authenticates a client. */
final class OfflineContext
{
    public static function current(): array
    {
        $user = Auth::id();
        if ($user === null) {
            throw new HttpError('Se requiere conexión para validar tu cuenta.', 401);
        }
        $process = Auth::processId();
        $organization = Auth::organizationId();
        $db = (new Database())->getConnection();
        if ($process !== null) {
            $query = $db->prepare('SELECT organizacion FROM procesos_electorales WHERE id=? AND estatus=1');
            $query->execute([$process]);
            $value = $query->fetchColumn();
            if ($value === false) {
                throw new HttpError('La elección ya no está disponible.', 403);
            }
            $organization = (int) $value;
        }
        $rights = $_SESSION['SICRAT_RIGHTS'] ?? [];
        sort($rights);
        $scopes = $_SESSION['SICRAT_PERMISSION_SCOPES'] ?? [];
        ksort($scopes);
        foreach ($scopes as &$values) {
            sort($values);
        }
        unset($values);
        $modules = $organization ? (new ModuleRepository($db))->catalog($organization, $process) : [];
        $fingerprint = hash('sha256', json_encode([
            $rights, $scopes, $modules, $_SESSION['SICRAT_PERSON_ID'] ?? null,
            $_SESSION['SICRAT_GLOBAL_TYPE_CODE'] ?? null,
        ], JSON_THROW_ON_ERROR));

        // Public correlation ID, with no authentication value. Never expose PHP session IDs/tokens.
        $_SESSION['SICRAT_PWA_SESSION'] ??= bin2hex(random_bytes(16));
        $now = time() * 1000;
        return [
            'profile' => $user . ':' . ($organization ?? 0) . ':' . ($process ?? 0),
            'user' => $user,
            'organization' => $organization,
            'election' => $process,
            'name' => $_SESSION['SICRAT_NAME'] ?? '',
            'election_name' => $_SESSION['SICRAT_ORGANIZATION_PROCESS'] ?? '',
            'access' => $fingerprint,
            'session' => $_SESSION['SICRAT_PWA_SESSION'],
            'validated_at' => $now,
            'expires_at' => $now + 12 * 60 * 60 * 1000,
        ];
    }
}
