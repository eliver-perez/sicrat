<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private PDO $conn;

    public function __construct()
    {
        $host = env('DB_HOST', 'localhost');
        $dbname = env('DB_DATABASE', '');
        $port = (int) env('DB_PORT', 3306);
        $charset = env('DB_CHARSET', 'utf8mb4');
        if ($port < 1 || $port > 65535 || !is_string($charset) || !preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
            throw new \RuntimeException('La configuración de conexión no es válida.');
        }
        $user = env('DB_USERNAME', '');
        $pass = env('DB_PASSWORD', '');

        try {
            $this->conn = new PDO(
                "mysql:host={$host};dbname={$dbname};port={$port};charset={$charset}",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('No fue posible conectar con la base de datos.', previous: $e);
        }
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }
}