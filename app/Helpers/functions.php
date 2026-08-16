<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

function convertirMes(int|string $mes, int $type = 0): string {
    $meses = [
        1 => ['Enero', 'Ene'],
        2 => ['Febrero', 'Feb'],
        3 => ['Marzo', 'Mar'],
        4 => ['Abril', 'Abr'],
        5 => ['Mayo', 'May'],
        6 => ['Junio', 'Jun'],
        7 => ['Julio', 'Jul'],
        8 => ['Agosto', 'Ago'],
        9 => ['Septiembre', 'Sep'],
        10 => ['Octubre', 'Oct'],
        11 => ['Noviembre', 'Nov'],
        12 => ['Diciembre', 'Dic'],
    ];

    if (!is_numeric($mes)) {
        return (string)$mes;
    }

    $mes = (int)$mes;

    return $meses[$mes][$type] ?? (string)$mes;
}

function revisarPermisos(array $permisos, array $requeridos): bool {
    foreach ($requeridos as $permiso) {
        if (in_array($permiso, $permisos, true)) {
            return true;
        }
    }

    return false;
}

function obtenerAjuste(PDO $conn, string $ajuste): ?string {
    $tsql = "SELECT val FROM configuracion WHERE config = :ajuste";

    $stmt = $conn->prepare($tsql);
    $stmt->bindValue(':ajuste', $ajuste);
    $stmt->execute();

    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    return $data['val'] ?? null;
}

function generateRandomString(int $length = 10): string {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }

    return $randomString;
}

function generateUCRandomString(int $length = 10): string {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }

    return $randomString;
}

function getClientIP(): ?string {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($keys as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }

        $value = trim($_SERVER[$key]);

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $ips = array_map('trim', explode(',', $value));
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        } else {
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }
    }

    return null;
}

function getDeviceType(?string $userAgent): ?string {
    if (!$userAgent) {
        return null;
    }

    $ua = mb_strtolower($userAgent);

    if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
        return 'Tablet';
    }

    if (str_contains($ua, 'mobile')) {
        return 'Móvil';
    }

    return 'Escritorio';
}