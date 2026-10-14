<div class="card mb-5 p-4">
    <div class="ux-actions mb-4">
        <?php if ($personCapabilities['import']): ?>
        <button type="button" id="person-import-open" class="btn bg-primary text-white">Importar personas</button>
        <?php endif; ?>
        <?php if (\App\Auth\Authorization::canAny(['superadmin','admin'])): ?>
        <button type="button" id="identity-policy-open" class="btn">Reglas de identidad</button>
        <?php endif; ?>
    </div>
    <p>El padrón pertenece a la organización. Registrar o importar una persona no la incorpora a una elección.</p>
    <div id="person-duplicates" class="hidden p-4 border border-default-200"></div>
</div>
<dialog id="person-detail" class="card p-5 w-full max-w-3xl" aria-label="Ficha de persona">
    <div class="flex justify-between mb-4"><h6 class="card-title" id="person-detail-title">Persona</h6><button type="button" class="btn" data-close onclick="document.getElementById('person-detail').close()">Cerrar</button></div>
    <form id="person-edit-form">
        <div id="person-neighborhood-picker" class="hidden mb-4 ux-full"></div>
        <div id="person-edit-fields" class="ux-full"></div>
        <?php if ($personCapabilities['edit']): ?><button class="btn bg-primary text-white mt-4" type="submit">Guardar información</button><?php endif; ?>
    </form>
    <section class="ux-section"><h6 class="card-title">Participación electoral</h6>
    <div id="person-participations" class="mb-4 overflow-x-auto"></div>
    <div id="person-operational-activity" class="mb-4"></div>
    <?php if ($personCapabilities['participate']): ?>
    <label>Incorporar a una o varias elecciones <select id="person-election-select" class="form-input" multiple size="6"></select></label>
    <button type="button" id="person-participate" class="btn bg-primary text-white mt-3">Incorporar</button>
    <?php endif; ?></section>
</dialog>
<dialog id="identity-policy" class="card p-5" aria-label="Reglas de identidad">
    <h6 class="card-title mb-4">Reglas de identidad de la organización seleccionada</h6>
    <form id="identity-policy-form">
        <label class="block"><input type="checkbox" id="identity-curp"> Impedir CURP repetida</label>
        <label class="block"><input type="checkbox" id="identity-key"> Impedir clave de elector repetida</label>
        <p class="my-4">Las coincidencias existentes se conservan. Desactivar una regla permite registros nuevos con advertencia.</p>
        <button class="btn bg-primary text-white" type="submit">Guardar</button>
        <button class="btn" type="button" onclick="document.getElementById('identity-policy').close()">Cerrar</button>
    </form>
</dialog>
<?php if ($personCapabilities['import']): ?>
<dialog id="person-import" class="card p-5 w-full max-w-3xl" aria-label="Importar personas">
    <div class="flex justify-between mb-4"><h6 class="card-title">Importar al padrón de la organización seleccionada</h6><button type="button" class="btn" data-close onclick="document.getElementById('person-import').close()">Cerrar</button></div>
    <p>CSV en UTF-8 o XLSX (primera hoja). Hasta 10 MB y 20 000 filas. Las fechas usan AAAA-MM-DD. Puedes importar el número de sección cuando sea único en el catálogo. Las columnas sin asignación se omiten.</p>
    <form id="person-import-upload" class="my-4">
        <label>Archivo de personas<input type="file" name="file" accept=".csv,.xlsx" required class="form-input"></label>
        <label>Separador CSV <select name="delimiter" class="form-input"><option value=",">Coma</option><option value=";">Punto y coma</option><option value="&#9;">Tabulación</option></select></label>
        <button class="btn bg-primary text-white mt-3" type="submit">Cargar para revisar</button>
    </form>
    <form id="person-import-mapping" class="hidden">
        <div id="person-import-columns" class="grid grid-cols-2 gap-3"></div>
        <label class="block my-4">Modalidad<select id="import-mode" class="form-input"><option value="batches">Revisar por lotes</option><?php if ($personCapabilities['automatic']): ?><option value="automatic">Altas automáticas</option><?php endif; ?></select></label>
        <?php if (!$personCapabilities['automatic']): ?><p class="ux-help">Las altas automáticas requieren permiso para crear personas y consultar el padrón de la organización.</p><?php endif; ?>
        <p class="ux-help">Altas automáticas revisa todo el archivo y crea sólo personas nuevas sin coincidencias. Duplicados, datos inválidos y referencias sin resolver quedan para revisión manual. No asigna participaciones electorales.</p>
        <label class="block my-4"><input type="checkbox" id="import-detect" checked> Revisar posibles duplicados</label>
        <p>Las reglas de CURP y clave de elector se aplican siempre.</p>
        <button class="btn bg-primary text-white" type="submit">Continuar con la modalidad seleccionada</button>
    </form>
    <div id="person-import-preview" class="my-4 overflow-y-auto max-h-[480px]"></div>
    <p id="person-import-summary" role="status"></p>
    <p id="person-import-planned" role="status"></p>
    <div id="person-import-result-pages" class="hidden my-3 ux-actions"><button id="person-import-results-first" type="button" class="btn">Primer lote de resultados</button><button id="person-import-results-next" type="button" class="btn">Siguiente lote de resultados</button></div>
    <button type="button" id="person-import-automatic" class="btn bg-primary text-white hidden">Continuar altas automáticas</button>
    <button type="button" id="person-import-review" class="btn hidden">Revisar filas pendientes</button>
    <button type="button" id="person-import-next" class="btn bg-primary text-white hidden">Guardar decisiones y revisar siguiente lote</button>
    <button type="button" id="person-import-confirm" class="btn bg-primary text-white hidden">Confirmar importación</button>
</dialog>

<?php endif; ?>
