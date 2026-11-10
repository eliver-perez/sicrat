<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\{Auth,HttpError};
use App\Repositories\{CommonRepository,ModuleRepository,OperationsRepository,PermissionRepository,ScopeRepository};
use App\Services\Operations\{Catalog,Context};
use App\Services\Common\{ResourceRegistry,Values};

/** Aggregates only. Source modules retain ownership of permission and row-scope rules. */
final class Summary
{
    private array $effective;
    private array $modules=[];
    private array $kpis=[];
    private array $charts=[];
    private array $pending=[];
    private array $sources=[];
    private bool $pendingAvailable=false;
    private int $actor;
    private int $organization;
    private ?int $process;
    private string $since;
    private string $until;
    private Context $operations;
    public function __construct(private CommonRepository $repository)
    {
        $this->actor=Auth::id() ?? throw new HttpError('No autenticado.',401);
        $this->process=Auth::processId();
        $this->operations=new Context(new OperationsRepository($repository->connection()));
    }
    private function allowed(string $permission): bool { return $this->effective['super'] || in_array($permission,$this->effective['rights'],true); }
    private function enabled(string $module): bool { return $this->modules[$module] ?? false; }
    private function scope(string $permission): array { return (new ScopeRepository($this->repository->connection()))->predicate($permission,$this->process); }
    private function kpi(string $key,string $label,int $value,string $detail,string $icon): void { $this->kpis[]=compact('key','label','value','detail','icon'); }
    private function chart(string $key,string $title,array $rows,string $type='bar'): void
    {
        if (array_sum(array_column($rows,'value'))>0) $this->charts[]=compact('key','title','rows','type');
    }
    private function series(array $rows,int $days): array
    {
        $values=array_column($rows,'value','label'); $result=[];
        for($i=$days-1;$i>=0;$i--) { $date=date('Y-m-d',strtotime('-'.$i.' days')); $result[]=['label'=>$date,'value'=>(int)($values[$date] ?? 0)]; }
        return $result;
    }
    private function personElection(): string
    {
        return '(EXISTS(SELECT 1 FROM personas_procesos pp WHERE pp.persona=p.id AND pp.proceso='.$this->process.' AND pp.activo=1) OR EXISTS(SELECT 1 FROM estructura_integrantes_procesos ep WHERE ep.persona=p.id AND ep.proceso='.$this->process.' AND ep.activo=1))';
    }
    public function get(int $days=7): array
    {
        return PermissionRepository::duringRead(fn()=>$this->calculate($days));
    }
    private function calculate(int $days): array
    {
        $this->effective=(new PermissionRepository($this->repository->connection()))->effective($this->actor,$this->process);
        $this->kpis=$this->charts=$this->pending=$this->sources=[];
        $this->pendingAvailable=false;
        if(!in_array($days,[7,15,30],true)) throw new \InvalidArgumentException('Periodo inválido.');
        $this->since=date('Y-m-d',strtotime('-'.($days-1).' days')).' 00:00:00';
        $this->until=date('Y-m-d',strtotime('+1 day')).' 00:00:00';
        $election=null; $message='';
        if($this->process===null) {
            $message='No tienes una elección de trabajo disponible. Tus notas personales siguen disponibles.';
            if($this->effective['super']) {
                $message='Vista administrativa global. Selecciona una elección para ver su operación.';
                $totals=$this->repository->one('SELECT (SELECT COUNT(*) FROM organizaciones) organizaciones,(SELECT COUNT(*) FROM procesos_electorales WHERE estatus=1) elecciones,(SELECT COUNT(*) FROM usuarios WHERE activo=1) usuarios');
                foreach(['organizaciones'=>['Organizaciones','building'],'elecciones'=>['Elecciones activas','vote'],'usuarios'=>['Cuentas activas','users']] as $key=>[$label,$icon]) $this->kpi($key,$label,(int)$totals[$key],'Ámbito global',$icon);
            }
        } else {
            $election=$this->repository->one('SELECT organizacion,proceso FROM procesos_electorales WHERE id=? AND estatus=1',[$this->process]) ?? throw new HttpError('Elección no disponible.',404);
            $this->organization=(int)$election['organizacion'];
            if(!$this->effective['super'] && $this->organization!==(int)$this->effective['user']['organizacion']) throw new HttpError('Elección no disponible.',404);
            foreach((new ModuleRepository($this->repository->connection()))->catalog($this->organization,$this->process) as $module) $this->modules[$module['codigo']]=$module['habilitado'] && (bool)$module['implementado'];
            $this->people(); $this->structure($days);
            foreach(['tareas','incidencias','peticiones','interacciones'] as $entity) $this->operational($entity,$days);
            $this->jornada(); $this->approvals();
        }
        $activity=$this->process===null ? [] : $this->activity($days);
        // Relevance order, independent of role names. Show at most three charts.
        $priority=['jornada'=>0,'actividad'=>1,'estructura'=>2,'tareas'=>3,'incidencias'=>4,'visitas'=>5,'peticiones'=>6];
        usort($this->charts,fn($a,$b)=>($priority[$a['key']] ?? 9)<=>($priority[$b['key']] ?? 9));
        usort($this->pending,static fn($a,$b)=>[$a['rank'],$a['deadline'] ?? '9999',$a['title']]<=>[$b['rank'],$b['deadline'] ?? '9999',$b['title']]);
        foreach($this->pending as &$item) unset($item['rank']); unset($item);
        // Operational urgency comes first when more than six indicators are available.
        $order=['jornada'=>0,'tareas'=>1,'incidencias'=>2,'personas'=>3,'estructura'=>4,'peticiones'=>5,'visitas'=>6];
        usort($this->kpis,fn($a,$b)=>($order[$a['key']] ?? 9)<=>($order[$b['key']] ?? 9));
        return ['election'=>$election['proceso'] ?? null,'message'=>$message,'days'=>$days,'kpis'=>array_slice($this->kpis,0,6),'charts'=>array_slice($this->charts,0,3),'activity_available'=>(bool)$this->sources,'pending_available'=>$this->pendingAvailable,'activity'=>$activity,'pending'=>array_slice($this->pending,0,12)];
    }
    private function people(): void
    {
        if(!$this->allowed('personas.ver')) return;
        [$where,$params]=$this->scope('personas.ver');
        $where.=' AND p.organizacion=? AND '.$this->personElection(); $params[]=$this->organization;
        $value=$this->repository->one("SELECT COUNT(*) total FROM personas p WHERE $where",$params);
        $this->kpi('personas','Personas en mi ámbito',(int)$value['total'],'Participantes en esta elección','users');
        $this->sources[]=["a.entidad='personas' AND EXISTS(SELECT 1 FROM personas p WHERE p.id=a.entidad_id AND ($where))",$params];
    }
    private function structure(int $days): void
    {
        if(!$this->allowed('estructura.ver') || !$this->enabled('estructura')) return;
        [$scope,$params]=$this->scope('estructura.ver');
        $from='FROM estructura_integrantes_procesos ep JOIN personas p ON p.id=ep.persona JOIN estructura e ON e.id=ep.integrante JOIN estructuras_organizacionales c ON c.id=e.contenedor JOIN estructuras_procesos cp ON cp.estructura=c.id AND cp.proceso=ep.proceso';
        $where="ep.proceso=? AND ep.activo=1 AND e.activo=1 AND c.activo=1 AND cp.activo=1 AND c.organizacion=? AND ($scope)"; $params=[$this->process,$this->organization,...$params];
        $row=$this->repository->one("SELECT COUNT(*) total,SUM(ep.fecha>=? AND ep.fecha<?) nuevos $from WHERE $where",[$this->since,$this->until,...$params]);
        $this->kpi('estructura','Mi estructura',(int)$row['total'],(int)$row['nuevos'].' incorporaciones en '.$days.' días','network');
        $rows=$this->repository->rows("SELECT DATE(ep.fecha) label,COUNT(*) value $from WHERE $where AND ep.fecha>=? AND ep.fecha<? GROUP BY DATE(ep.fecha) ORDER BY label",[...$params,$this->since,$this->until]);
        $this->chart('estructura','Incorporaciones a mi estructura',$this->series($rows,$days),'area');
        // Only election-specific member events; container events cannot safely identify an authorized person.
        $this->sources[]=["a.entidad='estructura' AND EXISTS(SELECT 1 $from WHERE ep.integrante=a.entidad_id AND ($where))",$params];
    }
    private function operational(string $entity,int $days): void
    {
        $definition=Catalog::entity($entity);
        if(!$this->allowed($entity.'.ver') || !$this->enabled($definition['module'])) return;
        [$where,$params]=$this->operations->predicate($entity,$this->process,$entity.'.ver');
        $this->sources[]=["a.entidad='$entity' AND EXISTS(SELECT 1 FROM $entity r WHERE r.id=a.entidad_id AND ($where))",$params];
        if($entity==='interacciones') {
            $from="FROM interacciones r JOIN tipos_interaccion ti ON ti.id=r.tipo WHERE ($where) AND ti.codigo='VISITA' AND r.f_interaccion>=? AND r.f_interaccion<?";
            $rows=$this->repository->rows("SELECT DATE(r.f_interaccion) label,COUNT(*) value $from GROUP BY DATE(r.f_interaccion) ORDER BY label",[...$params,$this->since,$this->until]);
            $this->kpi('visitas','Visitas realizadas',(int)array_sum(array_column($rows,'value')),'Últimos '.$days.' días','map-pin');
            $this->chart('visitas','Visitas realizadas',$this->series($rows,$days),'area'); return;
        }
        $states=$this->repository->rows("SELECT r.estado label,COUNT(*) value FROM $entity r WHERE $where GROUP BY r.estado",$params);
        $closed=$entity==='tareas' ? ['completada','cancelada'] : ['cerrada','cancelada']; $total=0;
        foreach($states as &$state) { $state['value']=(int)$state['value']; if(!in_array($state['label'],$closed,true)) $total+=$state['value']; $state['label']=self::stateLabel($state['label']); } unset($state);
        $this->kpi($entity,match($entity){'tareas'=>'Tareas pendientes','incidencias'=>'Incidencias abiertas',default=>'Peticiones abiertas'},$total,'En tu ámbito autorizado',$entity==='tareas' ? 'clipboard-check' : 'inbox');
        $this->chart($entity,$definition['title'].' por estado',$states);
        $permission=$entity=== 'tareas' ? 'tareas.completar' : $entity.'.editar';
        if(!$this->allowed($permission)) return;
        $this->pendingAvailable=true;
        [$action,$actionParams]=$this->operations->predicate($entity,$this->process,$permission);
        $person=(int)($this->effective['user']['persona'] ?? 0);
        if($entity==='tareas') {
            [$itemScope,$itemParams]=$this->scope('tareas.completar');
            $mine="(EXISTS(SELECT 1 FROM tareas_asignaciones mine WHERE mine.tarea=r.id AND (mine.persona=? OR mine.usuario_historico=?) AND (mine.persona IS NULL OR EXISTS(SELECT 1 FROM personas p WHERE p.id=mine.persona AND ($itemScope))) AND mine.estado IN ('pendiente','en_proceso','correccion')) OR EXISTS(SELECT 1 FROM tareas_subtareas mine WHERE mine.tarea=r.id AND mine.persona=? AND EXISTS(SELECT 1 FROM personas p WHERE p.id=mine.persona AND ($itemScope)) AND mine.estado IN ('pendiente','en_proceso','correccion')))";
            $mineParams=[$person,$this->actor,...$itemParams,$person,...$itemParams]; $deadline='r.fecha_limite'; $title='r.titulo';
        } else { $mine=$entity==='incidencias' ? '(r.responsable=? OR (r.responsable IS NULL AND r.asignado_a=?))' : 'r.responsable=?'; $mineParams=$entity==='incidencias' ? [$person,$this->actor] : [$person]; $deadline='NULL'; $title='r.descripcion'; }
        $inactive=$entity==='tareas' ? "('completada','cancelada')" : "('cerrada','cancelada','validacion')";
        $rows=$this->repository->rows("SELECT r.uuid,$title title,$deadline deadline,pr.codigo priority FROM $entity r LEFT JOIN tareas_prioridades pr ON pr.id=r.prioridad WHERE ($where) AND ($action) AND ($mine) AND r.estado NOT IN $inactive ORDER BY (COALESCE(pr.codigo='URGENTE',0) OR COALESCE($deadline<?,0)) DESC,$deadline IS NULL,$deadline,r.id LIMIT 12",[...$params,...$actionParams,...$mineParams,date('Y-m-d H:i:s')]);
        foreach($rows as $row) $this->addPending($definition['title'],$row['title'],'operations/?module='.$entity.'&record='.Values::uuidText($row['uuid']),$row['deadline'],$row['priority']==='URGENTE');
    }
    private static function stateLabel(string $state): string
    {
        return ['pendiente'=>'Pendientes','en_proceso'=>'En proceso','validacion'=>'En validación','completada'=>'Completadas','cancelada'=>'Canceladas','abierta'=>'Abiertas','en_atencion'=>'En atención','correccion'=>'En corrección','cerrada'=>'Cerradas'][$state] ?? 'Otros';
    }
    private function addPending(string $module,string $title,string $url,?string $deadline=null,bool $urgent=false): void
    {
        $expired=$deadline!==null && $deadline<date('Y-m-d H:i:s');
        $this->pending[]=['module'=>$module,'title'=>mb_substr($title,0,180),'url'=>$url,'deadline'=>$deadline===null ? null : (new \DateTimeImmutable($deadline))->format(DATE_ATOM),'urgent'=>$urgent || $expired,'rank'=>$urgent || $expired ? 0 : ($deadline ? 1 : 2)];
    }
    private function jornada(): void
    {
        if(!$this->enabled('jornada_electoral')) return;
        $queries=new \App\Services\Jornada\Queries($this->repository);
        try { $permission=$queries->permission(); } catch(HttpError) { return; }
        [$where,$params]=$queries->context->predicate($permission);
        $this->sources[]=["a.entidad='jornada_versiones' AND EXISTS(SELECT 1 FROM jornada_versiones v JOIN jornada_resultados jr ON jr.id=v.resultado JOIN jornada_casillas jc ON jc.id=jr.casilla WHERE v.id=a.entidad_id AND ($where))",$params];
        if($this->allowed('resultados.validar')) {
            $this->pendingAvailable=true;
            [$validate,$validateParams]=$queries->context->predicate('resultados.validar');
            $count=$this->repository->one('SELECT COUNT(*) total '.$queries->joins()." WHERE ($where) AND ($validate) AND (current_v.estado='preliminar' OR EXISTS(SELECT 1 FROM jornada_versiones proposed WHERE proposed.resultado=r.id AND proposed.estado='pendiente'))",[...$params,...$validateParams]);
            if($count['total']) $this->addPending('Jornada',(int)$count['total'].' casillas con capturas por validar','jornada/');
        }
        $row=$this->repository->one("SELECT COUNT(*) total,SUM(COALESCE(r.consecutivo,0)=0) pendientes,SUM(COALESCE(r.consecutivo,0)>0) capturadas,SUM(current_v.estado='validada') validadas ".$queries->joins()." WHERE ($where) AND jc.activo=1",$params);
        $this->kpi('jornada','Casillas capturadas',(int)$row['capturadas'],'De '.(int)$row['total'].' casillas en tu ámbito','vote');
        $this->chart('jornada','Avance de Jornada',[
            ['label'=>'Sin captura','value'=>(int)$row['pendientes']],
            ['label'=>'Capturadas por validar','value'=>(int)$row['capturadas']-(int)$row['validadas']],
            ['label'=>'Validadas','value'=>(int)$row['validadas']]
        ]);
        if($this->allowed('resultados.capturar')) {
            $this->pendingAvailable=true;
            [$action,$actionParams]=$queries->context->predicate('resultados.capturar');
            $config=$this->repository->one("SELECT proceso FROM jornada_config WHERE proceso=? AND estado='abierta' AND captura_desde<=UTC_TIMESTAMP()",[$this->process]);
            if($config) {
                $count=$this->repository->one('SELECT COUNT(*) total '.$queries->joins()." WHERE ($where) AND ($action) AND jc.activo=1 AND COALESCE(r.consecutivo,0)=0 AND EXISTS(SELECT 1 FROM jornada_capturistas mine WHERE mine.casilla=jc.id AND mine.usuario=? AND mine.activo=1)",[...$params,...$actionParams,$this->actor]);
                if($count['total']) $this->addPending('Jornada',(int)$count['total'].' casillas asignadas sin captura','jornada/?mis=1');
            }
        }
    }
    private function approvals(): void
    {
        if(!$this->allowed('aprobaciones.ver') || !$this->allowed('aprobaciones.resolver')) return;
        $this->pendingAvailable=true;
        // Only source modules available here. Registry filters intersect read and resolve scopes.
        $entities=$this->allowed('personas.ver') ? ['personas'] : [];
        foreach(['interacciones','encuestas','tareas','metas','eventos','peticiones','incidencias'] as $entity) {
            $definition=Catalog::entity($entity);
            $validate=$entity==='encuestas' ? 'encuestas.aprobar' : $entity.'.validar';
            if($this->enabled($definition['module']) && $this->allowed($entity.'.ver') && $this->allowed($validate)) {
                $entities[]=$entity;
                if($entity==='tareas') $entities=[...$entities,'tareas_asignaciones','tareas_subtareas'];
            }
        }
        if(!$entities) return;
        $registry=new ResourceRegistry($this->repository);
        [$where,$params]=$registry->listingPredicate($this->process,$this->actor,'aprobaciones.resolver',$entities);
        [$read,$readParams]=$registry->listingPredicate($this->process,$this->actor,'aprobaciones.ver',$entities);
        $where="($where) AND ($read)"; $params=[...$params,...$readParams];
        if(in_array('tareas_asignaciones',$entities,true)) {
            // The task item is separately authorized by TaskService::authorizeItem.
            [$task,$taskParams]=$this->operations->predicate('tareas',$this->process,'tareas.validar');
            $itemFilters=[]; $allItemParams=[];
            foreach(['tareas_asignaciones','tareas_subtareas'] as $table) {
                $itemScopes=[]; $itemParams=[];
                foreach(['tareas.ver','tareas.validar','aprobaciones.ver','aprobaciones.resolver'] as $permission) {
                    [$personScope,$personParams]=$this->scope($permission);
                    $broad=$this->effective['super'] || array_intersect(['eleccion','organizacion'],$this->effective['scopes'][$permission] ?? []);
                    $historical=$table==='tareas_asignaciones' ? 'item.usuario_historico='.$this->actor : '0=1';
                    $nullScope=$broad ? '1=1' : 'r.creado_por='.$this->actor.' OR '.$historical;
                    $itemScopes[]="((item.persona IS NULL AND ($nullScope)) OR EXISTS(SELECT 1 FROM personas p WHERE p.id=item.persona AND ($personScope)))";
                    $itemParams=[...$itemParams,...$personParams];
                }
                $itemFilters[]="(a.entidad='$table' AND EXISTS(SELECT 1 FROM $table item JOIN tareas r ON r.id=item.tarea WHERE item.id=a.entidad_id AND ($task) AND ".implode(' AND ',$itemScopes).'))';
                $allItemParams=[...$allItemParams,...$taskParams,...$itemParams];
            }
            $where.=" AND (a.entidad NOT IN ('tareas_asignaciones','tareas_subtareas') OR ".implode(' OR ',$itemFilters).')';
            $params=[...$params,...$allItemParams];
        }
        $rows=$this->repository->rows("SELECT a.uuid,a.vence_en FROM aprobaciones a WHERE a.organizacion=? AND a.proceso=? AND a.estado='pendiente' AND (a.vence_en IS NULL OR a.vence_en>?) AND EXISTS(SELECT 1 FROM aprobaciones_pasos step WHERE step.aprobacion=a.id AND step.aprobador=? AND step.estado='pendiente' AND NOT EXISTS(SELECT 1 FROM aprobaciones_pasos prev WHERE prev.aprobacion=a.id AND prev.orden<step.orden AND prev.estado='pendiente')) AND ($where) ORDER BY a.vence_en IS NULL,a.vence_en,a.id LIMIT 12",[$this->organization,$this->process,date('Y-m-d H:i:s'),$this->actor,...$params]);
        foreach($rows as $row) $this->addPending('Validaciones','Solicitud pendiente de tu aprobación','services/',$row['vence_en']);
    }
    private function activity(int $days): array
    {
        if(!$this->sources) return [];
        $parts=[]; $params=[];
        foreach($this->sources as [$sql,$values]) { $parts[]='('.$sql.')'; $params=[...$params,...$values]; }
        $actor='a.usuario=?'; $actorParams=[$this->actor];
        if($this->enabled('estructura') && $this->allowed('estructura.ver')) {
            [$scope,$values]=$this->scope('estructura.ver');
            $actor.=" OR EXISTS(SELECT 1 FROM usuarios team JOIN personas p ON p.id=team.persona JOIN estructura_integrantes_procesos ep ON ep.persona=p.id AND ep.proceso=? AND ep.activo=1 WHERE team.id=a.usuario AND team.organizacion=? AND ($scope))";
            $actorParams=[...$actorParams,$this->process,$this->organization,...$values];
        }
        $where="a.organizacion=? AND (a.proceso=? OR (a.proceso IS NULL AND a.entidad='estructura' AND a.accion IN ('alta','baja','reactivacion','cambio_responsable','transferencia_equipo','transferencia_individual','aprobacion_transferencia','rechazo_transferencia','clonacion_integrante') AND JSON_CONTAINS(a.informacion,?, '$.elecciones'))) AND (".implode(' OR ',$parts).') AND ('.$actor.')';
        $params=[$this->organization,$this->process,(string)$this->process,...$params,...$actorParams];
        $rows=$this->repository->rows("SELECT a.usuario,a.accion,a.entidad,a.f_registro,u.nombre FROM auditoria a JOIN usuarios u ON u.id=a.usuario WHERE $where ORDER BY a.f_registro DESC,a.id DESC LIMIT 12",$params);
        $series=$this->repository->rows("SELECT DATE(a.f_registro) label,COUNT(*) value FROM auditoria a WHERE $where AND a.f_registro>=? AND a.f_registro<? GROUP BY DATE(a.f_registro) ORDER BY label",[...$params,$this->since,$this->until]);
        $this->chart('actividad','Actividad por día',$this->series($series,$days),'area');
        $modules=['personas'=>'Personas','estructura'=>'Estructura','tareas'=>'Tareas','incidencias'=>'Incidencias','peticiones'=>'Peticiones','interacciones'=>'Interacciones','jornada_versiones'=>'Jornada'];
        $actions=['capturar'=>'Registró una captura','corregir'=>'Propuso una corrección','validar'=>'Validó una captura','rechazar'=>'Rechazó una captura','participacion'=>'Actualizó una participación','crear'=>'Registró información','creacion'=>'Registró información','alta'=>'Registró información','editar'=>'Actualizó información','actualizar'=>'Actualizó información','actualizar_estado'=>'Actualizó el estado','cerrar'=>'Cerró un registro','solicitar_cierre'=>'Solicitó un cierre','reasignar'=>'Reasignó un registro','cancelar'=>'Canceló un registro','registrar'=>'Registró información'];
        return array_map(fn($row)=>['actor'=>(int)$row['usuario']===$this->actor ? 'Tú' : $row['nombre'],'action'=>$actions[$row['accion']] ?? 'Registró un movimiento','module'=>$modules[$row['entidad']],'date'=>$row['f_registro']],$rows);
    }
}
