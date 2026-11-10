<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    $db->exec("CREATE TABLE IF NOT EXISTS dashboard_notas (
        id INT AUTO_INCREMENT PRIMARY KEY, uuid BINARY(16) NOT NULL UNIQUE,
        usuario INT NOT NULL, texto VARCHAR(1000) NOT NULL,
        recordatorio DATETIME NULL, realizada TINYINT NOT NULL DEFAULT 0, fijada TINYINT NOT NULL DEFAULT 0,
        creada DATETIME NOT NULL, modificada DATETIME NOT NULL,
        KEY ix_notas_propietario (usuario,realizada,fijada,recordatorio,id),
        FOREIGN KEY (usuario) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$schema->indexExists('auditoria','ix_auditoria_dashboard')) {
        $db->exec('ALTER TABLE auditoria ADD KEY ix_auditoria_dashboard (organizacion,proceso,f_registro,id)');
    }
    if (!$schema->indexExists('estructura_integrantes_procesos','ix_incorporaciones_dashboard')) {
        $db->exec('ALTER TABLE estructura_integrantes_procesos ADD KEY ix_incorporaciones_dashboard (proceso,activo,fecha,persona)');
    }
};
