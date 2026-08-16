<?php
    $title = "Personas";
    $section = "Personas";

    require_once __DIR__.'/../layout/title.php';
?>


                <div class="flex flex-row lg:flex-col-reverse lg:items-center gap-5 mb-5">

                    <div class="w-2/3 lg:w-full">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Personas</h6>

                                <div class="flex gap-3 items-center">
                                    <div class="relative">
                                        <select class="form-input form-input-sm"
                                            id="select-filter-section"
                                            name="select-filter-section"
                                            data-choices data-choices-search-false data-choices-removeItem>
                                        </select>
                                    </div>

                                    <div class="relative">
                                        <input id="field-filter-search" type="text" class="form-input form-input-sm ps-9" placeholder="Busqueda....">
                                        <div class="absolute inset-y-0 start-0 flex items-center ps-3">
                                            <i data-lucide="search" class="size-3.5 text-default-500"></i>
                                        </div>
                                    </div>

                                    <button id="btn-filter-search" class="btn btn-sm bg-transparent text-primary border border-dashed border-primary hover:bg-primary/20 hidden lg:flex">
                                        <i data-lucide="search" class="size-4 me-1"></i>Buscar
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-hidden min-h-[480px] max-h-[480px]">
                                        <table id="table-persons" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Sección</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Nombre</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Fecha de Nacimiento</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Dirección</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Genero</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Teléfono</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Teléfono Móvil</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">E-Mail</th>
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

                    
                    <div class="w-1/3 lg:w-full">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title mb-4">Detalles</h6>

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
                                            <label for="select-persons-gender" class="inline-block mb-2 font-medium">Genero</label>
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
                                            <input type="text" id="field-persons-last-name" class="form-input" placeholder="Apellido Paterno" value="" readonly required>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-persons-last-name-2" class="inline-block mb-2 font-medium">Apellido Materno</label>
                                            <input type="text" id="field-persons-last-name-2" class="form-input" placeholder="Apellido Materno" value="" readonly>
                                        </div>

                                        <div class="lg:col-span-3 col-span-1">
                                            <div class="flex justify-end gap-2">
                                                <button id="btn-persons-cancel" type="button" class="btn bg-danger text-white btn-sm hover:bg-danger-hbr hidden">Cancelar</span></button>
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

<script src="<?= asset('js/persons/index.js'); ?>"></script>

<script>
    var currentLink = '<?= base_url('persons'); ?>';
</script>