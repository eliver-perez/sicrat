<?php

declare(strict_types=1);

namespace App\Core;

class Auth
{
    public static function id(): ?int
    {
        return $_SESSION['SICRAT_ID'] ?? null;
    }

    public static function organizationId(): ?int
    {
        return $_SESSION['SICRAT_ORGANIZATION_ID'] ?? null;
    }

    public static function organizationBranchId(): ?int
    {
        return $_SESSION['SICRAT_ORGANIZATION_BRANCH_ID'] ?? null;
    }
}