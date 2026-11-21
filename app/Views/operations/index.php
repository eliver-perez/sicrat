<?php
	$icons=['interacciones'=>'phone','peticiones'=>'clipboard-list','incidencias'=>'circle-alert','transporte'=>'bus',
		'conversaciones'=>'messages-square','tareas'=>'list-checks','metas'=>'target','eventos'=>'calendar-days','encuestas'=>'list-checks'];
	$title=escape_html($operationDefinition['title']); $titleIcon=$icons[$operationEntity] ?? null;
	$section='Operación'; require __DIR__.'/../layout/title.php';
	$isTransport=$operationEntity==='transporte';
	$personDetail=$isTransport && \App\Core\Auth::processId() && \App\Auth\Authorization::canAny(['personas.ver']);
	$related=$operationDefinition['module']==='tareas_metas' ? ['tareas','metas'] : ($operationDefinition['module']==='peticiones_incidencias' ? ['peticiones','incidencias'] : []);
?>
<link rel="stylesheet" href="<?= escape_html(asset('css/operations.css')); ?>">
<div id="operations-root" data-entity="<?= escape_html($operationEntity); ?>">
	<?php if ($related) { ?><nav class="ux-tabs" aria-label="Módulos relacionados">
	<?php foreach ($related as $entity) { if (!$session->verifyUserRights([$entity.'.ver'])) continue; ?>
		<a class="inline-flex items-center gap-2" href="<?= escape_html(base_url('/operations/?module='.$entity)); ?>" <?= $entity===$operationEntity ? 'aria-current="page"' : ''; ?>><i data-lucide="<?= escape_html($icons[$entity]); ?>" class="size-4" aria-hidden="true"></i><?= escape_html(\App\Services\Operations\Catalog::entity($entity)['title']); ?></a>
	<?php } ?></nav><?php } ?>
	<p id="operations-message" class="mb-4 text-primary" role="status" aria-live="polite"></p>
	<?php if (!\App\Core\Auth::processId()) { ?><div class="card p-5"><p>Selecciona una elección de trabajo en el encabezado para consultar este módulo.</p></div><?php } else { ?>
	<div class="card operation-card p-5">
		<header class="operation-card-header">
			<p id="operation-help" class="text-sm text-default-600"></p>
			<div class="ux-actions">
				<button id="operation-new" type="button" class="btn bg-primary text-white" hidden>Nuevo registro</button>
				<button id="operation-settings" type="button" class="btn" hidden><i data-lucide="settings" class="size-4" aria-hidden="true"></i>Configuración</button>
				<button id="operation-export" type="button" class="btn" hidden><i data-lucide="download" class="size-4" aria-hidden="true"></i>Exportar</button>
			</div>
		</header>
		<div class="operation-toolbar">
			<form id="operation-filter" class="operation-filters">
				<label>Nombre o referencia<input name="search" class="form-input" maxlength="150" autocomplete="off"></label>
				<label id="operation-state-filter"><?= $isTransport ? 'Necesidad' : 'Estado'; ?><select name="state" class="form-input"><option value=""><?= $isTransport ? 'Todas' : 'Todos'; ?></option></select></label>
				<?php if ($isTransport) { ?>
					<label>Sección<input name="section_number" class="form-input" inputmode="numeric" maxlength="4" pattern="[0-9]{1,4}" autocomplete="off"></label>
					<label>Coordinación<select name="coordination" class="form-input"><option value="">Todas</option><option value="pendiente">Pendiente</option><option value="coordinada">Coordinada</option></select></label>
					<div id="transport-coordinator-filter" class="transport-picker"></div>
				<?php } ?>
				<div class="operation-filter-actions">
					<button class="btn"><i data-lucide="search" class="size-4" aria-hidden="true"></i>Buscar</button>
					<button id="operation-refresh" type="button" class="btn"><i data-lucide="refresh-cw" class="size-4" aria-hidden="true"></i>Actualizar</button>
				</div>
			</form>
			<?php if ($isTransport) { ?>
			<div class="person-view-switch">
				<button class="btn" type="button" id="transport-view-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="transport-view-menu"></button>
				<div id="transport-view-menu" class="person-view-menu" role="menu" aria-label="Vista de transporte" hidden>
					<button class="btn" type="button" role="menuitemradio" aria-checked="true"><i data-lucide="list" class="size-4" aria-hidden="true"></i>Lista<i data-lucide="check" class="size-4 person-view-check" aria-hidden="true"></i></button>
					<button class="btn" type="button" role="menuitemradio" aria-checked="false"><i data-lucide="map" class="size-4" aria-hidden="true"></i>Mapa<i data-lucide="check" class="size-4 person-view-check" aria-hidden="true"></i></button>
				</div>
			</div>
			<?php } ?>
		</div>
		<?php if ($isTransport) { ?><div id="operation-summary" class="transport-summary" aria-live="polite" hidden></div><?php } ?>
		<div id="operation-list"></div>
		<?php if ($isTransport) { ?>
			<section id="transport-map-panel" aria-label="Mapa de transporte" hidden>
				<p id="transport-map-status" class="ux-help" role="status"></p>
				<div id="transport-map" class="person-overview-canvas" aria-label="Ubicaciones de participantes"></div>
			</section>
		<?php } ?>
	</div>
	<?php
    $operationRecordForm = in_array($operationEntity,['interacciones','peticiones','incidencias'],true);
    require __DIR__.'/form-dialogs.php';
    ?>
	<dialog id="operation-detail-dialog" class="card p-5 w-full max-w-3xl<?= $isTransport ? ' transport-detail-dialog' : ''; ?>" aria-labelledby="operation-detail-title">
		<div class="ux-actions mb-4"><h6 id="operation-detail-title"></h6><button type="button" class="btn" data-close>Cerrar</button></div>
		<nav id="operation-detail-tabs" class="ux-tabs" role="tablist" aria-label="Ficha operativa">
		<?php foreach (['summary'=>'Detalle','actions'=>'Acciones','history'=>'Seguimiento','evidence'=>'Evidencias'] as $key=>$label) { ?>
		<button id="operation-tab-<?= $key; ?>" type="button" role="tab" aria-controls="operation-panel-<?= $key; ?>" data-hs-tab="#operation-panel-<?= $key; ?>"><?= $label; ?></button>
		<?php } ?></nav>
		<?php foreach (['summary','actions','history','evidence'] as $key) { ?><section id="operation-panel-<?= $key; ?>" role="tabpanel" aria-labelledby="operation-tab-<?= $key; ?>" hidden></section><?php } ?>
	</dialog>
	<dialog id="operation-approval-dialog" class="card p-5 w-full max-w-xl" aria-labelledby="operation-approval-title"><h6 id="operation-approval-title">Validación</h6><div id="operation-approval-content"></div><button type="button" data-close class="btn mt-4">Cerrar</button></dialog>
	<?php } ?>
</div>
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<?php require_once __DIR__.'/form-assets.php'; ?>
<script src="<?= escape_html(asset('js/messaging-poll.js')); ?>"></script>
<script src="<?= escape_html(asset('js/messaging-media.js')); ?>"></script>
<script src="<?= escape_html(asset('js/messaging-history.js')); ?>"></script>
<script src="<?= escape_html(asset('js/messaging.js')); ?>"></script>
<?php if ($isTransport && \App\Core\Auth::processId()) {
	require_once __DIR__.'/../persons/map-assets.php';
	if ($personDetail) require __DIR__.'/../persons/detail.php';
?>
<script src="<?= escape_html(asset('js/persons/points-map.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/transport.js')); ?>"></script>
<?php } ?>
<script src="<?= escape_html(asset('js/operations/index.js')); ?>"></script>
<script>var currentLink=<?= json_script(base_url('/operations/?module='.$operationEntity)); ?>;</script>
