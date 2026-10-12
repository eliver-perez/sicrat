<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auth;
use App\Core\HttpError;
use PDO;

class ScopeRepository
{
    public function __construct(private PDO $db) {}

    // El predicado se aplica antes de paginar. Los alias y permisos son internos.
    public function predicate(string $permission, ?int $process, string $alias = 'p', ?int $actor = null): array
    {
        if (!in_array($alias,['p','candidate'],true)) throw new \InvalidArgumentException('Alias de alcance inválido.');
        $effective = (new PermissionRepository($this->db))->require($actor ?? Auth::id() ?? 0,$permission,$process);
        if ($effective['super']) return ['1=1',[]];
        $scopes = $effective['scopes'][$permission];
        $user = $effective['user'];
        $params = [(int) $user['organizacion']];
        $parts = [];
        foreach ($scopes as $scope) {
            if ($scope === 'organizacion') { $parts = ['1=1']; break; }
            if ($scope === 'propio') { $parts[] = "$alias.id=?"; $params[] = $user['persona'] ?? 0; }
            elseif ($scope === 'eleccion' && $process !== null) {
                $parts[] = "(EXISTS (SELECT 1 FROM personas_procesos pp WHERE pp.persona=$alias.id AND pp.proceso=?) OR EXISTS (SELECT 1 FROM estructura_integrantes_procesos ep WHERE ep.persona=$alias.id AND ep.proceso=?))";
                $params[] = $process; $params[] = $process;
            } elseif ($scope === 'territorio' && $process !== null) {
                $parts[] = "EXISTS (SELECT 1 FROM usuarios_territorios t WHERE t.usuario=? AND t.proceso=? AND t.seccion=$alias.seccion)";
                $params[] = $user['id']; $params[] = $process;
            } elseif (in_array($scope,['directos','descendientes'],true) && $process !== null && $user['persona'] !== null) {
                $depth = $scope === 'directos' ? '=1' : '>0';
                $parts[] = "EXISTS (SELECT 1 FROM estructura_integrantes_procesos own JOIN estructura_relaciones rel ON rel.ancestro=own.integrante JOIN estructura_integrantes_procesos team ON team.integrante=rel.descendiente AND team.proceso=own.proceso WHERE own.persona=? AND own.proceso=? AND own.activo=1 AND team.activo=1 AND team.persona=$alias.id AND rel.profundidad$depth)";
                $params[] = $user['persona']; $params[] = $process;
            }
        }
        if ($parts === ['1=1']) $params = [(int) $user['organizacion']];
        return ["$alias.organizacion=? AND (" . ($parts === [] ? '0=1' : implode(' OR ',$parts)) . ')',$params];
    }

    public function assertPerson(int $person, string $permission, ?int $process, ?int $actor = null): void
    {
        [$condition,$params] = $this->predicate($permission,$process,'p',$actor);
        $statement = $this->db->prepare("SELECT 1 FROM personas p WHERE p.id=? AND ($condition)");
        $statement->execute([$person,...$params]);
        if (!$statement->fetchColumn()) throw new HttpError('La persona no está disponible dentro de tu alcance.',404);
    }
}
