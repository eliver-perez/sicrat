<?php
    $title = "Dashboard";

    require_once __DIR__.'/../layout/title.php';
    // require_once __DIR__.'/index-template.php';
?>


<script src="<?= asset('js/dashboard/index.js'); ?>"></script>

<script>
    var currentLink = '<?= base_url(''); ?>';
    var callBack = '<?= isset($_GET['callBack']) ? $_GET['callBack'] : ''; ?>';
</script>