<?php
$title='Dashboard';
require __DIR__.'/../layout/title.php';
?>
<link rel="stylesheet" href="<?= escape_html(asset('css/dashboard.css')) ?>">
<div id="dashboard" data-base="<?= escape_html(base_url('')) ?>" data-charts="<?= escape_html(base_url('template/assets/apexcharts.esm-DPbJ6jlt.js')) ?>">
    <div class="db-heading"><div><p class="db-eyebrow">TU CENTRO OPERATIVO</p><h2>Una mirada a tu día</h2><p id="db-context" class="db-muted">Cargando tu información…</p></div><div class="db-toolbar"><label for="db-period">Periodo de actividad<select id="db-period"><option value="7">Últimos 7 días</option><option value="15">Últimos 15 días</option><option value="30">Últimos 30 días</option></select></label><button id="db-refresh" type="button">Actualizar</button></div></div>
    <p id="db-status" role="status" aria-live="polite" hidden></p>
    <p id="db-message" class="db-notice" hidden></p>
    <section id="db-kpis" class="db-kpis" aria-label="Indicadores de tu ámbito"></section>
    <div class="db-main">
        <div id="db-charts" class="db-charts"></div>
        <section class="db-panel" id="db-pending-panel" hidden><div class="db-panel-heading"><div><p class="db-eyebrow">POR ATENDER</p><h3>Pendientes</h3></div><span id="db-pending-count" class="db-count"></span></div><p class="db-muted">Tus asignaciones y solicitudes, en orden de prioridad.</p><div id="db-pending" class="db-list"></div></section>
        <section class="db-panel" id="db-activity-panel" hidden><div class="db-panel-heading"><div><p class="db-eyebrow">LO QUE ESTÁ PASANDO</p><h3>Actividad reciente</h3></div></div><p class="db-muted">Tus movimientos y los de tu estructura autorizada.</p><div id="db-activity" class="db-list"></div></section>
        <section class="db-panel db-notes"><div class="db-panel-heading"><div><p class="db-eyebrow">SÓLO PARA TI</p><h3>Notas y recordatorios</h3></div><button id="db-note-new" type="button" class="db-primary" data-note-write>Nueva nota</button></div><p class="db-muted">Privadas, disponibles en todas tus elecciones. Las modificaciones requieren conexión.</p><p id="db-notes-status" role="status" aria-live="polite"></p><div id="db-notes" class="db-list"></div><div class="db-pagination"><button id="db-notes-prev" type="button" hidden>Anterior</button><button id="db-notes-next" type="button" hidden>Siguiente</button></div></section>
    </div>
    <dialog id="db-note-dialog" aria-labelledby="db-note-title"><form id="db-note-form"><h3 id="db-note-title">Nota personal</h3><label for="db-note-text">Tu nota<textarea id="db-note-text" required maxlength="1000" rows="5"></textarea></label><label for="db-note-reminder">Recordatorio (opcional, hora local)<input id="db-note-reminder" type="datetime-local"></label><label class="db-checkbox"><input id="db-note-pin" type="checkbox"> Fijar esta nota</label><p id="db-note-error" role="alert"></p><div class="db-dialog-actions"><button id="db-note-cancel" type="button">Cancelar</button><button class="db-primary" type="submit" data-note-write>Guardar nota</button></div></form></dialog>
    <dialog id="db-delete-dialog" aria-labelledby="db-delete-title"><h3 id="db-delete-title">Eliminar nota</h3><p>Esta nota se eliminará de tus recordatorios.</p><p id="db-delete-error" role="alert"></p><div class="db-dialog-actions"><button id="db-delete-cancel" type="button">Cancelar</button><button id="db-delete-confirm" type="button" data-note-write>Eliminar</button></div></dialog>
</div>
<script src="<?= escape_html(asset('js/dashboard/index.js')) ?>"></script>
<script>var currentLink=<?= json_script(base_url('')) ?>;var callBack=<?= json_script(is_string($_GET['callBack'] ?? null) ? $_GET['callBack'] : '') ?>;</script>
