
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
                    </ul>
        
                </div>
            </div>