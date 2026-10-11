<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Auth\Session;
use App\Core\HttpError;
use App\Core\Request;

class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (!isset($_SESSION['SICRAT_CSRF_TOKEN'])) {
            $_SESSION['SICRAT_CSRF_TOKEN'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['SICRAT_CSRF_TOKEN'];
    }

    public static function validate(Request $request): void
    {
        $token = $request->header('X-CSRF-Token', $request->input('_csrf'));
        $expected = $_SESSION['SICRAT_CSRF_TOKEN'] ?? null;
        if (!is_string($token) || !is_string($expected) || !hash_equals($expected, $token)) {
            throw new HttpError('La solicitud no es válida. Recarga la página e inténtalo de nuevo.', 403);
        }
    }
}
