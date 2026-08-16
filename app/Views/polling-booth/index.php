<?php
    $title = "Casillas";
    $section = "Casillas";

    require_once __DIR__.'/../layout/title.php';
?>


                <div class="grid lg:grid-cols-12 gap-5 mb-5">
                    <div class="lg:col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Casillas</h6>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-hidden min-h-[480px] max-h-[480px]">
                                        <table id="table-sections" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Sección</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Casilla</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Tipo</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Ubicación</th>
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

                                <form id="form-register-polling-booth" no-validate action="javascript:RegisterSection()">
                                    <div class="grid lg:grid-cols-3 md:grid-cols-2 grid-cols-1 gap-x-5">
                                        <div class="col-span-3 mb-4">
                                            <label for="select-polling-booth-section" class="inline-block mb-2 font-medium">Sección</label>
                                                <select class="form-input"
                                                    id="select-polling-booth-section"
                                                    name="select-polling-booth-section"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="select-polling-booth-type" class="inline-block mb-2 font-medium">Tipo</label>
                                                <select class="form-input"
                                                    id="select-polling-booth-type"
                                                    name="select-polling-booth-type"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-polling-booth-number" class="inline-block mb-2 font-medium">Casilla</label>
                                            <input type="text" id="field-polling-booth-number" class="form-input" placeholder="Casilla" value="" readonly required>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-polling-booth-location" class="inline-block mb-2 font-medium">Ubicación</label>
                                            <input type="text" id="field-polling-booth-location" class="form-input" placeholder="Ubicación" value="" readonly required>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-polling-booth-address" class="inline-block mb-2 font-medium">Domicilio</label>
                                            <input type="text" id="field-polling-booth-address" class="form-input" placeholder="Domicilio" value="" readonly required>
                                        </div>

                                        <div class="lg:col-span-3 col-span-1">
                                            <div class="flex justify-end gap-2">
                                                <button id="btn-polling-booth-cancel" type="button" class="btn bg-danger text-white btn-sm hover:bg-danger-hbr hidden">Cancelar</span></button>
                                                <button id="btn-polling-booth-new" type="button" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr">Nueva Casilla</button>
                                                <button id="btn-polling-booth-register" type="submit" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr hidden">Registrar</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

<script src="<?= asset('js/polling-booth/index.js'); ?>"></script>

<script>
    var currentLink = '<?= base_url('polling-booth'); ?>';
</script>