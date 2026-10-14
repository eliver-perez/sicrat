<?php
$can=static fn(array $rights)=>$session->verifyUserRights($rights);
$process=\App\Core\Auth::processId();
$groups=['Administración'=>[['/','Dashboard','monitor-dot']],'Estructura'=>[],'Operación'=>[],'Recursos'=>[],'Jornada'=>[],'Ajustes'=>[]];
if($can(['superadmin']))$groups['Administración']=[...$groups['Administración'],['/organizations','Organizaciones','building'],['/electoral-process','Procesos Electorales','circuit-board']];
if($can(['secciones.ver','secciones.administrar']))$groups['Administración'][]=['/sections','Secciones','columns-3'];
if($can(['casillas.ver','casillas.administrar']))$groups['Administración'][]=['/polling-booth','Casillas','home'];
if($can(['personas.ver']))$groups['Estructura'][]=['/persons','Personas','user-round'];
if($can(['usuarios.administrar']))$groups['Estructura'][]=['/users','Usuarios','users'];
if($process){
    $db=(new \App\Core\Database())->getConnection();$modules=new \App\Repositories\ModuleRepository($db);
    try{if($can(['estructura.ver'])){$modules->require('estructura',$process);$groups['Estructura'][]=['/structure','Estructura organizacional','network'];}}catch(\App\Core\HttpError){}
    foreach(['interacciones'=>['Interacciones','phone'],'encuestas'=>['Encuestas','clipboard-list'],'tareas'=>['Tareas y metas','list-checks'],'eventos'=>['Eventos y brigadas','calendar-days'],'peticiones'=>['Peticiones e incidencias','clipboard'],'transporte'=>['Transporte y logística','bus'],'conversaciones'=>['Mensajería','messages-square']] as $entity=>[$name,$icon]){
        $definition=\App\Services\Operations\Catalog::entity($entity);$rights=$entity==='encuestas' ? ['encuestas.ver','encuestas.crear','encuestas.aplicar','encuestas.ver_resultados','encuestas.corregir','encuestas.administrar','encuestas.aprobar'] : [$definition['permission'].'.ver'];
        if($entity==='tareas')$rights[]='metas.ver';if($entity==='peticiones')$rights[]='incidencias.ver';if(!$can($rights))continue;
        try{$modules->require($definition['module'],$process);}catch(\App\Core\HttpError){continue;}
        if($entity==='tareas' && !$can(['tareas.ver']))$entity='metas';if($entity==='peticiones' && !$can(['peticiones.ver']))$entity='incidencias';
        $groups['Operación'][]=['/operations/?module='.$entity,$name,$icon];
    }
    $organization=(int)$db->query('SELECT organizacion FROM procesos_electorales WHERE id='.(int)$process)->fetchColumn();$catalog=$modules->catalog($organization,$process);
    foreach(['finanzas'=>['Finanzas','wallet',['finanzas.ver','finanzas.presupuestos.ver','finanzas.ingresos.ver'],['gastos','presupuestos','anticipos','reembolsos','ingresos','aportaciones','pagos','cuentas','politicas']],'inventario'=>['Inventario','boxes',['inventario.ver'],['articulos','almacenes','movimientos']]] as $code=>[$name,$icon,$rights,$entities]){
        if(!$can($rights))continue;$module=array_values(array_filter($catalog,static fn($row)=>$row['codigo']===$code))[0] ?? null;if(!$module || !(int)$module['implementado'])continue;
        $historical=false;
        if(!$module['habilitado']){$context=new \App\Services\Resources\Context(new \App\Repositories\ResourcesRepository($db));foreach($entities as $entity){$definition=\App\Services\Resources\Catalog::entity($entity);try{[$where,$parameters]=$context->predicate($entity,$definition['read'],$process);$statement=$db->prepare('SELECT r.id FROM '.$definition['table'].' r WHERE '.$where.' LIMIT 1');$statement->execute($parameters);if($statement->fetchColumn()!==false){$historical=true;break;}}catch(\App\Core\HttpError){}}}
        if($module['habilitado'] || $historical)$groups['Recursos'][]=['/resources/?module='.$code,$name.($historical ? ' · Historial' : ''),$icon];
    }
    if($can(['resultados.ver','resultados.capturar','resultados.validar','jornada.configurar','jornada.asignar_capturistas'])){try{$query=new \App\Services\Jornada\Queries(new \App\Repositories\CommonRepository($db));$visible=$query->context->enabled();$historical=false;if(!$visible){[$where,$parameters]=$query->context->predicate($query->permission());$statement=$db->prepare('SELECT jc.id FROM jornada_casillas jc JOIN jornada_resultados r ON r.casilla=jc.id WHERE ('.$where.') AND r.consecutivo>0 LIMIT 1');$statement->execute($parameters);$visible=$historical=$statement->fetchColumn()!==false;}if($visible)$groups['Jornada'][]=['/jornada','Jornada Electoral'.($historical ? ' · Historial' : ''),'vote'];}catch(\App\Core\HttpError){}}
}
if($can(['modulos.seleccionar','permisos.administrar','niveles.administrar']))$groups['Ajustes'][]=['/administration','Configuración electoral','settings'];
$groups['Ajustes'][]=['/services','Servicios del sistema','bell'];
?>
<div class="relative min-h-0 flex-grow"><div class="size-full" data-simplebar><ul class="side-nav p-3 hs-accordion-group">
<?php foreach($groups as $title=>$items){if(!$items)continue; ?>
<li class="menu-title"><span><?= escape_html($title) ?></span></li>
<?php foreach($items as [$path,$name,$icon]){ ?>
<li class="menu-item"><a class="menu-link" href="<?= escape_html(base_url($path)) ?>"><span class="menu-icon"><i data-lucide="<?= escape_html($icon) ?>"></i></span><div class="menu-text"><?= escape_html($name) ?></div></a></li>
<?php }} ?>
</ul></div></div>
