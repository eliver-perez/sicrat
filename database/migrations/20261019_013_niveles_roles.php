<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    if (!$schema->columnExists('niveles_organizacionales', 'rol_asociado')) {
        $db->exec('ALTER TABLE niveles_organizacionales ADD COLUMN rol_asociado SMALLINT NULL,
            ADD CONSTRAINT fk_nivel_rol_asociado FOREIGN KEY (rol_asociado) REFERENCES usuarios_tipos(id)');
    }
};
