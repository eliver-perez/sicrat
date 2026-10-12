<?php
    $title = "Dashboard";

    require_once __DIR__.'/../layout/title.php';
    // require_once __DIR__.'/index-template.php';
?>

<?php if (!empty($_SESSION['SICRAT_AVAILABLE_ROLES']) && \App\Core\Auth::processId() === null && !\App\Core\Auth::isSuperAdmin()): ?>
    <p class="p-4 text-default-700">Selecciona una elección de trabajo para utilizar los permisos de tu acceso.</p>
<?php endif; ?>

<section class="card p-5 mb-5"><h6>Bienvenido a SICRAT</h6><p class="ux-help">Selecciona tu elección de trabajo en el encabezado. Los accesos disponibles dependen de tus responsabilidades y permisos.</p></section>
<div class="ux-module-grid mb-5">
    <?php foreach ([
        ['persons','Personas','Consulta el padrón de la organización y la participación electoral.','personas.ver'],
        ['users','Usuarios y accesos','Administra las cuentas y sus accesos a elecciones.','usuarios.administrar'],
        ['administration','Configuración electoral','Configura niveles, roles, permisos y módulos.','permisos.administrar'],
        ['organizations','Organizaciones','Consulta organizaciones y administra sus elecciones.','superadmin'],
    ] as [$route,$label,$description,$right]): if (\App\Auth\Authorization::canAny([$right])): ?>
    <section class="card p-5"><h6><?= escape_html($label) ?></h6><p class="ux-help"><?= escape_html($description) ?></p><a class="btn" href="<?= escape_html(base_url($route)) ?>">Abrir <?= escape_html(mb_strtolower($label)) ?></a></section>
    <?php endif; endforeach; ?>
    <?php if ($structureEnabled ?? false): ?><section class="card p-5"><h6>Estructura organizacional</h6><p class="ux-help">Consulta integrantes, responsables directos y equipos a cargo.</p><a class="btn" href="<?= escape_html(base_url('structure')) ?>">Abrir estructura</a></section><?php endif; ?>
    <section class="card p-5"><h6>Servicios y notificaciones</h6><p class="ux-help">Revisa tus avisos y los servicios que tienes autorizados.</p><a class="btn" href="<?= escape_html(base_url('services')) ?>">Abrir servicios</a></section>
</div>
<script src="<?= escape_html(asset('js/dashboard/index.js')); ?>"></script>

<script>
    var currentLink = <?= json_script(base_url('')); ?>;
    var callBack = <?= json_script(is_string($_GET['callBack'] ?? null) ? $_GET['callBack'] : ''); ?>;
</script>