<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auth;
use App\Core\HttpError;
use PDO;

class AccountScopeRepository
{
    public function __construct(private PDO $db) {}
    public function predicate(string $permission, ?int $process): array
    {
        $effective=(new PermissionRepository($this->db))->require(Auth::id() ?? 0,$permission,$process);
        if ($effective['super']) return ['1=1',[]];
        $scopes=$effective['scopes'][$permission]; $parts=[]; $params=[(int) $effective['user']['organizacion']];
        if (in_array('organizacion',$scopes,true)) return ['u.organizacion=?',$params];
        if (in_array('eleccion',$scopes,true) && $process!==null) {
            $parts[]='(EXISTS (SELECT 1 FROM usuarios_procesos target WHERE target.usuario=u.id AND target.proceso=?) OR u.registro=?)'; $params[]=$process; $params[]=Auth::id();
        }
        [$personScope,$personParams]=(new ScopeRepository($this->db))->predicate($permission,$process);
        $parts[]="EXISTS (SELECT 1 FROM personas p WHERE p.id=u.persona AND ($personScope))"; $params=[...$params,...$personParams];
        return ['u.organizacion=? AND (' . implode(' OR ',$parts) . ')',$params];
    }
    public function assertUser(int $user, string $permission, ?int $process): void
    {
        [$scope,$params]=$this->predicate($permission,$process); $statement=$this->db->prepare("SELECT 1 FROM usuarios u WHERE u.id=? AND ($scope)"); $statement->execute([$user,...$params]);
        if (!$statement->fetchColumn()) throw new HttpError('La cuenta no está disponible dentro de tu alcance.',404);
    }
}
