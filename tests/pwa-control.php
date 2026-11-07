<?php

declare(strict_types=1);

// Browser-test mutations, explicitly confined to integration.php's disposable database.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
$database = $argv[1] ?? '';
if (!preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
$db = new PDO('mysql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT') . ';dbname=' . $database . ';charset=utf8mb4', env('DB_USERNAME'), env('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$user = $db->query("SELECT id FROM usuarios WHERE usuario='pwa-a'")->fetchColumn();
if (!$user) throw new RuntimeException('PWA fixture required.');
switch ($argv[2] ?? '') {
    case 'revoke':
    case 'restore':
        $query = $db->prepare("UPDATE permisos_usuarios SET valor=?,f_actualizacion=NOW() WHERE usuario=? AND permiso='personas.ver'");
        $query->execute([$argv[2] === 'restore' ? 1 : 0, $user]);
        break;
    case 'disable':
        $db->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$user]);
        break;
    case 'rename':
        $db->exec("UPDATE estructuras_organizacionales SET nombre='Rama PWA actualizada',version=version+1 WHERE nombre='Rama PWA consultada'");
        break;
    case 'unassign':
        $db->exec("UPDATE estructuras_procesos ep JOIN estructuras_organizacionales e ON e.id=ep.estructura SET ep.activo=0 WHERE e.nombre='Rama PWA actualizada'");
        break;
    default:
        throw new RuntimeException('Unknown fixture action.');
}
