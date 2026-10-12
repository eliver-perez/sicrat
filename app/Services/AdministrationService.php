<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Authorization;
use App\Core\Auth;
use App\Core\HttpError;
use App\Core\Service;
use App\Repositories\AdministrationRepository;
use App\Repositories\ElectoralContextRepository;
use App\Repositories\ModuleRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\StructureRepository;
use InvalidArgumentException;
use Throwable;

class AdministrationService extends Service
{
    public function __construct(private AdministrationRepository $repository) {}

    private function audit(?int $organization, ?int $process, string $entity, int $record, mixed $before, mixed $after): void
    {
        (new \App\Services\Common\AuditService(new \App\Repositories\CommonRepository($this->repository->connection())))->record($organization,$process,'configurar',$entity,$record,$before,$after);
    }

    private function process(?string $uuid = null): array
    {
        if ($uuid !== null && $uuid !== '') return (new ElectoralContextRepository($this->repository->connection()))->authorized($this->uuidStringToBinary($uuid),Authorization::scopeOrganizationId());
        $rows = $this->repository->rows('SELECT * FROM procesos_electorales WHERE id=?',[Auth::processId()]);
        if ($rows===[]) throw new InvalidArgumentException('Selecciona una elección.');
        return (new ElectoralContextRepository($this->repository->connection()))->authorized($rows[0]['uuid'],Authorization::scopeOrganizationId());
    }
    private function authorize(string $permission, ?int $process): array
    {
        $effective = (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,$permission,$process);
        if (!$effective['super'] && array_intersect(['organizacion','eleccion'],$effective['scopes'][$permission])===[]) throw new HttpError('La configuración requiere alcance de elección u organización.',403);
        return $effective;
    }
    private function transaction(callable $work): mixed
    {
        $db = $this->repository->connection(); $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); $db->beginTransaction();
        try {
            // Mismo orden que las escrituras estructurales. Evita carreras con retirada de capacidad.
            if (Auth::isSuperAdmin()) $this->repository->rows('SELECT id FROM organizaciones ORDER BY id FOR UPDATE');
            else $this->repository->rows('SELECT id FROM organizaciones WHERE id=? FOR UPDATE',[Auth::organizationId()]);
            $result = $work(); $db->commit(); return $result;
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }

    public function levels(?string $uuid = null): array
    {
        $process = $this->process($uuid);
        (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,'estructura.ver',(int) $process['id']);
        (new ModuleRepository($this->repository->connection()))->require('estructura',(int) $process['id']);
        return array_map(fn ($row) => ['id'=>$this->uuidBinaryToString($row['uuid']),'name'=>$row['nombre'],'order'=>(int) $row['orden'],'active'=>(int) $row['activo'],'can_recruit'=>(int) $row['puede_reclutar'],'same_level'=>(int) $row['permite_mismo_nivel']],$this->repository->rows('SELECT * FROM niveles_organizacionales WHERE proceso=? ORDER BY orden,id',[$process['id']]));
    }
    public function saveLevel(?string $uuid, array $data): array
    {
        return $this->transaction(function () use ($uuid,$data) {
            $process = $this->process($data['election'] ?? null); $this->authorize('niveles.administrar',(int) $process['id']);
            (new ModuleRepository($this->repository->connection()))->require('estructura',(int) $process['id']);
            $name = $this->normalizeRequiredText($data['name'] ?? null,'Indica el nombre del nivel.');
            if (mb_strlen($name)>128) throw new InvalidArgumentException('El nombre del nivel es demasiado largo.');
            $order = $this->normalizeRequiredInt($data['order'] ?? null,'Orden inválido.');
            if ($order>32767) throw new InvalidArgumentException('Orden inválido.');
            $active = $this->normalizeStatus($data['active'] ?? 1); $recruit = $this->normalizeStatus($data['can_recruit'] ?? 0); $same = $this->normalizeStatus($data['same_level'] ?? 0);
            $levelUuid = $uuid ? $this->uuidStringToBinary($uuid) : $this->generateUuidBinary();
            $previous = $uuid ? ($this->repository->rows('SELECT * FROM niveles_organizacionales WHERE uuid=? AND proceso=?',[$levelUuid,$process['id']])[0] ?? null) : null;
            if ($uuid && !$previous) throw new HttpError('El nivel no está disponible.',404);
            if ($this->repository->rows('SELECT id FROM niveles_organizacionales WHERE proceso=? AND orden=? AND uuid<>?',[$process['id'],$order,$levelUuid])!==[]) throw new HttpError('Ya existe un nivel con ese orden.',409);
            if ($previous) {
                if (!$active && $this->repository->rows('SELECT persona FROM estructura_integrantes_procesos WHERE nivel=? AND activo=1 LIMIT 1',[$previous['id']])!==[]) throw new HttpError('Reasigna primero los integrantes activos de este nivel.',409);
                if ((int) $previous['puede_reclutar']===1 && !$recruit && $this->repository->rows('SELECT e.id FROM estructura e JOIN estructura_integrantes_procesos ep ON ep.integrante=e.id JOIN estructura child ON child.nodo_padre=e.id AND child.activo=1 WHERE ep.nivel=? AND ep.activo=1 LIMIT 1',[$previous['id']])!==[]) throw new HttpError('Resuelve primero los equipos directos antes de retirar capacidad organizacional.',409);
                $this->repository->execute('UPDATE niveles_organizacionales SET nombre=?,orden=?,activo=?,puede_reclutar=?,permite_mismo_nivel=?,editado_por=?,fecha=NOW() WHERE id=?',[$name,$order,$active,$recruit,$same,Auth::id(),$previous['id']]);
            } else $this->repository->execute('INSERT INTO niveles_organizacionales (uuid,proceso,nombre,orden,activo,puede_reclutar,permite_mismo_nivel,editado_por,fecha) VALUES (?,?,?,?,?,?,?,?,NOW())',[$levelUuid,$process['id'],$name,$order,$active,$recruit,$same,Auth::id()]);
            $structures = $this->repository->rows('SELECT c.* FROM estructuras_organizacionales c JOIN estructuras_procesos ep ON ep.estructura=c.id WHERE ep.proceso=? AND ep.activo=1',[$process['id']]);
            $service = new StructureService(new StructureRepository($this->repository->connection()));
            foreach ($structures as $container) {
                // Un orden electoral no altera la jerarquía física compartida; se valida su proyección.
                $service->validateHierarchy((int) $container['id']);
                (new StructureRepository($this->repository->connection()))->history((int) $container['id'],null,'configuracion_nivel',$previous,['name'=>$name,'order'=>$order,'can_recruit'=>$recruit,'same_level'=>$same],(int) $process['id']);
            }
            $this->audit((int) $process['organizacion'],(int) $process['id'],'niveles_organizacionales',$previous ? (int) $previous['id'] : (int) $this->repository->rows('SELECT id FROM niveles_organizacionales WHERE uuid=?',[$levelUuid])[0]['id'],$previous,['nombre'=>$name,'orden'=>$order,'activo'=>$active,'puede_reclutar'=>$recruit,'permite_mismo_nivel'=>$same]);
            return ['id'=>$this->uuidBinaryToString($levelUuid)];
        });
    }
    public function configuration(?array $data = null): array
    {
        $process = $this->process($data['election'] ?? null); $this->authorize('niveles.administrar',(int) $process['id']);
        (new ModuleRepository($this->repository->connection()))->require('estructura',(int) $process['id']);
        if ($data !== null) return $this->transaction(function () use ($process,$data) {
            $this->authorize('niveles.administrar',(int) $process['id']);
            (new ModuleRepository($this->repository->connection()))->require('estructura',(int) $process['id']);
            $promotion = $this->normalizeStatus($data['auto_promote'] ?? 0); $approval = $this->normalizeStatus($data['approval_required'] ?? 1);
            $approver = null;
            if (!empty($data['approver'])) {
                $user = $this->repository->rows('SELECT id,organizacion FROM usuarios WHERE uuid=? AND activo=1',[$this->uuidStringToBinary((string) $data['approver'])])[0] ?? null;
                if (!$user || ($user['organizacion']!==null && (int) $user['organizacion']!==(int) $process['organizacion'])) throw new InvalidArgumentException('El aprobador no pertenece a la organización.');
                (new PermissionRepository($this->repository->connection()))->require((int) $user['id'],'estructura.aprobar_transferencias',(int) $process['id']); $approver = (int) $user['id'];
            }
            $beforeConfig=$this->repository->rows('SELECT promocion_primera_incorporacion,requiere_aprobacion,aprobador FROM procesos_estructura_config WHERE proceso=?',[$process['id']])[0] ?? null;
            $this->audit((int) $process['organizacion'],(int) $process['id'],'procesos_estructura_config',(int) $process['id'],$beforeConfig,['promocion_primera_incorporacion'=>$promotion,'requiere_aprobacion'=>$approval,'aprobador'=>$approver]);
            $this->repository->execute('INSERT INTO procesos_estructura_config (proceso,promocion_primera_incorporacion,requiere_aprobacion,aprobador,editado_por,fecha) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE promocion_primera_incorporacion=VALUES(promocion_primera_incorporacion),requiere_aprobacion=VALUES(requiere_aprobacion),aprobador=VALUES(aprobador),editado_por=VALUES(editado_por),fecha=NOW()',[$process['id'],$promotion,$approval,$approver,Auth::id()]);
            foreach ($this->repository->rows('SELECT estructura FROM estructuras_procesos WHERE proceso=? AND activo=1',[$process['id']]) as $container) (new StructureRepository($this->repository->connection()))->history((int) $container['estructura'],null,'configuracion_reglas',null,['auto_promote'=>$promotion,'approval_required'=>$approval,'approver'=>$approver],(int) $process['id']);
            return ['auto_promote'=>$promotion,'approval_required'=>$approval,'approver'=>$data['approver'] ?? null];
        });
        $config = $this->repository->rows('SELECT c.*,u.uuid aprobador_uuid FROM procesos_estructura_config c LEFT JOIN usuarios u ON u.id=c.aprobador WHERE c.proceso=?',[$process['id']])[0] ?? [];
        return ['auto_promote'=>(int) ($config['promocion_primera_incorporacion'] ?? 0),'approval_required'=>(int) ($config['requiere_aprobacion'] ?? 1),'approver'=>isset($config['aprobador_uuid']) ? $this->uuidBinaryToString($config['aprobador_uuid']) : null];
    }

    public function permissions(): array
    {
        $this->authorize('permisos.administrar',Auth::processId());
        return ['permissions'=>$this->repository->rows("SELECT id,permiso name,ambito scope,modulo module FROM permisos WHERE id<>'superadmin' ORDER BY id"),'scopes'=>PermissionRepository::SCOPES];
    }
    public function roles(): array
    {
        $this->authorize('permisos.administrar',Auth::processId());
        $organization=Auth::organizationId();
        if (Auth::isSuperAdmin() && Auth::processId()) $organization=$this->repository->rows('SELECT organizacion FROM procesos_electorales WHERE id=?',[Auth::processId()])[0]['organizacion'] ?? null;
        return $this->repository->rows("SELECT id,codigo code,tipo name,organizacion organization FROM usuarios_tipos WHERE codigo<>'superadmin' AND (organizacion IS NULL OR organizacion=?) ORDER BY tipo",[$organization]);
    }
    public function saveRole(?int $id, array $data): array
    {
        return $this->transaction(function () use ($id,$data) {
            $process = $this->process($data['election'] ?? null); $this->authorize('permisos.administrar',(int) $process['id']);
            $name = $this->normalizeRequiredText($data['name'] ?? null,'Indica el nombre del rol.');
            if (mb_strlen($name)>128) throw new InvalidArgumentException('Nombre demasiado largo.');
            if ($id !== null) {
                $role = $this->repository->rows("SELECT * FROM usuarios_tipos WHERE id=? AND codigo<>'superadmin'",[$id])[0] ?? null;
                if (!$role || (!Auth::isSuperAdmin() && ($role['organizacion']===null || (int) $role['organizacion']!==(int) $process['organizacion']))) throw new HttpError('El rol no puede ser modificado.',403);
                $this->repository->execute('UPDATE usuarios_tipos SET tipo=? WHERE id=?',[$name,$id]);
            } else {
                $this->repository->execute('INSERT INTO usuarios_tipos (codigo,tipo,organizacion) VALUES (?,?,?)',['r-' . bin2hex(random_bytes(8)),$name,$process['organizacion']]); $id = (int) $this->repository->connection()->lastInsertId();
            }
            $this->audit((int) $process['organizacion'],(int) $process['id'],'usuarios_tipos',$id,$role ?? null,['tipo'=>$name]);
            return ['id'=>$id];
        });
    }

    private function permissionChange(array $data): array
    {
        $global = ($data['context'] ?? 'electoral') === 'global';
        if ($global && !Auth::isSuperAdmin()) throw new HttpError('Sólo el Super Administrador configura permisos globales.',403);
        $process = $global ? null : $this->process($data['election'] ?? null);
        $effective = $this->authorize('permisos.administrar',$process ? (int) $process['id'] : null);
        $code = $this->normalizeRequiredText($data['permission'] ?? null,'Selecciona un permiso.');
        $permission = $this->repository->rows("SELECT * FROM permisos WHERE id=? AND id<>'superadmin'",[$code])[0] ?? null;
        if (!$permission || ($global && $permission['ambito']==='electoral') || (!$global && $permission['ambito']==='global')) throw new InvalidArgumentException('El permiso no admite este contexto.');
        $code = $permission['id'];
        $scope = $data['scope'] ?? 'propio';
        if (!in_array($scope,PermissionRepository::SCOPES,true)) throw new InvalidArgumentException('Alcance inválido.');
        $value = $this->normalizeStatus($data['value'] ?? null);
        if (!$effective['super']) {
            foreach (PermissionRepository::LEGACY[$code] ?? [$code] as $actual) {
                if (!in_array($actual,$effective['rights'],true)) throw new HttpError('No puedes delegar o configurar un permiso que no tienes.',403);
                $ownScopes = $effective['scopes'][$actual];
                // Las relaciones no son una escala total: sólo se acepta un alcance explícito o uno total.
                if (!in_array('organizacion',$ownScopes,true) && !in_array('eleccion',$ownScopes,true) && !in_array($scope,$ownScopes,true)) throw new HttpError('No puedes ampliar el alcance que tienes autorizado.',403);
                if ($scope==='organizacion' && !in_array('organizacion',$ownScopes,true)) throw new HttpError('No puedes conceder alcance de organización.',403);
            }
        }
        return [$process,$code,$scope,$value];
    }
    private function beforeCapacity(array $accounts): array
    {
        $before = [];
        foreach ($accounts as $account) {
            try { $effective = (new PermissionRepository($this->repository->connection()))->effective((int) $account['usuario'],(int) $account['proceso']); $before[$account['usuario'] . ':' . $account['proceso']] = in_array('estructura.formar',$effective['rights'],true) || $effective['super']; }
            catch (HttpError) { $before[$account['usuario'] . ':' . $account['proceso']] = false; }
        }
        return $before;
    }
    private function protectCapacity(array $accounts, array $before): void
    {
        $after = $this->beforeCapacity($accounts);
        foreach ($accounts as $account) if (($before[$account['usuario'] . ':' . $account['proceso']] ?? false) && !($after[$account['usuario'] . ':' . $account['proceso']] ?? false)) (new StructureService(new StructureRepository($this->repository->connection())))->withdrawCapacity((int) $account['usuario'],(int) $account['proceso']);
    }
    public function rolePermission(int $roleId, ?array $data = null): array
    {
        if ($data===null) {
            $process = $this->process(); $this->authorize('permisos.administrar',(int) $process['id']);
            $role = $this->repository->rows("SELECT id FROM usuarios_tipos WHERE id=? AND codigo<>'superadmin' AND (organizacion IS NULL OR organizacion=?)",[$roleId,$process['organizacion']]);
            if ($role===[]) throw new HttpError('El rol no está disponible.',404);
            return $this->repository->rows('SELECT permiso permission,valor value,alcance scope,proceso,f_actualizacion FROM permisos_usuarios_tipo WHERE tipo=? AND (proceso IS NULL OR proceso=?) ORDER BY permiso,proceso',[$roleId,$process['id']]);
        }
        return $this->transaction(function () use ($roleId,$data) {
            [$process,$code,$scope,$value] = $this->permissionChange($data);
            $role = $this->repository->rows("SELECT * FROM usuarios_tipos WHERE id=? AND codigo<>'superadmin'",[$roleId])[0] ?? null;
            if (!$role || ($role['organizacion']!==null && $process!==null && (int) $role['organizacion']!==(int) $process['organizacion'])) throw new HttpError('El rol no está disponible.',404);
            $accounts = $this->repository->rows('SELECT usuario,proceso FROM usuarios_procesos WHERE tipo_usuario=? AND activo=1 AND f_baja IS NULL' . ($process ? ' AND proceso=?' : ''),$process ? [$roleId,$process['id']] : [$roleId]); $before = $this->beforeCapacity($accounts);
            $processId = $process['id'] ?? null;
            $context = $process ? 'electoral' : 'global';
            $existing = $this->repository->rows('SELECT uuid,valor,alcance FROM permisos_usuarios_tipo WHERE tipo=? AND permiso=? AND proceso <=> ? AND contexto=?',[$roleId,$code,$processId,$context]);
            if ($existing!==[]) $this->repository->execute('UPDATE permisos_usuarios_tipo SET valor=?,alcance=?,editado_por=?,f_actualizacion=NOW() WHERE tipo=? AND permiso=? AND proceso <=> ? AND contexto=?',[$value,$scope,Auth::id(),$roleId,$code,$processId,$context]);
            else $this->repository->execute('INSERT INTO permisos_usuarios_tipo (uuid,tipo,permiso,proceso,valor,alcance,editado_por,contexto,f_actualizacion) VALUES (?,?,?,?,?,?,?,?,NOW())',[$this->generateUuidBinary(),$roleId,$code,$processId,$value,$scope,Auth::id(),$context]);
            $this->protectCapacity($accounts,$before);
            $this->audit($process ? (int) $process['organizacion'] : ($role['organizacion']===null ? null : (int) $role['organizacion']),$processId===null ? null : (int) $processId,'permisos_usuarios_tipo',$roleId,$existing[0] ?? null,['permiso'=>$code,'valor'=>$value,'alcance'=>$scope]);
            return ['saved'=>true];
        });
    }
    public function individual(string $uuid, ?array $data = null): array
    {
        $user = $this->repository->rows('SELECT id,organizacion FROM usuarios WHERE uuid=?',[$this->uuidStringToBinary($uuid)])[0] ?? null;
        if (!$user) throw new HttpError('La cuenta no está disponible.',404);
        if ($user['organizacion']!==null) Authorization::assertOrganization((int) $user['organizacion']);
        elseif (!Auth::isSuperAdmin()) throw new HttpError('La cuenta no está disponible.',404);
        (new \App\Repositories\AccountScopeRepository($this->repository->connection()))->assertUser((int) $user['id'],'permisos.administrar',Auth::processId());
        if ($data===null) {
            $process = $this->process(); $this->authorize('permisos.administrar',(int) $process['id']);
            return $this->repository->rows('SELECT permiso permission,valor value,alcance scope,proceso,f_actualizacion,editado_por FROM permisos_usuarios WHERE usuario=? AND (proceso IS NULL OR proceso=?) ORDER BY permiso',[$user['id'],$process['id']]);
        }
        return $this->transaction(function () use ($user,$data) {
            [$process,$code,$scope,$value] = $this->permissionChange($data);
            if ($process && (int) $user['organizacion']!==(int) $process['organizacion']) throw new InvalidArgumentException('La cuenta y la elección pertenecen a organizaciones distintas.');
            $accounts = $this->repository->rows('SELECT usuario,proceso FROM usuarios_procesos WHERE usuario=? AND activo=1 AND f_baja IS NULL' . ($process ? ' AND proceso=?' : ''),$process ? [$user['id'],$process['id']] : [$user['id']]); $before = $this->beforeCapacity($accounts);
            $processId = $process['id'] ?? null;
            $existing = $this->repository->rows('SELECT uuid,valor,alcance FROM permisos_usuarios WHERE usuario=? AND permiso=? AND proceso <=> ?',[$user['id'],$code,$processId]);
            if ($existing!==[]) $this->repository->execute('UPDATE permisos_usuarios SET valor=?,alcance=?,editado_por=?,f_actualizacion=NOW() WHERE usuario=? AND permiso=? AND proceso <=> ?',[$value,$scope,Auth::id(),$user['id'],$code,$processId]);
            else $this->repository->execute('INSERT INTO permisos_usuarios (uuid,usuario,organizacion,permiso,proceso,valor,alcance,editado_por,f_actualizacion) VALUES (?,?,?,?,?,?,?,?,NOW())',[$this->generateUuidBinary(),$user['id'],$user['organizacion'],$code,$processId,$value,$scope,Auth::id()]);
            $this->protectCapacity($accounts,$before);
            $this->audit($user['organizacion']===null ? null : (int) $user['organizacion'],$processId===null ? null : (int) $processId,'permisos_usuarios',(int) $user['id'],$existing[0] ?? null,['permiso'=>$code,'valor'=>$value,'alcance'=>$scope]);
            return ['saved'=>true];
        });
    }
    public function territories(string $uuid, ?array $data = null): array
    {
        $process = $this->process($data['election'] ?? null); $this->authorize('permisos.administrar',(int) $process['id']);
        $user = $this->repository->rows('SELECT id FROM usuarios WHERE uuid=? AND organizacion=?',[$this->uuidStringToBinary($uuid),$process['organizacion']])[0] ?? null;
        if (!$user) throw new HttpError('La cuenta no está disponible.',404);
        (new \App\Repositories\AccountScopeRepository($this->repository->connection()))->assertUser((int) $user['id'],'permisos.administrar',(int) $process['id']);
        if ($data!==null) $this->transaction(function () use ($process,$user,$data) {
            $sections = $data['sections'] ?? null;
            if (!is_array($sections) || count($sections)>100) throw new InvalidArgumentException('Selecciona hasta cien secciones.');
            $ids = [];
            foreach ($sections as $uuid) {
                $section = $this->repository->rows('SELECT id FROM secciones_electorales WHERE uuid=?',[$this->uuidStringToBinary((string) $uuid)])[0] ?? null;
                if (!$section) throw new InvalidArgumentException('Sección inválida.'); $ids[] = (int) $section['id'];
            }
            $beforeTerritories=$this->repository->rows('SELECT seccion FROM usuarios_territorios WHERE usuario=? AND proceso=?',[$user['id'],$process['id']]);
            $this->audit((int) $process['organizacion'],(int) $process['id'],'usuarios_territorios',(int) $user['id'],$beforeTerritories,['secciones'=>$ids]);
            $this->repository->execute('DELETE FROM usuarios_territorios WHERE usuario=? AND proceso=?',[$user['id'],$process['id']]);
            foreach (array_unique($ids) as $id) $this->repository->execute('INSERT INTO usuarios_territorios VALUES (?,?,?,?,NOW())',[$user['id'],$process['id'],$id,Auth::id()]);
        });
        return array_map(fn ($row) => ['id'=>$this->uuidBinaryToString($row['uuid']),'section'=>$row['seccion']],$this->repository->rows('SELECT s.uuid,s.seccion FROM usuarios_territorios t JOIN secciones_electorales s ON s.id=t.seccion WHERE t.usuario=? AND t.proceso=? ORDER BY s.seccion',[$user['id'],$process['id']]));
    }

    public function modules(string $organizationUuid, ?string $processUuid = null): array
    {
        $organization = $this->repository->rows('SELECT id FROM organizaciones WHERE uuid=?',[$this->uuidStringToBinary($organizationUuid)])[0]['id'] ?? null;
        if ($organization===null) throw new HttpError('Organización no disponible.',404);
        Authorization::assertOrganization((int) $organization);
        $process = $processUuid ? (new ElectoralContextRepository($this->repository->connection()))->authorized($this->uuidStringToBinary($processUuid),(int) $organization) : null;
        $this->authorize('modulos.seleccionar',$process ? (int) $process['id'] : Auth::processId());
        return (new ModuleRepository($this->repository->connection()))->catalog((int) $organization,$process ? (int) $process['id'] : null);
    }
    public function saveModule(string $organizationUuid, string $module, array $data): void
    {
        $this->transaction(function () use ($organizationUuid,$module,$data) {
            $organization = $this->repository->rows('SELECT id FROM organizaciones WHERE uuid=?',[$this->uuidStringToBinary($organizationUuid)])[0]['id'] ?? null;
            if ($organization===null) throw new HttpError('Organización no disponible.',404);
            Authorization::assertOrganization((int) $organization);
            $process = !empty($data['election']) ? (new ElectoralContextRepository($this->repository->connection()))->authorized($this->uuidStringToBinary((string) $data['election']),(int) $organization) : null;
            $this->authorize('modulos.seleccionar',$process ? (int) $process['id'] : Auth::processId());
            if (!$process && !Auth::isSuperAdmin()) {
                $effective = (new PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,'modulos.seleccionar',Auth::processId());
                if (!in_array('organizacion',$effective['scopes']['modulos.seleccionar'],true)) throw new HttpError('La selección para toda la organización requiere ese alcance.',403);
                foreach ($this->repository->rows('SELECT id FROM procesos_electorales WHERE organizacion=? AND estatus=1',[$organization]) as $election) $this->authorize('modulos.seleccionar',(int) $election['id']);
            }
            $catalog = (new ModuleRepository($this->repository->connection()))->catalog((int) $organization,$process ? (int) $process['id'] : null);
            $definition = array_values(array_filter($catalog,static fn ($row) => $row['codigo']===$module))[0] ?? null;
            if (!$definition) throw new InvalidArgumentException('Módulo inválido.');
            if ((int) $definition['esencial']===1) throw new InvalidArgumentException('Los componentes esenciales permanecen habilitados.');
            if (array_key_exists('authorized',$data) && !Auth::isSuperAdmin()) throw new HttpError('Sólo el Super Administrador autoriza módulos.',403);
            $authorized = array_key_exists('authorized',$data) ? $this->normalizeStatus($data['authorized']) : (int) $definition[$process ? 'autorizacion_eleccion' : 'autorizacion_organizacion'];
            $selected = array_key_exists('selected',$data) ? $this->normalizeStatus($data['selected']) : (int) $definition[$process ? 'seleccion_eleccion' : 'seleccion_organizacion'];
            if ($selected && (!$authorized || ($process && !(int) $definition['autorizacion_organizacion']))) {
                if (!array_key_exists('selected',$data)) $selected = 0;
                else throw new HttpError('El módulo no está autorizado para este ámbito.',403);
            }
            $this->audit((int) $organization,$process ? (int) $process['id'] : null,'modulos',(int) ($process['id'] ?? $organization),$definition,['modulo'=>$module,'autorizado'=>$authorized,'seleccionado'=>$selected]);
            $table = $process ? 'modulos_procesos' : 'modulos_organizaciones'; $column = $process ? 'proceso' : 'organizacion';
            $this->repository->execute("INSERT INTO $table ($column,modulo,autorizado,seleccionado,autorizado_por,seleccionado_por,fecha) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE autorizado=VALUES(autorizado),seleccionado=VALUES(seleccionado),autorizado_por=COALESCE(VALUES(autorizado_por),autorizado_por),seleccionado_por=COALESCE(VALUES(seleccionado_por),seleccionado_por),fecha=NOW()",[$process['id'] ?? $organization,$module,$authorized,$selected,array_key_exists('authorized',$data) ? Auth::id() : null,array_key_exists('selected',$data) ? Auth::id() : null]);
        });
    }
}
