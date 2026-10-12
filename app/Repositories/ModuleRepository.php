<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpError;
use PDO;

class ModuleRepository
{
    public function __construct(private PDO $db) {}

    public function catalog(int $organization, ?int $process): array
    {
        $statement = $this->db->prepare('SELECT m.*,COALESCE(o.autorizado,0) autorizacion_organizacion,COALESCE(o.seleccionado,0) seleccion_organizacion,COALESCE(pe.autorizado,1) autorizacion_eleccion,COALESCE(pe.seleccionado,o.seleccionado,0) seleccion_eleccion FROM modulos m LEFT JOIN modulos_organizaciones o ON o.modulo=m.codigo AND o.organizacion=? LEFT JOIN modulos_procesos pe ON pe.modulo=m.codigo AND pe.proceso=? ORDER BY m.esencial DESC,m.nombre');
        $statement->execute([$organization,$process]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['habilitado'] = (bool) $row['esencial'] || ((bool) $row['autorizacion_organizacion'] && (bool) $row['autorizacion_eleccion'] && (bool) $row['seleccion_eleccion']);
        return $rows;
    }

    public function require(string $module, int $process): void
    {
        $statement = $this->db->prepare('SELECT organizacion FROM procesos_electorales WHERE id=? AND estatus=1');
        $statement->execute([$process]);
        $organization = $statement->fetchColumn();
        if ($organization !== false) foreach ($this->catalog((int) $organization,$process) as $row) {
            if ($row['codigo'] === $module && $row['habilitado']) return;
        }
        throw new HttpError('El módulo no está habilitado para esta elección.',403);
    }
}
