<?php
	$title=escape_html($operationDefinition['title']); $section='Operación'; require __DIR__.'/../layout/title.php';
	$related=$operationDefinition['module']==='tareas_metas' ? ['tareas','metas'] : ($operationDefinition['module']==='peticiones_incidencias' ? ['peticiones','incidencias'] : []);
?>
<div id="operations-root" data-entity="<?= escape_html($operationEntity); ?>">
	<?php if ($related) { ?><nav class="ux-tabs" aria-label="Módulos relacionados">
	<?php foreach ($related as $entity) { if (!$session->verifyUserRights([$entity.'.ver'])) continue; ?>
		<a class="btn <?= $entity===$operationEntity ? 'bg-primary text-white' : ''; ?>" href="<?= escape_html(base_url('/operations/?module='.$entity)); ?>" <?= $entity===$operationEntity ? 'aria-current="page"' : ''; ?>><?= escape_html(\App\Services\Operations\Catalog::entity($entity)['title']); ?></a>
	<?php } ?></nav><?php } ?>
	<p id="operations-message" class="mb-4 text-primary" role="status" aria-live="polite"></p>
	<?php if (!\App\Core\Auth::processId()) { ?><div class="card p-5"><p>Selecciona una elección de trabajo en el encabezado para consultar este módulo.</p></div><?php } else { ?>
	<div id="operation-summary" class="ux-module-grid mb-5" hidden></div>
	<div class="card p-5">
		<div class="card-header"><h6><?= escape_html($operationDefinition['title']); ?></h6><div class="ux-actions"><button id="operation-new" type="button" class="btn bg-primary text-white" hidden>Nuevo registro</button><button id="operation-settings" type="button" class="btn" hidden>Configuración</button><button id="operation-export" type="button" class="btn" hidden>Exportar</button></div></div>
		<p id="operation-help" class="text-sm text-default-600 mb-4"></p>
		<form id="operation-filter" class="ux-inline-form mb-4"><label>Buscar<input name="search" class="form-input" maxlength="150" autocomplete="off" placeholder="Nombre o referencia"></label><label id="operation-state-filter">Estado<select name="state" class="form-input"><option value="">Todos</option></select></label><button class="btn">Buscar</button><button id="operation-refresh" type="button" class="btn">Actualizar</button></form>
		<div id="operation-list"></div>
	</div>
	<dialog id="operation-create-dialog" class="card p-5 w-full max-w-3xl" aria-labelledby="operation-create-title">
		<h6 id="operation-create-title" class="mb-4"></h6><form id="operation-create-form" class="ux-form-grid"><div id="operation-create-fields" class="op-form-fields ux-full"></div><div class="ux-actions ux-full"><button class="btn bg-primary text-white">Guardar</button><button type="button" class="btn" data-close>Cerrar</button></div></form>
	</dialog>
	<dialog id="operation-detail-dialog" class="card p-5 w-full max-w-3xl" aria-labelledby="operation-detail-title">
		<div class="ux-actions mb-4"><h6 id="operation-detail-title"></h6><button type="button" class="btn" data-close>Cerrar</button></div>
		<nav id="operation-detail-tabs" class="ux-tabs" role="tablist" aria-label="Ficha operativa">
		<?php foreach (['summary'=>'Detalle','actions'=>'Acciones','history'=>'Seguimiento','evidence'=>'Evidencias'] as $key=>$label) { ?>
		<button id="operation-tab-<?= $key; ?>" type="button" role="tab" aria-controls="operation-panel-<?= $key; ?>" data-hs-tab="#operation-panel-<?= $key; ?>"><?= $label; ?></button>
		<?php } ?></nav>
		<?php foreach (['summary','actions','history','evidence'] as $key) { ?><section id="operation-panel-<?= $key; ?>" role="tabpanel" aria-labelledby="operation-tab-<?= $key; ?>" hidden></section><?php } ?>
	</dialog>
	<dialog id="operation-settings-dialog" class="card p-5 w-full max-w-2xl" aria-labelledby="operation-settings-title"><h6 id="operation-settings-title" class="mb-4">Configuración</h6><div id="operation-settings-content"></div><button type="button" data-close class="btn mt-4">Cerrar</button></dialog>
	<dialog id="operation-approval-dialog" class="card p-5 w-full max-w-xl" aria-labelledby="operation-approval-title"><h6 id="operation-approval-title">Validación</h6><div id="operation-approval-content"></div><button type="button" data-close class="btn mt-4">Cerrar</button></dialog>
	<?php } ?>
</div>
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/core.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/forms.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/details.js')); ?>"></script>
<script src="<?= escape_html(asset('js/messaging-poll.js')); ?>"></script>
<script src="<?= escape_html(asset('js/messaging-media.js')); ?>"></script>
<script src="<?= escape_html(asset('js/messaging-history.js')); ?>"></script>
<script src="<?= escape_html(asset('js/messaging.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/index.js')); ?>"></script>
<script>var currentLink=<?= json_script(base_url('/operations/?module='.$operationEntity)); ?>;</script>
