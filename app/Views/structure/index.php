<?php
    $title = 'Estructura organizacional'; $section = $title;
    require __DIR__ . '/../layout/title.php';
?>
<p id="phase3-message" role="status" class="mb-4 text-primary"></p>
<div class="card p-5 mb-5" id="structure-workspace">
    <h6 class="card-title mb-3">Mi estructura / equipo</h6>
    <div class="ux-actions mb-4 structure-toolbar"><select id="structure-select" class="form-input" aria-label="Estructura organizacional"></select><button id="structure-create" class="btn bg-primary text-white" type="button">Nueva</button><button id="structure-clone" class="btn" type="button">Copiar</button><button id="structure-associate" class="btn" type="button">Asociar</button></div>
    <nav class="ux-tabs" aria-label="Modo de consulta"><button id="structure-hierarchy" class="btn" type="button" aria-pressed="true">Estructura</button><button id="structure-all" class="btn" type="button" aria-pressed="false">Todos los integrantes</button></nav>
    <nav id="structure-breadcrumb" aria-label="Ruta de la estructura" class="structure-breadcrumb"></nav>
    <div id="structure-navigation" class="ux-section" aria-live="polite"></div>
    <div class="ux-actions"><button id="structure-mine" class="btn" type="button">Mi equipo</button><button id="structure-roots" class="btn" type="button">Inicio de la estructura</button></div>
    <p id="structure-elections" class="mb-4"></p>
    <div class="ux-actions mb-4"><button id="structure-add" class="btn bg-primary text-white" type="button">Agregar</button><button id="structure-requests" class="btn" type="button">Solicitudes de transferencia</button><button id="structure-history" class="btn" type="button">Historial</button></div>
    <div id="structure-summary" class="structure-summary" aria-label="Resumen de la estructura" hidden></div>
    <form id="structure-flat-search-form" class="ux-search-row mb-3" hidden><label>Buscar integrante<input id="structure-flat-search" class="form-input" placeholder="Clave o nombre" maxlength="200" type="search"></label><button class="btn" type="submit">Buscar</button></form>
    <div id="structure-panels" class="structure-panels"><section aria-labelledby="structure-primary-title"><h6 id="structure-primary-title" class="mb-3">Integrantes</h6>
    <div class="overflow-x-auto"><table class="min-w-full"><thead><tr><th>Clave</th><th>Nombre</th><th>Nivel</th><th>Responsable directo</th><th>Equipo directo / total</th><th>Cuenta y estado</th><th>Acciones</th></tr></thead><tbody id="structure-members"></tbody></table></div>
    <div class="flex gap-3 mt-4"><button id="structure-previous" class="btn" type="button">Anterior</button><button id="structure-next" class="btn" type="button">Siguiente</button></div>
    </section><section id="structure-preview" aria-labelledby="structure-preview-title">
        <div class="ux-actions mb-3"><h6 id="structure-preview-title">Equipo directo</h6><button id="structure-preview-enter" type="button" class="btn" hidden>Entrar a este nivel</button></div>
        <p id="structure-preview-status" class="ux-help" role="status">Selecciona un integrante para consultar su equipo directo.</p>
        <div class="overflow-x-auto"><table class="min-w-full" id="structure-preview-table" hidden><thead><tr><th>Clave</th><th>Nombre</th><th>Nivel</th><th>Responsable directo</th><th>Equipo directo / total</th><th>Cuenta y estado</th><th>Acciones</th></tr></thead><tbody id="structure-preview-members"></tbody></table></div>
        <div id="structure-preview-pages" class="ux-actions mt-4" hidden><button id="structure-preview-previous" class="btn" type="button">Anterior</button><button id="structure-preview-next" class="btn" type="button">Siguiente</button></div>
    </section></div>
</div>
<dialog id="structure-container-dialog" class="card p-5 w-full max-w-xl"><form id="structure-container-form" class="grid gap-3"><h6 id="structure-container-title">Nueva estructura</h6><label id="structure-name-field">Nombre de la estructura<input name="name" class="form-input" maxlength="150" required></label><div id="structure-election-options"></div><button class="btn bg-primary text-white">Guardar</button><button type="button" data-close class="btn">Cerrar</button></form></dialog>
<dialog id="structure-add-dialog" class="card p-5 w-full max-w-xl"><form id="structure-add-form" class="grid gap-3"><h6 id="structure-add-title">Agregar integrante</h6>
    <div id="structure-person-search-box" class="ux-full"><div class="ux-search-row"><label>Buscar una persona existente<input id="structure-person-search" class="form-input" placeholder="Código o nombre"></label><button id="structure-person-find" class="btn" type="button">Buscar personas</button></div><select id="structure-person" hidden aria-label="Persona seleccionada"></select><div id="structure-person-results" class="overflow-x-auto mt-3" aria-live="polite"></div><div class="ux-actions mt-3"><button id="structure-person-previous" class="btn" type="button" disabled>Anterior</button><button id="structure-person-next" class="btn" type="button" disabled>Siguiente</button></div></div>
    <div id="structure-person-selected" class="ux-selected-person ux-full" hidden><span></span><button id="structure-person-change" class="btn" type="button">Cambiar persona</button></div>
    <details id="structure-quick-person" class="ux-full"><summary>Registrar una persona nueva</summary><div class="ux-form-grid mt-3"><label>Nombre<input name="new_name" class="form-input" maxlength="80"></label><label>Apellido<input name="new_last_name" class="form-input" maxlength="80"></label><label>Teléfono<input name="new_phone" class="form-input" maxlength="20"></label><div id="structure-duplicates" class="ux-full"></div><label class="ux-full"><input name="confirm_duplicate" type="checkbox"> Confirmo que esta persona aún no se encuentra registrada.</label></div></details>
    <div id="structure-responsible-search" class="ux-full"></div><label>Responsable directo<select name="responsible" class="form-input"></select></label><div id="structure-add-levels" class="ux-full ux-form-grid"></div><p class="ux-full">Participaciones electorales que se registrarán:</p><div id="structure-participations" class="ux-full"></div><button class="btn bg-primary text-white">Incorporar persona</button><button type="button" data-close class="btn">Cerrar</button></form></dialog>
<dialog id="structure-member-dialog" class="card p-5 w-full max-w-2xl"><h6 id="structure-member-name" class="mb-3"></h6><p id="structure-member-summary"></p><div id="structure-line" class="ux-section"></div><div id="structure-team" class="ux-section"></div><div id="structure-member-participations"></div>
    <button id="structure-coordination-more" class="btn" type="button" hidden>Ver más</button>
    <details id="structure-administration" class="ux-section"><summary>Operaciones administrativas</summary>
    <form id="structure-level-form" class="grid gap-3 mt-4"><div id="structure-member-levels"></div><button class="btn bg-primary text-white">Guardar niveles</button></form>
    <form id="structure-transfer-form" class="grid gap-3 ux-section"><h6>Transferir</h6><select name="mode" class="form-input" aria-label="Personas a transferir"><option value="integrante">Sólo integrante</option><option value="equipo">Integrante y equipo completo</option></select><label>Nuevo responsable directo<select name="destination" class="form-input"></select></label><label>Quién asumirá el equipo si mueves sólo al integrante<select name="replacement" class="form-input"></select></label><input name="reason" class="form-input" placeholder="Motivo de la transferencia" maxlength="500" required><button class="btn bg-primary text-white">Solicitar transferencia</button></form>
        <form id="structure-state-form" class="grid gap-3 ux-section">
            <h6>Estado del integrante</h6>

            <label>
                Estado
                <select name="active" class="form-input" aria-label="Cambiar estado del integrante">
                    <option value="0">Desactivar</option>
                    <option value="1">Reactivar</option>
                </select>
            </label>
            <label>
                Quién asumirá el equipo directo al desactivar
                <select name="replacement" class="form-input"></select>
            </label>
            <label>
                Responsable directo al reactivar
                <select name="responsible" class="form-input"></select>
            </label>

            <button type="submit" class="btn">Guardar estado</button>
        </form>
    <button id="structure-create-account" class="btn text-primary mt-4" type="button">Crear cuenta para esta persona</button></details><button type="button" data-close class="btn">Cerrar</button>
</dialog>
<dialog id="structure-account-dialog" class="card p-5 w-full max-w-xl">
    <form id="structure-account-form" class="grid gap-3">
        <h6>Crear cuenta para la persona existente</h6>

        <label class="ux-full">
            Usuario
            <input
                name="username"
                class="form-input"
                placeholder="Usuario"
                maxlength="60"
                required
            >
        </label>
        <label class="ux-full">
            Contraseña
            <input
                name="password"
                type="password"
                class="form-input"
                placeholder="Contraseña"
                autocomplete="new-password"
                required
            >
        </label>
        <div class="ux-full">
            <span class="ux-field-label">Rol</span>
            <p id="structure-account-role" class="ux-help" role="status"></p>
        </div>

        <div class="ux-modal-footer">
            <button id="structure-account-cancel" type="button" class="btn">Cancelar</button>
            <button type="submit" class="btn bg-primary text-white">Crear y vincular</button>
        </div>
        <button type="button" data-close class="btn">Cerrar</button>
    </form>
</dialog>
<dialog id="structure-list-dialog" class="card p-5 w-full max-w-2xl"><h6 id="structure-list-title" class="mb-4">Historial</h6><div id="structure-list-content" class="overflow-x-auto"></div><button id="structure-list-more" class="btn" type="button">Ver más</button><button type="button" data-close class="btn">Cerrar</button></dialog>
<link rel="stylesheet" href="<?= escape_html(asset('css/structure.css')); ?>">
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<link rel="stylesheet" href="<?= escape_html(asset('css/structure-assignment.css')); ?>">
<script src="<?= escape_html(asset('js/structure/assignment.js')); ?>"></script>
<script src="<?= escape_html(asset('js/structure/index.js')); ?>"></script>
<script>var currentLink = <?= json_script(base_url('structure')); ?>;</script>
