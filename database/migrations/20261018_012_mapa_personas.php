<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    $db->exec("CREATE TABLE IF NOT EXISTS organizaciones_mapa (
        organizacion INT PRIMARY KEY,
        latitud DECIMAL(10,7) NOT NULL,
        longitud DECIMAL(10,7) NOT NULL,
        zoom TINYINT UNSIGNED NOT NULL,
        editado_por INT NOT NULL,
        fecha DATETIME NOT NULL,
        FOREIGN KEY (organizacion) REFERENCES organizaciones(id),
        FOREIGN KEY (editado_por) REFERENCES usuarios(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
