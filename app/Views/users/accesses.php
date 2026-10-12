<dialog id="user-access-dialog" class="card p-5 w-full max-w-2xl" aria-label="Accesos y persona de la cuenta">
    <div class="flex justify-between mb-4"><h6 class="card-title">Accesos de la cuenta seleccionada</h6><button class="btn" type="button" onclick="document.getElementById('user-access-dialog').close()">Cerrar</button></div>
    <section class="ux-section"><h6>Accesos electorales</h6><p class="ux-help">Cada elección puede tener un rol y estado de acceso diferentes.</p><div id="user-access-list" class="mb-4 overflow-x-auto"></div><details id="user-access-editor"><summary class="btn">Agregar o editar acceso</summary>
    <form id="user-access-form" class="ux-form-grid my-3">
        <label>Elección <select id="user-access-process" class="form-input" required></select></label>
        <label>Rol en esta elección <select id="user-access-type" class="form-input" required></select></label>
        <label class="block my-3"><input type="checkbox" id="user-access-active" checked> Acceso activo</label>
        <button type="submit" class="btn bg-primary text-white">Guardar acceso</button>
    </form>
    </details></section>
    <form id="user-person-form" class="ux-section"><h6>Persona vinculada</h6><p class="ux-help">Vincula una ficha existente de la organización. Los integrantes pueden participar sin tener una cuenta.</p>
        <p id="user-person-current"></p>
        <label>Buscar persona por código o nombre <input id="user-person-search" class="form-input" type="search"></label>
        <button id="user-person-find" type="button" class="btn">Buscar</button>
        <label>Persona<select id="user-person-select" class="form-input"><option value="">Sin persona vinculada</option></select></label>
        <button type="submit" class="btn bg-primary text-white">Guardar vínculo</button>
    </form>
</dialog>
