<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpError;
use PDO;

class PermissionRepository
{
    public const SCOPES = ['propio', 'directos', 'descendientes', 'territorio', 'eleccion', 'organizacion'];
    public const LEGACY = ['captura-personas' => ['personas.ver','personas.crear','personas.editar','personas.participar','personas.importar']];

    public function __construct(private PDO $db) {}

    public static function delegableRights(array $rights): array
    {
        $expanded = [];
        foreach ($rights as $code) {
            // El identificador histórico de captura representa el mismo conjunto de permisos granulares.
            foreach (self::LEGACY[$code] ?? [$code] as $permission) $expanded[$permission] = true;
        }
        return array_keys($expanded);
    }

    public function effective(int $userId, ?int $process): array
    {
        $statement = $this->db->prepare('SELECT u.*,ut.codigo FROM usuarios u JOIN usuarios_tipos ut ON ut.id=u.tipo_usuario WHERE u.id=? AND u.activo=1');
        $statement->execute([$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new HttpError('La cuenta no está activa.',401);
        if ($user['codigo'] === 'superadmin') return ['super'=>true,'rights'=>['superadmin'],'scopes'=>[],'denied'=>[],'user'=>$user];

        $inherited = []; $canonical = [];
        $statement = $this->db->prepare("SELECT p.id permiso,pt.valor,pt.alcance FROM permisos_usuarios_tipo pt JOIN permisos p ON p.id=pt.permiso WHERE pt.tipo=? AND pt.proceso IS NULL AND (p.ambito='global' OR (pt.contexto='global' AND p.ambito='ambos')) AND p.id<>'superadmin'");
        $statement->execute([$user['tipo_usuario']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inherited[$row['permiso']][] = $row;
            $canonical[$row['permiso']] = true;
        }
        if ($process !== null) {
            $statement = $this->db->prepare('SELECT up.tipo_usuario FROM usuarios_procesos up JOIN procesos_electorales pe ON pe.id=up.proceso WHERE up.usuario=? AND up.proceso=? AND up.activo=1 AND up.f_baja IS NULL AND pe.estatus=1 AND pe.organizacion=?');
            $statement->execute([$userId,$process,$user['organizacion']]);
            $type = $statement->fetchColumn();
            if ($type === false) throw new HttpError('No tienes acceso a esta elección.',403);
            $statement = $this->db->prepare("SELECT p.id permiso,pt.valor,pt.alcance,pt.proceso FROM permisos_usuarios_tipo pt JOIN permisos p ON p.id=pt.permiso WHERE pt.tipo=? AND pt.contexto<>'global' AND (pt.proceso IS NULL OR pt.proceso=?) AND p.ambito IN ('electoral','ambos') AND p.id<>'superadmin' ORDER BY pt.proceso IS NULL DESC");
            $statement->execute([$type,$process]);
            $electoral = []; $specific = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['proceso'] !== null && !isset($specific[$row['permiso']])) { $electoral[$row['permiso']] = []; $specific[$row['permiso']] = true; }
                $electoral[$row['permiso']][] = $row;
            }
            foreach ($electoral as $code => $rows) { $canonical[$code]=true; $inherited[$code] = [...($inherited[$code] ?? []), ...$rows]; }
        }
        $statement = $this->db->prepare("SELECT p.id permiso,pu.valor,pu.alcance FROM permisos_usuarios pu JOIN permisos p ON p.id=pu.permiso WHERE pu.usuario=? AND (pu.organizacion IS NULL OR pu.organizacion=?) AND ((pu.proceso IS NULL AND p.ambito IN ('global','ambos')) OR (pu.proceso=? AND p.ambito IN ('electoral','ambos'))) AND p.id<>'superadmin'");
        $statement->execute([$userId,$user['organizacion'],$process]);
        $individual = $statement->fetchAll(PDO::FETCH_ASSOC);
        $scopes = []; $denied = [];
        foreach ($inherited as $rows) foreach ($rows as $row) {
            if ((int) $row['valor'] !== 1) continue;
            foreach ([$row['permiso'], ...(self::LEGACY[$row['permiso']] ?? [])] as $code) {
                // Una decisión granular del rol (incluido valor=0 global) prevalece sobre el alias histórico.
                if ($code!==$row['permiso'] && isset($canonical[$code])) continue;
                $scopes[$code][] = $row['alcance'];
            }
        }
        $overrides = [];
        foreach ($individual as $row) foreach ([$row['permiso'], ...(self::LEGACY[$row['permiso']] ?? [])] as $code) {
            if ((int) $row['valor'] !== 1) $denied[$code] = true;
            else $overrides[$code][] = $row['alcance'];
        }
        foreach ($overrides as $code => $values) $scopes[$code] = $values;
        foreach ($denied as $code => $_) unset($scopes[$code]);
        foreach ($scopes as $code => $values) $scopes[$code] = array_values(array_unique($values));
        return ['super'=>false,'rights'=>array_keys($scopes),'scopes'=>$scopes,'denied'=>array_keys($denied),'user'=>$user];
    }

    public function require(int $user, string $permission, ?int $process): array
    {
        $effective = $this->effective($user,$process);
        if (!$effective['super'] && !in_array($permission,$effective['rights'],true)) throw new HttpError('No tienes permiso para realizar esta operación en la elección indicada.',403);
        return $effective;
    }
}
