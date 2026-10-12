<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

class Migrations
{
    public function __construct(private PDO $db) {}

    public function columnExists(string $table, string $column): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $statement->execute([$table, $column]);
        return (bool) $statement->fetchColumn();
    }

    public function indexExists(string $table, string $index): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $statement->execute([$table, $index]);
        return (bool) $statement->fetchColumn();
    }

    public function run(): array
    {
        // DDL hace commit implícito en ambos motores: cada migración admite reanudar pasos parciales.
        if (!(int) $this->db->query("SELECT GET_LOCK(CONCAT(DATABASE(), ':migrations'), 10)")->fetchColumn()) {
            throw new RuntimeException('Otra ejecución de migraciones está activa.');
        }
        try {
            $this->db->exec('CREATE TABLE IF NOT EXISTS esquema_migraciones (nombre VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL, aplicada_en DATETIME NOT NULL) ENGINE=InnoDB');
            $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
            sort($files);
            $applied = [];
            foreach ($files as $file) {
                $name = basename($file);
                $checksum = hash_file('sha256', $file);
                $statement = $this->db->prepare('SELECT checksum FROM esquema_migraciones WHERE nombre = ?');
                $statement->execute([$name]);
                $previous = $statement->fetchColumn();
                if ($previous !== false) {
                    if ($previous !== $checksum) throw new RuntimeException('Una migración aplicada fue modificada: ' . $name);
                    continue;
                }
                $migration = require $file;
                $migration($this->db, $this);
                $statement = $this->db->prepare('INSERT INTO esquema_migraciones VALUES (?, ?, NOW())');
                $statement->execute([$name, $checksum]);
                $applied[] = $name;
            }
            return $applied;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':migrations'))");
        }
    }
}
