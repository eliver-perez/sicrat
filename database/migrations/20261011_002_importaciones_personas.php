<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    // Área temporal de revisión exclusiva de personas; no es una cola ni un servicio general de archivos.
    $db->exec('CREATE TABLE IF NOT EXISTS personas_importaciones (
        id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        organizacion INT NOT NULL, usuario INT NOT NULL, encabezados TEXT NOT NULL,
        mapeo TEXT NULL, detectar_duplicados TINYINT NOT NULL DEFAULT 1,
        estado VARCHAR(20) NOT NULL DEFAULT \'cargado\', total INT NOT NULL DEFAULT 0,
        f_registro DATETIME NOT NULL, expira_en DATETIME NOT NULL,
        FOREIGN KEY (organizacion) REFERENCES organizaciones(id),
        FOREIGN KEY (usuario) REFERENCES usuarios(id), INDEX (expira_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE IF NOT EXISTS personas_importacion_filas (
        importacion CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        fila INT NOT NULL, datos MEDIUMTEXT NOT NULL, normalizados TEXT NULL,
        nombre_completo VARCHAR(242) NULL, curp VARCHAR(18) NULL, clave_elector VARCHAR(30) NULL,
        telefono VARCHAR(20) NULL, email VARCHAR(150) NULL,
        revision TEXT NULL, decision TEXT NULL, resultado TEXT NULL,
        estado VARCHAR(20) NOT NULL DEFAULT \'pendiente\',
        PRIMARY KEY (importacion, fila), FOREIGN KEY (importacion) REFERENCES personas_importaciones(id) ON DELETE CASCADE,
        INDEX (importacion, curp), INDEX (importacion, clave_elector), INDEX (importacion, nombre_completo),
        INDEX (importacion, telefono), INDEX (importacion, email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};
