<?php
    $title = "Secciones";
    $section = "Secciones";

    require_once __DIR__.'/../layout/title.php';
?>


                <div class="grid lg:grid-cols-12 gap-5 mb-5">
                    <div class="lg:col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Secciones</h6>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-hidden min-h-[480px] max-h-[480px]">
                                        <table id="table-sections" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Sección</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Municipio</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Localidad</th>
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

                                <form id="form-register-sections" no-validate action="javascript:RegisterSection()">
                                    <div class="grid lg:grid-cols-3 md:grid-cols-2 grid-cols-1 gap-x-5">
                                        <div class="col-span-3 mb-4">
                                            <label for="select-sections-state" class="inline-block mb-2 font-medium">Estado</label>
                                                <select class="form-input"
                                                    id="select-sections-state"
                                                    name="select-sections-state"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="select-sections-municipality" class="inline-block mb-2 font-medium">Municipio</label>
                                                <select class="form-input"
                                                    id="select-sections-municipality"
                                                    name="select-sections-municipality"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-sections-section" class="inline-block mb-2 font-medium">Sección</label>
                                            <input type="text" id="field-sections-section" class="form-input" placeholder="Sección" value="" readonly required>
                                        </div>

                                        <div class="lg:col-span-3 col-span-1">
                                            <div class="flex justify-end gap-2">
                                                <button id="btn-sections-cancel" type="button" class="btn bg-danger text-white btn-sm hover:bg-danger-hbr hidden">Cancelar</span></button>
                                                <button id="btn-sections-new" type="button" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr">Nueva Sección</button>
                                                <button id="btn-sections-register" type="submit" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr hidden">Registrar</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

<script src="<?= asset('js/sections/index.js'); ?>"></script>

<script>
    var currentLink = '<?= base_url('sections'); ?>';
</script>