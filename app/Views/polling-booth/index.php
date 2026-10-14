<?php
    $title = "Casillas";
    $section = "Casillas";
    require_once __DIR__.'/../layout/title.php';
?>
<section class="card p-5">
    <div class="ux-actions"><h6>Casillas de la elección</h6><button id="booths-new" class="btn bg-primary text-white" hidden>Nueva casilla</button></div>
    <p id="booths-message" class="ux-help">Cargando casillas…</p>
    <form id="booths-filter" class="ux-search-row"><input class="form-input" name="search" placeholder="Buscar casilla o sección" aria-label="Buscar casilla o sección"><button class="btn">Buscar</button></form>
    <div id="booths-list"></div>
</section>
<dialog id="booths-dialog"><div class="flex justify-between"><h6 id="booths-title">Casilla</h6><button class="btn" data-close="booths-dialog" type="button">Cerrar</button></div><div id="booths-form"></div></dialog>
<script src="<?= escape_html(asset('js/phase3.js')); ?>"></script>
<script src="<?= escape_html(asset('js/operations/core.js')); ?>"></script>
<script src="<?= escape_html(asset('js/polling-booth/index.js')); ?>"></script>
<script>var currentLink = <?= json_script(base_url('polling-booth')); ?>;</script>
