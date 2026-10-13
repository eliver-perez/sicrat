<?php
    $title = 'Configuración electoral'; $section = $title;
    require __DIR__ . '/../layout/title.php';
?>
<p id="phase3-message" role="status" class="mb-4 text-primary"></p>
<nav id="administration-tabs" class="ux-tabs" role="tablist" aria-label="Configuración electoral">
    <?php foreach (['modules'=>'Módulos','levels'=>'Niveles y reglas','permissions'=>'Roles y permisos'] as $key=>$label): ?>
    <button type="button" id="administration-tab-<?= $key ?>" role="tab" aria-selected="false" aria-controls="administration-<?= $key ?>" data-hs-tab="#administration-<?= $key ?>"><?= $label ?></button>
    <?php endforeach; ?>
</nav>
<section id="administration-modules" role="tabpanel" aria-labelledby="administration-tab-modules" class="card p-5 mb-5">
    <h6 id="module-heading">Módulos</h6>
    <p id="module-description" class="ux-help"></p>
    <div class="ux-form-grid mb-4"><label id="module-organization-field">Organización<select id="module-organization" class="form-input"></select></label><label id="module-context-field">Aplicar a<select id="module-context" class="form-input"><option value="election">Elección de trabajo</option><option value="organization">Toda la organización</option></select></label></div>
    <h6>Funciones esenciales</h6><div id="module-essential" class="ux-essential"></div>
    <h6>Módulos opcionales</h6><div id="module-list" class="ux-module-grid my-3"></div>
    <div class="ux-actions"><button id="module-save" type="button" class="btn bg-primary text-white" disabled>Guardar cambios</button><span id="module-save-status" role="status">Sin cambios pendientes</span></div>
</section>
<section id="administration-levels" role="tabpanel" aria-labelledby="administration-tab-levels" class="card p-5 mb-5">
    <h6>Niveles organizacionales</h6><p class="ux-help">Los niveles describen responsabilidades en el equipo. Los roles definen el acceso al sistema.</p>
    <div id="level-list" class="my-3"></div>
    <form id="level-form" class="ux-form-grid">
        <input name="id" type="hidden"><label>Nombre del nivel<input name="name" class="form-input" maxlength="128" required></label><label>Orden jerárquico<input name="order" type="number" class="form-input" min="1" max="32767" required></label>
        <label><input name="active" type="checkbox" checked> Nivel activo</label><label><input name="can_recruit" type="checkbox"> Puede incorporar integrantes</label><label><input name="same_level" type="checkbox"> Permite responsables del mismo nivel</label>
        <div class="ux-actions"><button class="btn bg-primary text-white">Guardar nivel</button><button id="level-new" type="button" class="btn">Nuevo nivel</button></div>
    </form>
    <form id="structure-config-form" class="ux-form-grid ux-section">
        <h6 class="ux-full">Reglas de la elección</h6><label><input name="auto_promote" type="checkbox"> Promover con la primera incorporación autorizada</label><label><input name="approval_required" type="checkbox" checked> Las transferencias requieren aprobación</label>
        <label class="ux-full">Responsable de aprobar transferencias<select name="approver" class="form-input"><option value="">Superior común o Super Administrador</option></select></label><div class="ux-actions"><button class="btn bg-primary text-white">Guardar reglas</button></div>
    </form>
</section>
<section id="administration-permissions" role="tabpanel" aria-labelledby="administration-tab-permissions" class="card p-5 mb-5">
    <h6>Roles y permisos</h6><p class="ux-help">Configura el acceso al sistema sin cambiar el nivel organizacional de la persona.</p>
    <nav id="permission-tabs" class="ux-tabs" role="tablist" aria-label="Administrar roles y permisos">
        <?php foreach (['roles'=>'Roles','role'=>'Permisos del rol','user'=>'Excepciones por usuario','territories'=>'Territorios autorizados'] as $key=>$label): ?>
        <button type="button" id="permission-tab-<?= $key ?>" role="tab" aria-selected="false" aria-controls="permission-panel-<?= $key ?>" data-hs-tab="#permission-panel-<?= $key ?>" data-target="<?= $key ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </nav>
    <div id="permission-panel-roles" role="tabpanel" aria-labelledby="permission-tab-roles">
        <form id="role-form" class="ux-inline-form"><label>Nombre del nuevo rol<input name="name" class="form-input" maxlength="128" required></label><button class="btn bg-primary text-white">Crear rol</button></form>
        <div id="role-list" class="ux-essential my-3"></div>
    </div>
    <div id="permission-panel-role" role="tabpanel" aria-labelledby="permission-tab-role" hidden></div>
    <div id="permission-panel-user" role="tabpanel" aria-labelledby="permission-tab-user" hidden></div>
    <div id="permission-editor" hidden>
        <form id="permission-form" class="ux-form-grid">
            <select name="target" hidden aria-label="Origen del permiso"><option value="role">Rol</option><option value="user">Usuario</option></select>
            <label id="permission-role-field">Rol<select name="role" class="form-input" required></select></label><label id="permission-user-field" hidden>Usuario<select name="user" class="form-input"></select></label>
            <label>Aplicar a<select name="context" class="form-input"><option value="electoral">Esta elección</option><option id="permission-global" value="global">Configuración global</option></select></label>
            <label>Permiso<select name="permission" class="form-input" required></select></label><label>Alcance de la información<select name="scope" class="form-input"></select></label>
            <label>Decisión<select name="value" class="form-input" aria-describedby="permission-decision-help"><option value="1">Conceder</option><option value="0">Revocar</option></select></label>
            <p id="permission-decision-help" class="ux-help ux-full"></p>
            <div class="ux-actions"><button class="btn bg-primary text-white">Guardar permiso</button><button id="permission-inspect" type="button" class="btn">Consultar permisos configurados</button></div>
        </form>
        <div class="ux-section"><h6>Permisos configurados</h6><div class="ux-form-grid"><label>Buscar permiso<input id="permission-search" type="search" class="form-input"></label><label>Estado<select id="permission-state-filter" class="form-input"><option value="">Todos</option><option value="1">Concedidos</option><option value="0">Revocados</option></select></label></div><div id="permission-list" class="my-3 overflow-x-auto"></div></div>
    </div>
    <div id="permission-panel-territories" role="tabpanel" aria-labelledby="permission-tab-territories" hidden>
        <form id="territory-form" class="grid gap-3">
            <h6>Territorios autorizados</h6><label>Usuario<select name="user" class="form-input" required></select></label>
            <div class="ux-multiselect"><label for="territory-search">Buscar sección<input id="territory-search" type="search" class="form-input" placeholder="Número de sección, estado o municipio"></label><div id="territory-options" class="ux-multiselect-list"></div><p id="territory-count" class="ux-help" role="status">0 seleccionadas</p><div class="ux-actions"><button id="territory-previous" type="button" class="btn" disabled>Anterior</button><button id="territory-next" type="button" class="btn" disabled>Siguiente</button><button id="territory-clear" type="button" class="btn">Deseleccionar todas</button></div></div>
            <div class="ux-actions"><button class="btn bg-primary text-white">Guardar territorios de esta elección</button></div>
        </form>
    </div>
</section>
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<script src="<?= escape_html(asset('js/administration/index.js')); ?>"></script>
<script>var currentLink = <?= json_script(base_url('administration')); ?>;</script>
