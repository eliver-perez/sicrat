<?php foreach (['core','forms','details','create'] as $operationScript): ?>
<script src="<?= escape_html(asset('js/operations/'.$operationScript.'.js')); ?>"></script>
<?php endforeach; ?>
