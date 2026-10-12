<?php
    $title = "Dashboard";

    require_once __DIR__.'/../layout/title.php';
    // require_once __DIR__.'/index-template.php';
?>

<?php if (!empty($_SESSION['SICRAT_AVAILABLE_ROLES']) && \App\Core\Auth::processId() === null && !\App\Core\Auth::isSuperAdmin()): ?>
    <p class="p-4 text-default-700">Selecciona una elección de trabajo para utilizar los permisos de tu acceso.</p>
<?php endif; ?>

<script src="<?= escape_html(asset('js/dashboard/index.js')); ?>"></script>

<script>
    var currentLink = <?= json_script(base_url('')); ?>;
    var callBack = <?= json_script(is_string($_GET['callBack'] ?? null) ? $_GET['callBack'] : ''); ?>;
</script>