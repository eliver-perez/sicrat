<?php

declare(strict_types=1);

namespace App\Core;

class Auth
{
    public static function id(): ?int
    {
        return isset($_SESSION['SICRAT_ID']) ? (int) $_SESSION['SICRAT_ID'] : null;
    }

    public static function organizationId(): ?int
    {
        return isset($_SESSION['SICRAT_ORGANIZATION_ID']) ? (int) $_SESSION['SICRAT_ORGANIZATION_ID'] : null;
    }

    public static function processId(): ?int
    {
        return isset($_SESSION['SICRAT_ORGANIZATION_PROCESS_ID']) ? (int) $_SESSION['SICRAT_ORGANIZATION_PROCESS_ID'] : null;
    }

    public static function isSuperAdmin(): bool
    {
        return ($_SESSION['SICRAT_GLOBAL_TYPE_CODE'] ?? null) === 'superadmin';
    }

    public static function organizationBranchId(): ?int
    {
        return $_SESSION['SICRAT_ORGANIZATION_BRANCH_ID'] ?? null;
    }
}