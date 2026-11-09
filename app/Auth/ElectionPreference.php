<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Auth;

// Preferencia de este navegador; nunca sustituye la autorización vigente en la BD.
class ElectionPreference
{
    private function name(): string
    {
        return 'sicrat_election_' . substr(hash('sha256', (string) Auth::id()), 0, 16);
    }

    private function signature(string $uuid): string
    {
        return hash_hmac('sha256', Auth::id() . ':' . Auth::organizationId() . ':' . $uuid, env('HMAC_SALT'));
    }

    public function read(): ?string
    {
        $value = $_COOKIE[$this->name()] ?? '';
        if (!is_string($value) || !preg_match('/^([a-f0-9-]{36})\.([a-f0-9]{64})$/D', $value, $parts)) return null;
        return hash_equals($this->signature($parts[1]), $parts[2]) ? $parts[1] : null;
    }

    public function remember(string $uuid): void
    {
        $options = session_get_cookie_params();
        setcookie($this->name(), $uuid . '.' . $this->signature($uuid), [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => $options['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
