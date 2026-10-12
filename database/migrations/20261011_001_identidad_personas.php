<?php

declare(strict_types=1);

use App\Core\Migrations;
use App\Core\PersonCode;

return static function (PDO $db, Migrations $schema): void {
    // Ninguna identidad ni cuenta existente se elimina, fusiona o vincula automáticamente.
    if (!$schema->columnExists('personas', 'codigo_persona')) {
        $db->exec('ALTER TABLE personas ADD codigo_persona VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL');
    }
    if (!$schema->indexExists('personas', 'uq_personas_codigo')) {
        if ($db->query('SELECT codigo_persona FROM personas WHERE codigo_persona IS NOT NULL GROUP BY codigo_persona HAVING COUNT(*) > 1 LIMIT 1')->fetchColumn() !== false) {
            throw new RuntimeException('Hay códigos repetidos; revise los datos antes de continuar.');
        }
        $db->exec('ALTER TABLE personas ADD UNIQUE KEY uq_personas_codigo (codigo_persona)');
    }
    $generator = new PersonCode();
    $lastId = 0;
    do {
        $statement = $db->prepare('SELECT id FROM personas WHERE codigo_persona IS NULL AND id > ? ORDER BY id LIMIT 100');
        $statement->execute([$lastId]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            $lastId = (int) $id;
            for ($attempt = 0; $attempt < 20; $attempt++) {
                try {
                    $update = $db->prepare('UPDATE personas SET codigo_persona = ? WHERE id = ? AND codigo_persona IS NULL');
                    $update->execute([$generator->generate(), $id]);
                    break;
                } catch (PDOException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== 1062 || $attempt === 19) throw $exception;
                }
            }
        }
    } while ($rows !== []);
    $db->exec('ALTER TABLE personas MODIFY codigo_persona VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, MODIFY seccion INT NULL');
    foreach (['curp_unica', 'clave_elector_unica'] as $column) {
        if (!$schema->columnExists('organizaciones', $column)) {
            $db->exec('ALTER TABLE organizaciones ADD ' . $column . ' TINYINT NOT NULL DEFAULT 1');
        }
    }
    foreach (['curp', 'clave_elector', 'email', 'telefono'] as $column) {
        $index = 'idx_personas_org_' . $column;
        if (!$schema->indexExists('personas', $index)) $db->exec('ALTER TABLE personas ADD INDEX ' . $index . ' (organizacion, ' . $column . ')');
    }
    if (!$schema->columnExists('usuarios', 'persona')) {
        $db->exec('ALTER TABLE usuarios ADD persona INT NULL, ADD INDEX idx_usuarios_persona (persona), ADD CONSTRAINT fk_usuarios_persona FOREIGN KEY (persona) REFERENCES personas (id)');
    }
    // CURP/clave tienen índices NO únicos: la regla estricta se aplica con bloqueo de organización.
    // Duplicados históricos se conservan; el preflight los informa antes de aplicar cambios.
};
