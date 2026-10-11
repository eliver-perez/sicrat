<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\Session;
use App\Auth\SessionValidator;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class AuthMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        Session::start();
        if (!(new SessionValidator((new Database())->getConnection()))->validate()) {
            Session::clear();
            $response->error('La sesión no es válida o ha expirado.', 401);
            return false;
        }
        return true;
    }
}
