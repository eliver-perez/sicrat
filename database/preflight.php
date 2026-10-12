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
    'integrantes_historicos' => 'SELECT COUNT(*) FROM estructura',
    'relaciones_estructura' => 'SELECT COUNT(*) FROM estructura_relaciones',
    'referencias_responsables' => 'SELECT COUNT(*) FROM personas_responsables',
    'grupos_curp_repetidos' => "SELECT COUNT(*) FROM (SELECT organizacion,curp FROM personas WHERE curp IS NOT NULL AND TRIM(curp)<>'' GROUP BY organizacion,curp HAVING COUNT(*)>1) coincidencias",
    'grupos_clave_repetidos' => "SELECT COUNT(*) FROM (SELECT organizacion,clave_elector FROM personas WHERE clave_elector IS NOT NULL AND TRIM(clave_elector)<>'' GROUP BY organizacion,clave_elector HAVING COUNT(*)>1) coincidencias",
    'accesos_otra_organizacion' => 'SELECT COUNT(*) FROM usuarios_procesos up JOIN usuarios u ON u.id=up.usuario JOIN procesos_electorales p ON p.id=up.proceso WHERE u.organizacion IS NULL OR u.organizacion<>p.organizacion',
    'participaciones_otra_organizacion' => 'SELECT COUNT(*) FROM personas_procesos pp JOIN personas p ON p.id=pp.persona JOIN procesos_electorales e ON e.id=pp.proceso WHERE p.organizacion<>e.organizacion',
    'auditoria_contexto_incompatible' => 'SELECT COUNT(*) FROM auditoria a JOIN procesos_electorales p ON p.id=a.proceso WHERE a.organizacion<>p.organizacion',
    'archivos_cargador_otra_organizacion' => "SELECT COUNT(*) FROM archivos a JOIN usuarios u ON u.id=a.subido_por JOIN usuarios_tipos t ON t.id=u.tipo_usuario WHERE u.organizacion<>a.organizacion AND t.codigo<>'superadmin'",
];
foreach ($queries as $label => $sql) echo $label . ': ' . $db->query($sql)->fetchColumn() . PHP_EOL;
$schema = new App\Core\Migrations($db);
if ($schema->columnExists('personas','codigo_persona')) {
    echo 'codigos_pendientes: ' . $db->query('SELECT COUNT(*) FROM personas WHERE codigo_persona IS NULL')->fetchColumn() . PHP_EOL;
    echo 'codigos_repetidos: ' . $db->query('SELECT COUNT(*) FROM (SELECT codigo_persona FROM personas WHERE codigo_persona IS NOT NULL GROUP BY codigo_persona HAVING COUNT(*)>1) coincidencias')->fetchColumn() . PHP_EOL;
}
if ($schema->columnExists('usuarios','persona')) {
    echo 'personas_con_varias_cuentas: ' . $db->query('SELECT COUNT(*) FROM (SELECT persona FROM usuarios WHERE persona IS NOT NULL GROUP BY persona HAVING COUNT(*)>1) conflictos')->fetchColumn() . PHP_EOL;
    echo 'estructura_sin_persona_vinculada: ' . $db->query('SELECT COUNT(*) FROM estructura e JOIN usuarios u ON u.id=e.usuario WHERE u.persona IS NULL')->fetchColumn() . PHP_EOL;
}
if ($schema->columnExists('archivos','estado')) {
    echo 'archivos_historicos_por_revisar: ' . $db->query("SELECT COUNT(*) FROM archivos WHERE estado='historico'")->fetchColumn() . PHP_EOL;
    echo 'archivos_activos_sin_integridad: ' . $db->query("SELECT COUNT(*) FROM archivos WHERE estado='activo' AND (sha256 IS NULL OR NOT EXISTS (SELECT 1 FROM archivos_variantes v WHERE v.archivo=archivos.id AND v.variante='normal'))")->fetchColumn() . PHP_EOL;
}
