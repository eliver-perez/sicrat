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
        $user = env('DB_USERNAME', '');
        $pass = env('DB_PASSWORD', '');

        try {
            $this->conn = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('Error de conexión a la base de datos: ' . $e->getMessage());
        }
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }
}