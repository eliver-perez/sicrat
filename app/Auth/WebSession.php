<?php

declare(strict_types=1);

namespace App\Auth;

class WebSession extends Session
{
    public function getUsuario(): ?string
    {
        return $this->getEmail();
    }

    public function verifyUserRights(array $allowedRoles): bool
    {
        return Authorization::canAny($allowedRoles);
    }

    public function denyAccess(): never
    {
        header('Location: ' . base_url('?callBack=deniedAccess'));
        exit;
    }
}
