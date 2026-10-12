<?php

declare(strict_types=1);

// Diagnóstico de lectura: solo muestra contadores, nunca datos personales o credenciales.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
$db = (new App\Core\Database())->getConnection();
$queries = [
    'personas' => 'SELECT COUNT(*) FROM personas',
    'usuarios' => 'SELECT COUNT(*) FROM usuarios',
    'grupos_curp_repetidos' => "SELECT COUNT(*) FROM (SELECT organizacion,curp FROM personas WHERE curp IS NOT NULL AND TRIM(curp)<>'' GROUP BY organizacion,curp HAVING COUNT(*)>1) coincidencias",
    'grupos_clave_repetidos' => "SELECT COUNT(*) FROM (SELECT organizacion,clave_elector FROM personas WHERE clave_elector IS NOT NULL AND TRIM(clave_elector)<>'' GROUP BY organizacion,clave_elector HAVING COUNT(*)>1) coincidencias",
    'accesos_otra_organizacion' => 'SELECT COUNT(*) FROM usuarios_procesos up JOIN usuarios u ON u.id=up.usuario JOIN procesos_electorales p ON p.id=up.proceso WHERE u.organizacion IS NULL OR u.organizacion<>p.organizacion',
    'participaciones_otra_organizacion' => 'SELECT COUNT(*) FROM personas_procesos pp JOIN personas p ON p.id=pp.persona JOIN procesos_electorales e ON e.id=pp.proceso WHERE p.organizacion<>e.organizacion',
];
foreach ($queries as $label => $sql) echo $label . ': ' . $db->query($sql)->fetchColumn() . PHP_EOL;
$schema = new App\Core\Migrations($db);
if ($schema->columnExists('personas','codigo_persona')) {
    echo 'codigos_pendientes: ' . $db->query('SELECT COUNT(*) FROM personas WHERE codigo_persona IS NULL')->fetchColumn() . PHP_EOL;
    echo 'codigos_repetidos: ' . $db->query('SELECT COUNT(*) FROM (SELECT codigo_persona FROM personas WHERE codigo_persona IS NOT NULL GROUP BY codigo_persona HAVING COUNT(*)>1) coincidencias')->fetchColumn() . PHP_EOL;
}
