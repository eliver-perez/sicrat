<?php

declare(strict_types=1);

use App\Core\Migrations;

return static function (PDO $db, Migrations $schema): void {
    if (!$schema->indexExists('personas_domicilios','ix_domicilio_mapa')) {
        $db->exec('ALTER TABLE personas_domicilios ADD INDEX ix_domicilio_mapa (latitud,longitud,persona)');
    }
    if (!$schema->indexExists('personas_domicilios','ix_domicilio_preferido')) {
        $db->exec('ALTER TABLE personas_domicilios ADD INDEX ix_domicilio_preferido (persona,principal DESC,id)');
    }
};
