
            <div class="relative min-h-0 flex-grow">
                <div class="size-full" data-simplebar>
                    <ul class="side-nav p-3 hs-accordion-group">
                        <li class="menu-title">
                            <span>Administración</span>
                        </li>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= base_url('/'); ?>">
                                <span class="menu-icon"><i data-lucide="monitor-dot"></i></span>
                                <div class="menu-text">Dashboard</div>
                            </a>
                        </li>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>
                            
                        <li class="menu-item">
                            <a class="menu-link" href="<?= base_url('/organizations'); ?>">
                                <span class="menu-icon"><i data-lucide="building"></i></span>
                                <div class="menu-text">Organizaciones</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= base_url('/electoral-process'); ?>">
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
                            <a class="menu-link" href="<?= base_url('/sections'); ?>">
                                <span class="menu-icon"><i data-lucide="columns-3"></i></span>
                                <div class="menu-text">Secciones</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= base_url('/polling-booth'); ?>">
                                <span class="menu-icon"><i data-lucide="home"></i></span>
                                <div class="menu-text">Casillas</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin']) || $session->verifyUserRights(['captura-personas'])) { ?>

                        <li class="menu-title">
                            <span>Estructura</span>
                        </li>
        
                        <?php
	                        } ?>
        
                        <?php
	                        if($session->verifyUserRights(['superadmin']) || $session->verifyUserRights(['captura-personas'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= base_url('/persons'); ?>">
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
	                        if($session->verifyUserRights(['superadmin'])) { ?>
        
                        <li class="menu-item">
                            <a class="menu-link" href="<?= base_url('/users'); ?>">
                                <span class="menu-icon"><i data-lucide="users"></i></span>
                                <div class="menu-text">Usuarios</div>
                            </a>
                        </li>
        
                        <?php
	                        } ?>
                    </ul>
        
                </div>
            </div>