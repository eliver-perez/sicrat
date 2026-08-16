<?php
    $title = "Organizaciones";
    $section = "Organizaciones";

    require_once __DIR__.'/../layout/title.php';
    require_once __DIR__.'/modal_organization.php';
?>


                <div class="grid lg:grid-cols-12 gap-5 mb-5">
                    <div class="lg:col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Organizaciones</h6>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-hidden min-h-[480px] max-h-[480px]">
                                        <table id="table-organizations" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Organización</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Representante</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Activo</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Fecha Registro</th>
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

                                <form id="form-register-organization" no-validate action="javascript:RegisterOrganization()">
                                    <div class="grid lg:grid-cols-3 md:grid-cols-2 grid-cols-1 gap-x-5">
                                        <div class="col-span-3 mb-4">
                                            <label for="field-organization" class="inline-block mb-2 font-medium">Organización</label>
                                            <input type="text" id="field-organization" class="form-input" placeholder="Organización" value="" readonly required>
                                        </div>

                                        <div class="col-span-2 mb-4">
                                            <label for="field-contact" class="inline-block mb-2 font-medium">Contacto</label>
                                            <input type="text" id="field-contact" class="form-input" placeholder="Contacto" value="" readonly required>
                                        </div>

                                        <div class="col-span-1 mb-4">
                                            <label for="field-phone" class="inline-block mb-2 font-medium">Teléfono</label>
                                            <input type="text" id="field-phone" class="form-input" placeholder="Teléfono" value="" readonly>
                                        </div>

                                        <div class="col-span-2 mb-4">
                                            <label for="field-email" class="inline-block mb-2 font-medium">E-Mail</label>
                                            <input type="text" id="field-email" class="form-input" placeholder="E-Mail" value="" readonly>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <input id="chk-active" class="form-checkbox size-4 !border-primary/20 !bg-primary/10 checked:!bg-primary" type="checkbox" value="" disabled checked>
                                            <label for="chk-active" class="align-middle">
                                                Activo
                                            </label>
                                        </div>

                                        <div class="lg:col-span-3 col-span-1">
                                            <div class="flex justify-end gap-2">
                                                <button id="btn-organization-cancel" type="button" class="btn bg-danger text-white btn-sm hover:bg-danger-hbr hidden">Cancelar</span></button>
                                                <button id="btn-organization-new" type="button" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr">Nueva Organización</button>
                                                <button id="btn-organization-register" type="submit" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr hidden">Registrar</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>


                    <div class="lg:col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Procesos Electorales</h6>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-hidden min-h-[480px] max-h-[480px]">
                                        <table id="table-electoral-process" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Proceso</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Tipo</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Caracter</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Activo</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Fecha Registro</th>
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

                                <form id="form-register-electoral-process" no-validate action="javascript:RegisterElectoralProcess()">
                                    <div class="grid lg:grid-cols-3 md:grid-cols-2 grid-cols-1 gap-x-5">
                                        <div class="col-span-3 mb-4">
                                            <label for="field-electoral-process" class="inline-block mb-2 font-medium">Proceso</label>
                                            <input type="text" id="field-electoral-process" class="form-input" placeholder="Proceso" value="" readonly required>
                                        </div>

                                        <div class="col-span-3 mb-4">
                                            <label for="field-electoral-process-type" class="inline-block mb-2 font-medium">Tipo</label>
                                                <select class="form-input"
                                                    id="select-electoral-process-type"
                                                    name="select-electoral-process-type"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="col-span-2 mb-4">
                                            <label for="field-electoral-process-character" class="inline-block mb-2 font-medium">Caracter</label>
                                                <select class="form-input"
                                                    id="select-electoral-process-character"
                                                    name="select-electoral-process-character"
                                                    data-choices data-choices-search-false data-choices-removeItem
                                                    disabled>
                                                </select>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <input id="chk-electoral-process-status" class="form-checkbox size-4 !border-primary/20 !bg-primary/10 checked:!bg-primary" type="checkbox" value="" disabled checked>
                                            <label for="chk-electoral-process-status" class="align-middle">
                                                Activo
                                            </label>
                                        </div>

                                        <div class="lg:col-span-3 col-span-1">
                                            <div class="flex justify-end gap-2">
                                                <button id="btn-electoral-process-cancel" type="button" class="btn bg-danger text-white btn-sm hover:bg-danger-hbr hidden">Cancelar</span></button>
                                                <button id="btn-electoral-process-new" type="button" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr">Nuevo Proceso</button>
                                                <button id="btn-electoral-process-register" type="submit" class="btn bg-primary text-white btn-sm hover:bg-primary-hbr hidden">Registrar</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

<script src="<?= asset('js/organizations/index.js'); ?>"></script>

<script>
    var currentLink = '<?= base_url('organizations'); ?>';
</script>