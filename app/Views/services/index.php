<?php

$title='Servicios del sistema'; $section=$title;
require __DIR__.'/../layout/title.php';
?>
<link rel="stylesheet" href="<?= escape_html(asset('css/services.css')); ?>">
<p id="services-message" role="status" class="my-4 text-primary"></p>
<nav id="services-tabs" class="ux-tabs" role="tablist" aria-label="Servicios del sistema">
    <?php foreach ([
        'notifications'=>['Notificaciones','bell'], 'audit'=>['Auditoría','history'],
        'approvals'=>['Aprobaciones','clipboard-check'], 'files'=>['Archivos','files'],
        'exports'=>['Exportaciones','download'], 'jobs'=>['Trabajos','list-todo'],
        'deliveries'=>['Envíos','send'], 'backups'=>['Respaldos','archive'],
    ] as $service => [$label,$icon]): ?>
    <button type="button" id="services-tab-<?= $service ?>" role="tab" aria-selected="false" aria-controls="services-<?= $service ?>" data-hs-tab="#services-<?= $service ?>" data-service="<?= $service ?>" <?= $service === 'notifications' ? '' : 'hidden' ?>>
        <i data-lucide="<?= $icon ?>" class="size-4" aria-hidden="true"></i><?= $label ?>
    </button>
    <?php endforeach; ?>
</nav>
<section class="card p-5 mb-5 services-panel" id="services-notifications" role="tabpanel" aria-labelledby="services-tab-notifications">
    <header class="services-toolbar">
        <h6>Mis notificaciones</h6>
        <div class="services-actions"><button type="button" class="btn" data-refresh="notifications">Actualizar</button><button type="button" class="btn" id="notifications-read-all">Marcar todas como leídas</button></div>
    </header>
    <div id="notifications-list"></div><div class="ux-pagination" data-pager="notifications" hidden></div>
</section>
<section class="card p-5 mb-5 services-panel" id="services-audit" role="tabpanel" aria-labelledby="services-tab-audit" hidden><h6>Auditoría</h6><form id="audit-filter" class="ux-form-grid services-form"><label>Desde<input type="date" name="from" class="form-input"></label><label>Hasta<input type="date" name="to" class="form-input"></label><div><label for="audit-organization">Organización</label><input id="audit-organization-search" type="search" class="form-input" aria-label="Buscar organización por nombre" placeholder="Buscar por nombre"><select id="audit-organization" name="organization" class="form-input" aria-label="Organización"><option value="">Organización de trabajo</option></select><button id="audit-organization-more" type="button" class="btn" hidden>Mostrar más</button></div><div><label for="audit-process">Elección</label><input id="audit-process-search" type="search" class="form-input" aria-label="Buscar elección por nombre" placeholder="Buscar por nombre"><select id="audit-process" name="process" class="form-input" aria-label="Elección"><option value="">Elección de trabajo</option></select><button id="audit-process-more" type="button" class="btn" hidden>Mostrar más</button></div><label>Contextos<select name="context" class="form-input"><option value="selected">Elección de trabajo o filtro</option><option value="all">Todos los contextos autorizados</option></select></label><label>Buscar usuario<input id="audit-user-search" type="search" class="form-input" placeholder="Nombre o cuenta"></label><label>Usuario<select name="user" class="form-input"><option value="">Todos los usuarios autorizados</option></select></label><button id="audit-user-more" type="button" class="btn" hidden>Más usuarios</button><label>Actividad<select name="action" class="form-input"><option value="">Todas las actividades</option></select></label><label>Área<select name="entity" class="form-input"><option value="">Todas las áreas</option></select></label><div class="ux-full"><button class="btn bg-primary text-white"><i data-lucide="search" class="size-4" aria-hidden="true"></i>Consultar historial</button></div></form><div id="audit-list"></div><div class="ux-pagination" data-pager="audit" hidden></div></section>
<section class="card p-5 mb-5 services-panel" id="services-approvals" role="tabpanel" aria-labelledby="services-tab-approvals" hidden>
    <header class="services-toolbar"><h6>Solicitudes de aprobación</h6><button type="button" class="btn" data-refresh="approvals">Actualizar</button></header>
    <div id="approvals-list"></div><div class="ux-pagination" data-pager="approvals" hidden></div>
</section>
<section class="card p-5 mb-5 services-panel" id="services-files" role="tabpanel" aria-labelledby="services-tab-files" hidden>
    <header class="services-toolbar"><h6>Archivos por persona</h6><button type="button" class="btn" data-refresh="files" hidden>Actualizar</button></header>
    <form id="file-form" class="ux-form-grid services-form" hidden>
        <div class="services-file-person ux-full">
            <span class="font-semibold" id="file-person-label">Persona</span>
            <select name="record" class="ux-sr-only" tabindex="-1" aria-labelledby="file-person-label" required></select>
            <span id="file-person-summary" role="status">Selecciona una persona</span>
            <button id="file-person-find" type="button" class="btn">Seleccionar persona</button>
        </div>
        <label class="services-file-input">
            <span class="btn ux-labeled-action"><i data-lucide="paperclip" aria-hidden="true"></i>Elegir archivo</span>
            <input name="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required class="ux-sr-only" aria-label="Elegir archivo" aria-describedby="file-types">
            <span id="file-name" role="status">Ningún archivo seleccionado</span>
            <small id="file-types" class="ux-help">JPEG, PNG, WebP o PDF</small>
        </label>
        <button class="btn bg-primary text-white ux-labeled-action"><i data-lucide="paperclip" class="size-4" aria-hidden="true"></i>Subir archivo</button>
    </form>
    <div id="file-result"></div>
    <div id="files-list"></div><div class="ux-pagination" data-pager="files" hidden></div>
</section>
<section class="card p-5 mb-5 services-panel" id="services-exports" role="tabpanel" aria-labelledby="services-tab-exports" hidden><header class="services-toolbar"><h6>Exportaciones propias</h6><button type="button" class="btn" data-refresh="exports">Actualizar</button></header><form id="export-form" class="ux-form-grid services-form"><label>Datos<select name="type" class="form-input"><option value="personas">Personas</option><option value="participaciones">Participaciones</option><option value="estructura">Estructura</option><option value="usuarios_accesos">Usuarios y accesos</option><option value="paquete">Paquete de datos del cliente</option></select></label><label>Formato<select name="format" class="form-input"><option value="xlsx">XLSX</option><option value="csv">CSV</option><option value="pdf">PDF</option></select></label><label>Código o nombre<input name="search" maxlength="150" class="form-input"></label><button class="btn bg-primary text-white ux-labeled-action"><i data-lucide="download" aria-hidden="true"></i>Solicitar exportación</button></form><div id="exports-list" class="my-3"></div><div class="ux-pagination" data-pager="exports" hidden></div></section>
<section class="card p-5 mb-5 services-panel" id="services-jobs" role="tabpanel" aria-labelledby="services-tab-jobs" hidden><h6>Trabajos en segundo plano</h6><p id="jobs-summary" class="my-3"></p><form id="jobs-filter" class="services-toolbar services-jobs-filter"><select name="state" class="form-input" aria-label="Estado del trabajo"><option value="">Todos los estados</option><option value="pendiente">Pendiente</option><option value="ejecutando">Ejecutando</option><option value="reintento">Reintento</option><option value="fallido">Fallido</option><option value="completado">Completado</option></select><button class="btn" id="jobs-refresh">Actualizar</button></form><div id="jobs-list" class="my-3"></div><div class="ux-pagination" data-pager="jobs" hidden></div></section>
<section class="card p-5 mb-5 services-panel" id="services-deliveries" role="tabpanel" aria-labelledby="services-tab-deliveries" hidden>
    <h6>Canales de envío</h6>
    <p id="notification-channel-help" class="ux-help" hidden>Necesitas alcance de organización para modificar estos canales.</p>
    <form id="notification-config-form" class="services-form">
        <fieldset id="notification-channel-controls" disabled aria-label="Configuración de canales">
        <div class="services-channels">
            <?php foreach (['email'=>['Correo','mail'],'whatsapp'=>['WhatsApp','message-circle']] as $name => [$label,$icon]): ?>
            <label class="ux-module-card services-channel">
                <i data-lucide="<?= $icon ?>" class="size-4" aria-hidden="true"></i>
                <span><strong><?= $label ?></strong><small data-channel-state></small></span>
                <input type="checkbox" role="switch" name="<?= $name ?>" aria-label="<?= $label ?>">
            </label>
            <?php endforeach; ?>
        </div>
        <button class="btn bg-primary text-white ux-labeled-action"><i data-lucide="save" class="size-4" aria-hidden="true"></i>Guardar canales</button>
        </fieldset>
    </form>
    <div class="ux-section">
        <header class="services-toolbar"><h6>Historial de envíos</h6><button type="button" class="btn" data-refresh="deliveries">Actualizar</button></header>
        <div id="deliveries-list"></div><div class="ux-pagination" data-pager="deliveries" hidden></div>
    </div>
</section>
<section class="card p-5 mb-5 services-panel" id="services-backups" role="tabpanel" aria-labelledby="services-tab-backups" hidden><h6>Respaldos técnicos — Super Administrador</h6><p id="backup-summary" class="my-3"></p><details class="my-3"><summary class="btn">Programación y retención de respaldos</summary><form id="backup-policy-form" class="ux-form-grid my-3"><label><input type="checkbox" name="database_daily"> Base de datos diaria</label><label>Respaldo completo de archivos<select name="full_weekday" class="form-input"><option value="0">Domingo</option><option value="1">Lunes</option><option value="2">Martes</option><option value="3">Miércoles</option><option value="4">Jueves</option><option value="5">Viernes</option><option value="6">Sábado</option></select></label><label><input type="checkbox" name="incremental_daily"> Incremental diario de archivos</label><label>Retención (días)<input type="number" name="retention_days" min="1" max="3650" class="form-input" required></label><button class="btn bg-primary text-white">Guardar política</button></form></details><form id="backup-form" class="ux-inline-form ux-section"><label>Contenido del respaldo<select name="type" class="form-input"><option value="bd">Base de datos</option><option value="archivos_full">Archivos completos</option><option value="incremental">Archivos incrementales</option><option value="completo">Base de datos y archivos</option></select></label><button class="btn bg-primary text-white">Solicitar respaldo manual</button></form><form id="backup-filter" class="ux-form-grid"><label>Estado<select name="state" class="form-input"><option value="">Todos</option><option value="pendiente">Pendiente</option><option value="ejecutando">Ejecutando</option><option value="completado">Completado</option><option value="fallido">Fallido</option></select></label><label>Tipo<select name="type" class="form-input"><option value="">Todos</option><option value="bd">Base de datos</option><option value="archivos_full">Archivos completos</option><option value="incremental">Incremental</option><option value="completo">Completo</option></select></label><button class="btn">Actualizar</button></form><div id="backups-list" class="my-3"></div><div class="ux-pagination" data-pager="backups" hidden></div></section>
<dialog id="approval-dialog" class="card p-5"><h6>Resolver aprobación</h6><div id="approval-detail" class="my-3"></div><form id="approval-form" class="grid gap-3"><input type="hidden" name="uuid"><label>Motivo de la decisión<textarea name="reason" class="form-input" maxlength="500" required></textarea></label><button name="decision" value="1" class="btn bg-primary text-white">Aprobar paso</button><button name="decision" value="0" class="btn bg-danger text-white">Rechazar</button><button type="button" data-close="approval-dialog" class="btn">Cerrar</button></form></dialog>
<dialog id="restore-dialog" class="card p-5"><h6>Revisar restauración</h6><div id="restore-detail" class="my-3"></div><p>Este procedimiento restaura una copia en un destino independiente configurado. Revisa el contenido y escribe la confirmación para continuar.</p><form id="restore-form" class="grid gap-3"><input type="hidden" name="uuid"><input type="hidden" name="review"><label>Escribe la confirmación indicada<input name="confirmation" class="form-input" required></label><button class="btn bg-danger text-white">Confirmar restauración</button><button type="button" data-close="restore-dialog" class="btn">Cancelar</button></form></dialog>
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<script>var homeURL = <?= json_script(base_url('')); ?>;</script>
<script src="<?= escape_html(asset('js/services/index.js')); ?>"></script>
<script>var currentLink = <?= json_script(base_url('services')); ?>;</script>
