
            <div class="relative min-h-0 flex-grow">
                <div class="size-full" data-simplebar>
                    <ul class="side-nav p-3 hs-accordion-group">
                        <li class="menu-title">
                            <span>Administración</span>
                        </li>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= escape_html(base_url('/')); ?>">
                                <span class="menu-icon"><i data-lucide="monitor-dot"></i></span>
                                <div class="menu-text">Dashboard</div>
                            </a>
                        </li>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>
                            
                        <li class="menu-item">
                            <a class="menu-link" href="<?= escape_html(base_url('/organizations')); ?>">
                                <span class="menu-icon"><i data-lucide="building"></i></span>
                                <div class="menu-text">Organizaciones</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= escape_html(base_url('/electoral-process')); ?>">
                                <span class="menu-icon"><i data-lucide="circuit-board"></i></span>
                                <div class="menu-text">Procesos Electorales</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>

                        <li class="menu-title">
                            <span>Secciones y Casillas</span>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= escape_html(base_url('/sections')); ?>">
                                <span class="menu-icon"><i data-lucide="columns-3"></i></span>
                                <div class="menu-text">Secciones</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= escape_html(base_url('/polling-booth')); ?>">
                                <span class="menu-icon"><i data-lucide="home"></i></span>
                                <div class="menu-text">Casillas</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['personas.ver'])) { ?>

                        <li class="menu-title">
                            <span>Estructura</span>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['personas.ver'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= escape_html(base_url('/persons')); ?>">
                                <span class="menu-icon"><i data-lucide="users"></i></span>
                                <div class="menu-text">Personas</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>

                        <li class="menu-title">
                            <span>Accesos</span>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['usuarios.administrar'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= escape_html(base_url('/users')); ?>">
                                <span class="menu-icon"><i data-lucide="users"></i></span>
                                <div class="menu-text">Usuarios</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
                        <?php
                            $structureEnabled = false;
                            if (\App\Core\Auth::processId() && $session->verifyUserRights(['estructura.ver'])) {
                                try { (new \App\Repositories\ModuleRepository((new \App\Core\Database())->getConnection()))->require('estructura', \App\Core\Auth::processId()); $structureEnabled = true; }
                                catch (\App\Core\HttpError) {}
                            }
                            if ($structureEnabled) { ?>
                        <li class="menu-item"><a class="menu-link" href="<?= escape_html(base_url('/structure')); ?>"><span class="menu-icon"><i data-lucide="network"></i></span><div class="menu-text">Estructura organizacional</div></a></li>
                        <?php } if ($session->verifyUserRights(['modulos.seleccionar', 'permisos.administrar', 'niveles.administrar'])) { ?>
                        <li class="menu-item"><a class="menu-link" href="<?= escape_html(base_url('/administration')); ?>"><span class="menu-icon"><i data-lucide="settings"></i></span><div class="menu-text">Configuración electoral</div></a></li>
                        <?php } ?>
                        <li class="menu-item"><a class="menu-link" href="<?= escape_html(base_url('/services')); ?>"><span class="menu-icon"><i data-lucide="bell"></i></span><div class="menu-text">Servicios y notificaciones</div></a></li>
                        <?php
                            $resourceNavigation=[];
                            if (\App\Core\Auth::processId()) {
                                $resourceDb=(new \App\Core\Database())->getConnection();
                                $resourceOrganization=(int) $resourceDb->query('SELECT organizacion FROM procesos_electorales WHERE id='.(int) \App\Core\Auth::processId())->fetchColumn();
                                $resourceCatalog=(new \App\Repositories\ModuleRepository($resourceDb))->catalog($resourceOrganization,\App\Core\Auth::processId());
                                foreach (['finanzas'=>['Finanzas','wallet',['finanzas.ver','finanzas.presupuestos.ver','finanzas.ingresos.ver'],['gastos','presupuestos','anticipos','reembolsos','ingresos','aportaciones','pagos','cuentas','politicas']],'inventario'=>['Inventario','boxes',['inventario.ver'],['articulos','almacenes','movimientos']]] as $code=>[$name,$icon,$permissions,$entities]) {
                                    if (!$session->verifyUserRights($permissions)) continue;
                                    $module=array_values(array_filter($resourceCatalog,static fn($row)=>$row['codigo']===$code))[0] ?? null;
                                    if (!$module || !(int) $module['implementado']) continue;
                                    $historical=false;
                                    if (!$module['habilitado']) {
                                        $context=new \App\Services\Resources\Context(new \App\Repositories\ResourcesRepository($resourceDb));
                                        foreach ($entities as $entity) {
                                            $definition=\App\Services\Resources\Catalog::entity($entity);
                                            try {
                                                [$where,$params]=$context->predicate($entity,$definition['read'],\App\Core\Auth::processId());
                                                $check=$resourceDb->prepare('SELECT r.id FROM '.$definition['table'].' r WHERE '.$where.' LIMIT 1');
                                                $check->execute($params);
                                                if ($check->fetchColumn()!==false) { $historical=true; break; }
                                            } catch (\App\Core\HttpError) { }
                                        }
                                    }
                                    if ($module['habilitado'] || $historical) $resourceNavigation[]=[$code,$name.($historical ? ' · Historial' : ''),$icon];
                                }
                            }
                            foreach ($resourceNavigation as [$code,$name,$icon]) { ?>
                        <li class="menu-item"><a class="menu-link" href="<?= escape_html(base_url('/resources/?module='.$code)); ?>"><span class="menu-icon"><i data-lucide="<?= escape_html($icon); ?>"></i></span><div class="menu-text"><?= escape_html($name); ?></div></a></li>
                        <?php }
                            $operationNavigation=[];
                            if (\App\Core\Auth::processId()) {
                                $moduleRepository=new \App\Repositories\ModuleRepository((new \App\Core\Database())->getConnection());
                                foreach (['interacciones'=>['Interacciones','phone'],'encuestas'=>['Encuestas','clipboard-list'],'tareas'=>['Tareas y metas','list-checks'],'eventos'=>['Eventos y brigadas','calendar-days'],'peticiones'=>['Peticiones e incidencias','inbox'],'transporte'=>['Transporte y logística','bus'],'conversaciones'=>['Mensajería','messages-square']] as $entity=>[$name,$icon]) {
                                    $definition=\App\Services\Operations\Catalog::entity($entity);
                                    $rights=$entity==='encuestas' ? ['encuestas.ver','encuestas.crear','encuestas.aplicar','encuestas.ver_resultados','encuestas.corregir','encuestas.administrar','encuestas.aprobar'] : [$definition['permission'].'.ver'];
                                    if ($entity==='tareas') $rights[]='metas.ver';
                                    if ($entity==='peticiones') $rights[]='incidencias.ver';
                                    if (!$session->verifyUserRights($rights)) continue;
                                    try { $moduleRepository->require($definition['module'],\App\Core\Auth::processId()); }
                                    catch (\App\Core\HttpError) { continue; }
                                    if ($entity==='tareas' && !$session->verifyUserRights(['tareas.ver'])) $entity='metas';
                                    if ($entity==='peticiones' && !$session->verifyUserRights(['peticiones.ver'])) $entity='incidencias';
                                    $operationNavigation[]=[$entity,$name,$icon];
                                }
                            }
                            if ($operationNavigation) { ?>
                        <li class="menu-title"><span>Operación</span></li>
                        <?php foreach ($operationNavigation as [$entity,$name,$icon]) { ?>
                        <li class="menu-item"><a class="menu-link" href="<?= escape_html(base_url('/operations/?module='.$entity)); ?>"><span class="menu-icon"><i data-lucide="<?= escape_html($icon); ?>"></i></span><div class="menu-text"><?= escape_html($name); ?></div></a></li>
                        <?php } } ?>
                    </ul>
        
                </div>
            </div>
