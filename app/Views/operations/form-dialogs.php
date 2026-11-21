<?php
$operationDialogPrefix ??= 'operation';
$operationRecordForm ??= false;
?>
<dialog id="<?= $operationDialogPrefix ?>-create-dialog" class="card p-5 w-full max-w-3xl<?= $operationRecordForm ? ' operation-record-form' : '' ?>" aria-labelledby="<?= $operationDialogPrefix ?>-create-title">
    <header class="ux-modal-header">
        <h6 id="<?= $operationDialogPrefix ?>-create-title"></h6>
        <button type="button" class="btn" data-close>Cerrar</button>
    </header>
    <form id="<?= $operationDialogPrefix ?>-create-form" class="ux-form-grid">
        <div id="<?= $operationDialogPrefix ?>-create-fields" class="op-form-fields ux-full"></div>
        <div class="ux-modal-footer">
            <button type="submit" class="btn bg-primary text-white ux-labeled-action"><i data-lucide="save" class="size-4" aria-hidden="true"></i>Guardar</button>
        </div>
    </form>
</dialog>
<dialog id="<?= $operationDialogPrefix ?>-settings-dialog" class="card p-5 w-full max-w-2xl" aria-labelledby="<?= $operationDialogPrefix ?>-settings-title">
    <header class="ux-modal-header">
        <h6 id="<?= $operationDialogPrefix ?>-settings-title">Configuración</h6>
        <button type="button" class="btn" data-close>Cerrar</button>
    </header>
    <div id="<?= $operationDialogPrefix ?>-settings-content"></div>
</dialog>
<?php unset($operationDialogPrefix,$operationRecordForm); ?>
