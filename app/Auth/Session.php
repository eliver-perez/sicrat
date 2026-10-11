<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use PDO;

class Session
{
    public ?int $id = null;
    public ?string $nombre = null;
    public ?string $email = null;
    public ?string $token = null;
    public ?string $tipo_codigo = null;
    public ?string $tipo = null;
    public bool $active = false;

    public function __construct()
    {
        self::start();
        $validator = new SessionValidator((new Database())->getConnection());
        if (!$validator->validate()) {
            $this->destroySession();
        }

        $this->id = (int) $_SESSION['SICRAT_ID'];
        $this->nombre = $_SESSION['SICRAT_NAME'] ?? null;
        $this->email = $_SESSION['SICRAT_EMAIL'] ?? null;
        $this->token = $_SESSION['SICRAT_AUTH_TOKEN'] ?? null;
        $this->tipo_codigo = isset($_SESSION['SICRAT_USER_TYPE_CODE']) ? (string) $_SESSION['SICRAT_USER_TYPE_CODE'] : null;
        $this->tipo = $_SESSION['SICRAT_USER_TYPE'] ?? null;

        $_SESSION['SICRAT_LAST_ACTIVITY'] = time();

        $this->active = true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipoId(): ?int
    {
        return isset($_SESSION['SICRAT_USER_TYPE_ID']) ? (int) $_SESSION['SICRAT_USER_TYPE_ID'] : null;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        session_name('SICRATSESSID');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || parse_url((string) env('APP_URL', ''), PHP_URL_SCHEME) === 'https';
        $path = parse_url((string) env('APP_URL', ''), PHP_URL_PATH) ?: '/';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim($path, '/') . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function clear(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
            session_destroy();
        }
    }

    public function destroySession(): never
    {
        self::clear();
        header('Location: ' . base_url('/autenticacion/'));
        exit;
    }
}
