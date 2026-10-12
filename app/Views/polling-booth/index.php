<?php
    $title = "Casillas";
    $section = "Casillas";
    require_once __DIR__.'/../layout/title.php';
?>
<section class="card p-5">
    <h6>Administración de casillas</h6>
    <p class="ux-help">La gestión de casillas todavía no está disponible. Puedes consultar y registrar secciones electorales desde el catálogo actual.</p>
    <div class="ux-actions"><a class="btn" href="<?= escape_html(base_url('sections')); ?>">Consultar secciones</a></div>
</section>
<script src="<?= escape_html(asset('js/polling-booth/index.js')); ?>"></script>
<script>var currentLink = <?= json_script(base_url('polling-booth')); ?>;</script>