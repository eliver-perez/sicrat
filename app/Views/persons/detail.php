<?php
$personCapabilities ??= [
    'edit' => \App\Auth\Authorization::canAny(['personas.editar']),
    'participate' => \App\Auth\Authorization::canAny(['personas.participar']),
];
?>
<script>window.SicratPersonCapabilities=<?= json_script($personCapabilities); ?>;</script>
<dialog id="person-detail" class="card p-5 w-full max-w-3xl" aria-labelledby="person-detail-title">
    <div class="ux-modal-header"><h6 class="card-title" id="person-detail-title">Persona</h6><button id="person-detail-close" type="submit" form="person-detail-close-form" class="btn ux-modal-close ux-icon-action" title="Cerrar diálogo" aria-label="Cerrar diálogo"><i data-lucide="x" class="size-4" aria-hidden="true"></i></button></div>
    <div class="ux-actions" id="person-detail-actions">
        <span id="person-structure-action"></span>
        <?php if ($personCapabilities['edit']): ?><button type="button" class="btn ux-secondary-action" id="person-edit"><i data-lucide="pencil" class="size-4" aria-hidden="true"></i>Editar</button><?php endif; ?>
    </div>
    <div id="person-consult"></div>
    <form id="person-edit-form" hidden>
        <div id="person-edit-fields" class="ux-full"></div>
        <div class="ux-modal-footer ux-actions">
            <button class="btn" id="person-edit-cancel" type="button">Cancelar</button>
            <button class="btn bg-primary text-white" type="submit">Guardar</button>
        </div>
    </form>
    <section id="person-context" class="ux-section"><h6 class="card-title">Participación y estructura</h6>
    <div id="person-participations" class="mb-4 overflow-x-auto"></div>
    <div id="person-structure" class="ux-section" aria-live="polite"></div>
    <div id="person-operational-activity" class="mb-4"></div>
    <?php if ($personCapabilities['participate']): ?>
    <details id="person-participation-options"><summary>Administrar participación electoral</summary><label>Incorporar a una o varias elecciones <select id="person-election-select" class="form-input" multiple size="6"></select></label>
    <button type="button" id="person-participate" class="btn bg-primary text-white mt-3">Incorporar</button></details>
    <?php endif; ?></section>
    <form id="person-detail-close-form" method="dialog" hidden></form>
</dialog>
<?php require_once __DIR__.'/map-assets.php'; ?>
<script src="<?= escape_html(asset('js/persons/structure-assignment.js')); ?>"></script>
<script src="<?= escape_html(asset('js/persons/identity.js')); ?>"></script>
<script src="<?= escape_html(asset('js/persons/activity.js')); ?>"></script>
