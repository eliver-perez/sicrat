<?php $title=$resourceModule==='finanzas' ? 'Finanzas' : 'Inventario'; $section='Recursos'; require __DIR__.'/../layout/title.php'; ?>
<div id="resources-root" data-module="<?= escape_html($resourceModule); ?>">
	<p id="resources-message" class="mb-4 text-primary" role="status" aria-live="polite"></p>
	<?php if(!\App\Core\Auth::processId()) { ?><div class="card p-5"><p>Selecciona una elección de trabajo en el encabezado.</p></div><?php } else { ?>
	<p id="resources-mode" class="op-small-note mb-4"></p>
	<nav id="resources-tabs" class="ux-tabs" role="tablist" aria-label="Apartados de <?= escape_html(strtolower($title)); ?>"></nav>
	<button id="resources-configuration" class="btn mb-3" type="button" hidden><i data-lucide="settings" class="size-4" aria-hidden="true"></i> <?= $resourceModule==='finanzas' ? 'Configuración financiera' : 'Configuración de inventario' ?></button>
	<div id="resources-primary-anchor"></div>
	<section id="resources-primary-panel" class="card p-5" aria-label="Información de recursos">
		<div class="card-header"><h6 id="resources-title"></h6><div class="ux-actions"><button id="resources-new" type="button" class="btn bg-primary text-white" hidden>Nuevo registro</button><button id="resources-export" type="button" class="btn" hidden>Exportar</button></div></div>
		<p id="resources-help" class="op-small-note mb-4"></p>
		<details id="resources-filter-panel" class="mb-4" hidden><summary class="btn mb-3">Filtros de búsqueda</summary>
		<form id="resources-filters" class="ux-inline-form mb-4" hidden><label>Buscar<input class="form-input" name="search" type="search" maxlength="150" placeholder="Nombre o folio"></label><label>Estado<select class="form-input" name="estado"><option value="">Todos</option></select></label><label>Tipo<select class="form-input" name="tipo"><option value="">Todos</option></select></label><label hidden>Existencia mínima<select class="form-input" name="bajo_minimo"><option value="">Todas</option><option value="1">Bajo mínimo</option></select></label><label>Desde<input name="desde" class="form-input" type="date"></label><label>Hasta<input name="hasta" class="form-input" type="date"></label><button type="submit" class="btn">Buscar</button><button id="resources-refresh" type="button" class="btn">Actualizar</button></form>
		<div id="resources-extra-filters" class="op-form-fields mb-4" hidden></div></details>
		<div id="resources-content"></div>
	</section>
	<dialog id="resources-config-dialog" class="card p-5" aria-labelledby="resources-config-title"><div class="ux-modal-header"><h6 id="resources-config-title"><?= $resourceModule==='finanzas' ? 'Configuración financiera' : 'Configuración de inventario' ?></h6><button type="button" data-close class="btn">Cerrar</button></div><nav id="resources-config-tabs" class="ux-tabs mt-3" role="tablist" aria-label="Configuración de recursos"></nav></dialog>
	<dialog id="resources-dialog" class="card p-5 w-full max-w-3xl" aria-labelledby="resources-dialog-title"><div class="ux-actions mb-4"><h6 id="resources-dialog-title"></h6><button type="button" data-close class="btn">Cerrar</button></div><div id="resources-dialog-content"></div></dialog>
	<dialog id="resources-detail" class="card p-5 w-full max-w-3xl" aria-labelledby="resources-detail-title"><div class="ux-actions mb-4"><h6 id="resources-detail-title"></h6><button type="button" data-close class="btn">Cerrar</button></div><nav id="resources-detail-tabs" class="ux-tabs" role="tablist" aria-label="Ficha de recursos"></nav><div id="resources-detail-content"></div></dialog>
	<?php } ?>
</div>
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/core.js')); ?>"></script>
<script src="<?= escape_html(asset('js/resources/core.js')); ?>"></script>
<script src="<?= escape_html(asset('js/resources/forms.js')); ?>"></script>
<script src="<?= escape_html(asset('js/resources/details.js')); ?>"></script>
<script src="<?= escape_html(asset('js/resources/index.js')); ?>"></script>
<script>var currentLink=<?= json_script(base_url('/resources/?module='.$resourceModule)); ?>;</script>
