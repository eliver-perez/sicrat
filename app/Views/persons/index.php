<?php
    $title = "Personas";
    $section = "Personas";

    require_once __DIR__.'/../layout/title.php';
?>


                <div class="grid lg:grid-cols-12 gap-5 mb-5">

                    <div class="lg:col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Personas</h6>

                                <div class="flex gap-3 items-center">
                                    <div class="relative">
                                        <select class="form-input form-input-sm"
                                            id="select-filter-section" aria-label="Filtrar por sección"
                                            name="select-filter-section"
                                            data-choices data-choices-search-false data-choices-removeItem>
                                        </select>
                                    </div>

                                    <div class="relative">
                                        <input id="field-filter-search" aria-label="Buscar personas por código o nombre" type="text" class="form-input form-input-sm ps-9" placeholder="Buscar por código o nombre">
                                        <div class="absolute inset-y-0 start-0 flex items-center ps-3">
                                            <i data-lucide="search" class="size-3.5 text-default-500"></i>
                                        </div>
                                    </div>

                                    <button id="btn-filter-search" class="btn btn-sm bg-transparent text-primary border border-dashed border-primary hover:bg-primary/20 ">
                                        <i data-lucide="search" class="size-4 me-1"></i>Buscar
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-y-auto min-h-[480px] max-h-[480px]">
                                        <table id="table-persons" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Sección</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Nombre</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Fecha de Nacimiento</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Dirección</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Género</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Teléfono</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Teléfono Móvil</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Correo</th>
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
                                <h6 class="card-title mb-4">Alta rápida</h6>

                                <form id="form-register-persons" no-validate action="javascript:RegisterPerson()">
                                    <div class="grid gap-x-5">
                                        <div class="col-span-2 mb-4">
                                            <label for="select-persons-organization" class="inline-block mb-2 font-medium">Organización</label>
                                                <select class="form-input"
                                                    id="select-persons-organization"
                                                    name="select-persons-organization"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-1 mb-4">
                                            <label for="select-persons-section" class="inline-block mb-2 font-medium">Sección</label>
                                                <select class="form-input"
                                                    id="select-persons-section"
                                                    name="select-persons-section"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="select-persons-gender" class="inline-block mb-2 font-medium">Género</label>
                                                <select class="form-input"
                                                    id="select-persons-gender"
                                                    name="select-persons-gender"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-persons-name" class="inline-block mb-2 font-medium">Nombre</label>
                                            <input type="text" id="field-persons-name" class="form-input" placeholder="Nombre" value="" readonly required>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-persons-last-name" class="inline-block mb-2 font-medium">Apellido Paterno</label>
                                            <input type="text" id="field-persons-last-name" class="form-input" placeholder="Apellido Paterno" value="" readonly>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-persons-last-name-2" class="inline-block mb-2 font-medium">Apellido Materno</label>
                                            <input type="text" id="field-persons-last-name-2" class="form-input" placeholder="Apellido Materno" value="" readonly>
                                        </div>

                                        <details class="mb-4">
                                            <summary>Más datos (opcionales)</summary>
                                            <?php foreach (['phone' => 'Teléfono', 'email' => 'Correo', 'curp' => 'CURP', 'elector_key' => 'Clave de elector'] as $field => $label): ?>
                                            <label class="block mt-3"><?= escape_html($label) ?><input id="person-quick-<?= escape_html($field) ?>" class="form-input person-quick-optional" type="text" readonly></label>
                                            <?php endforeach; ?>
                                        </details>

                                        <div class="lg:col-span-3 col-span-1">
                                            <div class="flex justify-end gap-2">
                                                <button id="btn-persons-cancel" type="button" class="btn btn-sm hidden">Cancelar</button>
                                                <button id="btn-persons-new" type="button" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr">Nueva Persona</button>
                                                <button id="btn-persons-register" type="submit" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr hidden">Registrar</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>


                </div>

<?php require __DIR__ . '/phase2.php'; ?>
<script src="<?= escape_html(asset('js/persons/index.js')); ?>"></script>
<script src="<?= escape_html(asset('js/persons/identity.js')); ?>"></script>
<script src="<?= escape_html(asset('js/persons/activity.js')); ?>"></script>
<script src="<?= escape_html(asset('js/persons/import.js')); ?>"></script>

<script>
    var currentLink = <?= json_script(base_url('persons')); ?>;
</script>
