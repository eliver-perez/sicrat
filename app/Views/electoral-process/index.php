<?php
    $title = "Procesos Electorales";
    $section = "Procesos Electorales";

    require_once __DIR__.'/../layout/title.php';
?>


                <div class="grid lg:grid-cols-12 gap-5 mb-5">
                    <div class="lg:col-span-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">Procesos Electorales</h6>
                            </div>

                            <div class="overflow-x-auto">
                                <div class="min-w-full inline-block align-middle">
                                    <div class="overflow-y-auto min-h-[480px] max-h-[480px]">
                                        <table id="table-electoral-process" class="min-w-full divide-y divide-default-200 dark:divide-white/14">
                                            <thead class="bg-default-150">
                                                <tr class="text-default-600">
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Organización</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Proceso</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Tipo</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Carácter</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Activo</th>
                                                    <th scope="col" class="px-3.5 py-3 text-start text-sm font-medium">Fecha de registro</th>
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


                    <section class="lg:col-span-4 card p-5">
                        <h6>Crear una elección</h6><p class="ux-help">El alta se realiza desde la organización a la que pertenece la elección.</p>
                        <div class="ux-actions"><button id="btn-electoral-process-new" type="button" class="btn bg-primary text-white">Ir a Organizaciones</button></div>
                    </section>
                </div>

<script src="<?= escape_html(asset('js/electoral-process/index.js')); ?>"></script>

<script>
    var currentLink = <?= json_script(base_url('electoral-process')); ?>;
</script>