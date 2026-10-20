<?php $title='Jornada Electoral y resultados';$section=$title;require __DIR__.'/../layout/title.php'; ?>
<p id="jornada-status" class="ux-help mb-4" role="status">Cargando elección de trabajo…</p>
<div id="jornada-app" hidden>
    <div class="card p-5 mb-5"><div class="ux-actions"><strong id="jornada-election"></strong><span id="jornada-state" class="ux-badge"></span></div><p id="jornada-clock" class="ux-help"></p><div id="jornada-state-actions" class="ux-actions"></div></div>
    <nav id="jornada-tabs" class="ux-tabs" role="tablist" aria-label="Jornada Electoral">
        <?php foreach(['booths'=>'Mis casillas y capturas','dashboard'=>'Avance y resultados','configuration'=>'Configuración y asignaciones','reports'=>'Reportes'] as $key=>$label): ?>
        <button type="button" id="jornada-tab-<?= $key ?>" role="tab" aria-selected="false" aria-controls="jornada-<?= $key ?>" data-hs-tab="#jornada-<?= $key ?>" data-panel="<?= $key ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </nav>
    <section id="jornada-booths" role="tabpanel" aria-labelledby="jornada-tab-booths" class="card p-5" hidden>
        <form id="jornada-filters" class="ux-form-grid"><label>Buscar sección, municipio o casilla<input name="search" type="search" class="form-input"></label><label>Estado<select name="estado" class="form-input"><option value="">Todos</option><option value="sin_captura">Sin captura</option><option value="pendiente">Pendiente de validación inicial</option><option value="preliminar">Preliminar vigente</option><option value="validada">Validada vigente</option><option value="con_propuestas">Con propuestas pendientes</option></select></label><label><input name="mis" type="checkbox" value="1"> Sólo mis casillas asignadas</label><button class="btn bg-primary text-white">Buscar</button></form>
        <details class="mt-3"><summary class="btn">Filtrar por territorio o capturista</summary><div id="jornada-booth-territories" class="ux-form-grid mt-3"></div><button type="button" id="jornada-apply-booth-filters" class="btn bg-primary text-white">Aplicar filtros</button></details>
        <div id="jornada-booth-list" class="mt-4"></div>
    </section>
    <section id="jornada-dashboard" role="tabpanel" aria-labelledby="jornada-tab-dashboard" class="card p-5" hidden>
        <form id="jornada-summary-filter" class="ux-form-grid">
            <label>Concentrado<select name="modo" class="form-input"><option value="validado">Validado interno</option><option value="preliminar">Preliminar interno</option></select></label>
            <button class="btn bg-primary text-white">Actualizar concentrado</button>
            <details class="ux-full"><summary class="btn">Filtros territoriales</summary><div id="jornada-summary-territories" class="ux-form-grid mt-3"><label>Distrito local<input name="distrito_local" type="number" min="1" step="1" class="form-input"></label><label>Distrito federal<input name="distrito_federal" type="number" min="1" step="1" class="form-input"></label></div></details>
        </form>
        <p id="jornada-summary-legend" class="ux-help my-4"></p><div id="jornada-progress" class="grid grid-cols-2 lg:grid-cols-3 gap-3"></div><div id="jornada-charts" class="ux-form-grid my-4"><div id="jornada-candidate-chart" class="ux-full" aria-label="Gráfica de votos por candidatura"></div><div id="jornada-progress-chart" class="ux-full" aria-label="Gráfica del avance de captura y validación"></div></div>
        <h6>Totales derivados por candidatura</h6><div id="jornada-candidate-table"></div><h6 class="mt-5">Votos crudos por opción exacta del acta</h6><div id="jornada-option-table"></div><p id="jornada-denominator" class="ux-help my-3"></p>
        <div class="ux-actions"><button id="jornada-show-pending" type="button" class="btn">Ver casillas sin captura</button><button id="jornada-show-proposals" type="button" class="btn">Ver casillas con propuestas pendientes</button></div>
    </section>
    <section
        id="jornada-configuration"
        role="tabpanel"
        aria-labelledby="jornada-tab-configuration"
        class="card p-5"
        hidden
    >
        <div id="jornada-config-content"></div>
        <div id="jornada-assign-content"></div>
    </section>
    <section id="jornada-reports" role="tabpanel" aria-labelledby="jornada-tab-reports" class="card p-5" hidden><p class="ux-help">Los informes respetan los permisos y filtros del concentrado o listado. Se generan en segundo plano y se descargan desde Servicios y notificaciones.</p><div id="jornada-export-content"></div></section>
</div>
<dialog id="jornada-booth-dialog" class="ux-dialog" aria-labelledby="jornada-booth-title"><div class="ux-actions"><h6 id="jornada-booth-title"></h6><button type="button" class="btn" data-close>Volver</button></div><div id="jornada-booth-content"></div></dialog>
<dialog id="jornada-version-dialog" class="ux-dialog" aria-labelledby="jornada-version-title"><div class="ux-actions"><h6 id="jornada-version-title"></h6><button type="button" class="btn" data-close>Volver</button></div><div id="jornada-version-content"></div></dialog>
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/core.js')); ?>"></script>
<link rel="stylesheet" href="<?= escape_html(asset('css/jornada.css')); ?>">
<script src="<?= escape_html(asset('js/jornada/presentation.js')); ?>"></script>
<script src="<?= escape_html(asset('js/jornada/index.js')); ?>"></script>
<script>var currentLink=<?= json_script(base_url('/jornada')); ?>;</script>
