<?php
    $title = "Usuarios";
    $section = "Usuarios";

    require_once __DIR__.'/../layout/title.php';
?>


                <div class="grid lg:grid-cols-12 gap-5 mb-5">
                    <div class="lg:col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Usuarios</h6>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-y-auto min-h-[480px] max-h-[480px]">
                                        <table id="table-users" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Nombre</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Usuario</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Correo</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Rol</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Estado</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Última Conexión</th><th scope="col">Acciones</th>
                                                </tr>
                                            </thead>

                                            <tbody class="divide-y divide-default-200 dark:divide-white/14">
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <div class="lg:col-span-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title mb-4">Detalles</h6>

                                <form id="form-register-users" no-validate action="javascript:RegisterUser()">
                                    <div class="grid lg:grid-cols-4 md:grid-cols-2 grid-cols-1 gap-x-5">
                                        <div class="lg:col-span-2 mb-4">
                                            <label for="field-users-username" class="inline-block mb-2 font-medium">Usuario</label>
                                            <input type="text" id="field-users-username" class="form-input" placeholder="Usuario" value="" readonly required>
                                        </div>

                                        <div class="lg:col-span-2 mb-4">
                                            <label for="field-users-password" class="inline-block mb-2 font-medium">Contraseña</label>
                                            <input type="password" autocomplete="new-password" id="field-users-password" class="form-input" placeholder="Contraseña" value="" readonly required>
                                        </div>

                                        <div class="lg:col-span-4 md:col-span-2 mb-4">
                                            <label for="select-users-type" class="inline-block mb-2 font-medium">Rol</label>
                                                <select class="form-input"
                                                    id="select-users-type"
                                                    name="select-users-type"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled
                                                    required>
                                                </select>
                                        </div>

                                        <div class="lg:col-span-2 mb-4">
                                            <label for="select-users-organization" class="inline-block mb-2 font-medium">Organización</label>
                                                <select class="form-input"
                                                    id="select-users-organization"
                                                    name="select-users-organization"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled
                                                    required>
                                                </select>
                                        </div>

                                        <div class="lg:col-span-2 mb-4">
                                            <label for="select-users-process" class="inline-block mb-2 font-medium">Proceso</label>
                                                <select class="form-input"
                                                    id="select-users-process"
                                                    name="select-users-process"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="lg:col-span-3 mb-4">
                                            <label for="field-users-email" class="inline-block mb-2 font-medium">Correo</label>
                                            <input type="text" id="field-users-email" class="form-input" placeholder="Correo" value="" readonly>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <input id="chk-users-status" class="form-checkbox size-4 !border-primary/20 !bg-primary/10 checked:!bg-primary" type="checkbox" value="" disabled checked>
                                            <label for="chk-users-status" class="align-middle">
                                                Activo
                                            </label>
                                        </div>

                                        <div class="lg:col-span-4 md:col-span-2 mb-4">
                                            <label for="field-users-name" class="inline-block mb-2 font-medium">Nombre</label>
                                            <input type="text" id="field-users-name" class="form-input" placeholder="Nombre" value="" readonly required>
                                        </div>

                                        <div class="lg:col-span-4 md:col-span-2 mb-4">
                                            <label for="field-users-last-name" class="inline-block mb-2 font-medium">Apellido Paterno</label>
                                            <input type="text" id="field-users-last-name" class="form-input" placeholder="Apellido Paterno" value="" readonly required>
                                        </div>

                                        <div class="lg:col-span-4 md:col-span-2 mb-4">
                                            <label for="field-users-last-name-2" class="inline-block mb-2 font-medium">Apellido Materno</label>
                                            <input type="text" id="field-users-last-name-2" class="form-input" placeholder="Apellido Materno" value="" readonly>
                                        </div>

                                        <div class="lg:col-span-4 md:col-span-2 col-span-1">
                                            <div class="flex justify-end gap-2">
                                                <button id="btn-users-cancel" type="button" class="btn btn-sm hidden">Cancelar</button>
                                                <button id="btn-users-new" type="button" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr">Nuevo Usuario</button>
                                                <button id="btn-users-register" type="submit" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr hidden">Registrar</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

<?php require __DIR__ . '/accesses.php'; ?>
<script src="<?= escape_html(asset('js/users/index.js')); ?>"></script>
<script src="<?= escape_html(asset('js/users/accesses.js')); ?>"></script>

<script>
    var currentLink = <?= json_script(base_url('users')); ?>;
</script>