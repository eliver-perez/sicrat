<?php

// Pruebas HTTP/BD: sólo utiliza la base desechable de integration.php --phase3.
use App\Services\Common\Values;

$assignmentStart = $checks;
$assignmentElections = [];
foreach (['Principal', 'Otra elección'] as $name) {
    $assignmentElections[] = phase3Ok('/api/organizations/' . $organizationIds[0] . '/electoral-process', 'POST', [
        'process' => 'Asignaciones ' . $name,
        'type' => 1,
        'character' => 1,
    ])['id'];
}
$assignmentElection = $assignmentElections[0];
phase3Ok('/api/auth/election', 'POST', ['process' => $assignmentElection]);
phase3Ok('/api/organizations/' . $organizationIds[0] . '/modules/jornada_electoral', 'PUT', [
    'authorized' => 1,
    'selected' => 1,
]);
$assignmentConfig = [
    'zona' => 'America/Monterrey',
    'captura_local' => gmdate('Y-m-d\TH:i', time() - 3600),
    'politica_inicial' => 'validar',
    'foto_obligatoria' => 0,
    'denominador' => 'total_acta',
    'fecha_eleccion' => date('Y-m-d'),
];
phase3Ok('/api/jornada/configuration', 'PUT', $assignmentConfig);
$assignmentSection = Values::uuidText($connection->query('SELECT uuid FROM secciones_electorales ORDER BY id LIMIT 1')->fetchColumn());
$assignmentBooths = [];
foreach (['Uno', 'Dos'] as $name) {
    $assignmentBooths[] = phase3Ok('/api/jornada/booths', 'POST', [
        'seccion' => $assignmentSection,
        'nombre' => 'Asignación ' . $name,
        'tipo' => 'BASICA',
    ])['uuid'];
}
phase3Ok('/api/auth/election', 'POST', ['process' => $assignmentElections[1]]);
phase3Ok('/api/jornada/configuration', 'PUT', $assignmentConfig);
$assignmentForeignBooth = phase3Ok('/api/jornada/booths', 'POST', [
    'seccion' => $assignmentSection,
    'nombre' => 'Casilla de otra elección',
    'tipo' => 'BASICA',
])['uuid'];
phase3Ok('/api/auth/election', 'POST', ['process' => $assignmentElection]);

$assignmentRoles = [];
foreach (['eleccion', 'organizacion'] as $scope) {
    $role = phase3Ok('/api/roles', 'POST', ['name' => 'Administrador asignador ' . $scope])['id'];
    phase3Ok('/api/roles/' . $role . '/permissions', 'PUT', [
        'election' => $assignmentElection,
        'permission' => 'jornada.asignar_capturistas',
        'scope' => $scope,
        'value' => 1,
    ]);
    $assignmentRoles[$scope] = $role;
}
$assignmentEmptyRole = phase3Ok('/api/roles', 'POST', ['name' => 'Cuenta sin captura ni asignación'])['id'];
$assignmentUsers = [];
foreach (['eleccion', 'organizacion', 'destino-1', 'destino-2'] as $key) {
    $user = phase3Ok('/api/users', 'POST', [
        'organization' => $organizationIds[0],
        'process' => $assignmentElection,
        'username' => 'asignaciones-' . $key,
        'password' => 'Asignaciones seguras prueba',
        'name' => 'Cuenta ' . $key,
        'type' => 8,
    ])['id'];
    phase3Ok('/api/users/' . $user . '/accesses', 'POST', [
        'process' => $assignmentElection,
        'type' => $assignmentRoles[$key] ?? $assignmentEmptyRole,
    ]);
    phase3Ok('/api/users/' . $user . '/accesses', 'POST', [
        'process' => $assignmentElections[1],
        'type' => $assignmentEmptyRole,
    ]);
    $assignmentUsers[$key] = $user;
    $client = 'assignment-' . $key;
    $phase3Tokens[$client] = login($client, 'asignaciones-' . $key, 'Asignaciones seguras prueba');
    phase3Ok('/api/auth/election', 'POST', ['process' => $assignmentElection], $client);
}
$assignmentTargets = [$assignmentUsers['destino-1'], $assignmentUsers['destino-2']];
$assignmentProcessStatement = $connection->prepare('SELECT id FROM procesos_electorales WHERE uuid=?');
$assignmentProcessStatement->execute([Values::binary($assignmentElection)]);
$assignmentProcessId = (int) $assignmentProcessStatement->fetchColumn();
$assignmentUserId = static function (string $uuid) use ($connection): int {
    $statement = $connection->prepare('SELECT id FROM usuarios WHERE uuid=?');
    $statement->execute([Values::binary($uuid)]);
    return (int) $statement->fetchColumn();
};
$assignmentPermissionRepository = new App\Repositories\PermissionRepository($connection);
foreach (['eleccion', 'organizacion', 'destino-1', 'destino-2'] as $key) {
    $rights = $assignmentPermissionRepository->effective($assignmentUserId($assignmentUsers[$key]), $assignmentProcessId)['rights'];
    verify(!in_array('resultados.capturar', $rights, true), 'La prueba no depende de permisos de captura: ' . $key);
    if (isset($assignmentRoles[$key])) {
        verify(in_array('jornada.asignar_capturistas', $rights, true), 'El administrador hereda exactamente el permiso de asignar: ' . $key);
        verify(!in_array('jornada.configurar', $rights, true), 'No se exige configurar Jornada para asignar: ' . $key);
    }
}

// Mismo request del frontend: sin elección ni actor en el cuerpo, sólo cuentas, casillas y decisión.
$assignmentPayload = ['usuarios' => $assignmentTargets, 'casillas' => $assignmentBooths, 'activo' => 1];
$assignmentSnapshot = static function () use ($connection, $assignmentProcessId): array {
    $statement = $connection->prepare('SELECT * FROM jornada_capturistas WHERE proceso=? ORDER BY id');
    $statement->execute([$assignmentProcessId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
foreach (['assignment-eleccion', 'assignment-organizacion', 'admin'] as $client) {
    $result = phase3Ok('/api/jornada/assignments', 'PUT', $assignmentPayload, $client);
    verify($result['asignaciones'] === 4, 'Administrador/Superadministrador asigna sin evaluar su permiso en el capturista: ' . $client);
    $assigned = $assignmentSnapshot();
    verify(count($assigned) === 4 && array_sum(array_column($assigned, 'activo')) === 4, 'Persisten las cuatro asignaciones sin duplicados');
    $expectedActor = $client === 'admin' ? 1 : $assignmentUserId($assignmentUsers[str_replace('assignment-', '', $client)]);
    verify(array_unique(array_map('intval', array_column($assigned, 'asignado_por'))) === [$expectedActor], 'Se registra el usuario autenticado que asigna');
    phase3Ok('/api/jornada/assignments', 'PUT', array_replace($assignmentPayload, ['activo' => 0]), $client);
    $retired = $assignmentSnapshot();
    verify(array_sum(array_column($retired, 'activo')) === 0, 'Puede retirar asignaciones con la misma autorización');
    verify(count(array_filter(array_column($retired, 'retirado_en'))) === 4, 'Retirada conserva los registros y su fecha');
    phase3Ok('/api/jornada/assignments', 'PUT', $assignmentPayload, $client);
    $reactivated = $assignmentSnapshot();
    verify(array_column($reactivated, 'id') === array_column($assigned, 'id'), 'Reactivar reutiliza las asignaciones existentes');
    verify(array_sum(array_column($reactivated, 'activo')) === 4 && !array_filter(array_column($reactivated, 'retirado_en')), 'Reactivación restablece estado y fecha');
}

// Las asignaciones no conceden el permiso de capturar ni el de administrar asignaciones.
$assignmentBeforeDenials = $assignmentSnapshot();
foreach ([1, 0] as $active) {
    phase3Status(403, '/api/jornada/assignments', 'PUT', array_replace($assignmentPayload, ['activo' => $active]), 'assignment-destino-1');
}
phase3Status(403, '/api/jornada/drafts', 'POST', [
    'casilla' => $assignmentBooths[0],
    'clave' => Values::uuid(),
], 'assignment-destino-1');
verify($assignmentSnapshot() === $assignmentBeforeDenials, 'Denegaciones no cambian las asignaciones');
verify(!in_array('resultados.capturar', $assignmentPermissionRepository->effective(
    $assignmentUserId($assignmentUsers['destino-1']), $assignmentProcessId
)['rights'], true), 'Asignar no concede permisos de captura');

// Aun con alcance de organización y aun como Superadministrador, la elección procede de sesión.
foreach (['assignment-eleccion', 'assignment-organizacion', 'admin'] as $client) {
    phase3Status(404, '/api/jornada/assignments', 'PUT', [
        'usuarios' => [$assignmentTargets[0]],
        'casillas' => [$assignmentBooths[0], $assignmentForeignBooth],
        'activo' => 0,
    ], $client);
    verify($assignmentSnapshot() === $assignmentBeforeDenials, 'La mezcla de elecciones revierte también la casilla válida');
}
phase3Status(422, '/api/jornada/assignments', 'PUT', array_replace($assignmentPayload, [
    'process' => $assignmentElections[1],
]), 'assignment-eleccion');
phase3Ok('/api/auth/election', 'POST', ['process' => $assignmentElections[1]], 'assignment-eleccion');
phase3Status(403, '/api/jornada/assignments', 'PUT', [
    'usuarios' => [$assignmentTargets[0]],
    'casillas' => [$assignmentForeignBooth],
    'activo' => 1,
], 'assignment-eleccion');
phase3Ok('/api/auth/election', 'POST', ['process' => $assignmentElection], 'assignment-eleccion');
verify((int) $connection->query('SELECT COUNT(*) FROM jornada_capturistas cp JOIN jornada_casillas jc ON jc.id=cp.casilla WHERE cp.proceso<>jc.proceso OR cp.organizacion<>jc.organizacion')->fetchColumn() === 0, 'No hay asignaciones cruzadas entre elecciones u organizaciones');

// Mantiene validaciones de alcance, revocación individual, cuenta y acceso electoral activos.
phase3Ok('/api/roles/' . $assignmentRoles['eleccion'] . '/permissions', 'PUT', [
    'election' => $assignmentElection,
    'permission' => 'jornada.asignar_capturistas',
    'scope' => 'propio',
    'value' => 1,
]);
phase3Status(403, '/api/jornada/assignments', 'PUT', $assignmentPayload, 'assignment-eleccion');
phase3Grant($assignmentUsers['organizacion'], 'jornada.asignar_capturistas', $assignmentElection, 'organizacion', 0);
phase3Status(403, '/api/jornada/assignments', 'PUT', $assignmentPayload, 'assignment-organizacion');
phase3Ok('/api/users/' . $assignmentTargets[0] . '/accesses', 'POST', [
    'process' => $assignmentElection,
    'type' => $assignmentEmptyRole,
    'active' => 0,
]);
phase3Status(404, '/api/jornada/assignments', 'PUT', $assignmentPayload);
verify($assignmentSnapshot() === $assignmentBeforeDenials, 'Cuenta sin acceso activo no modifica asignaciones');

$foreignAccount = phase3Ok('/api/users', 'POST', [
    'organization' => $organizationIds[1],
    'process' => $processIds[1],
    'username' => 'asignacion-otra-organizacion',
    'password' => 'Asignaciones seguras prueba',
    'name' => 'Cuenta de otra organización',
    'type' => 8,
])['id'];
phase3Status(404, '/api/jornada/assignments', 'PUT', array_replace($assignmentPayload, ['usuarios' => [$foreignAccount]]));
verify($assignmentSnapshot() === $assignmentBeforeDenials, 'No se asignan cuentas de otra organización');
phase3Ok('/api/auth/election', 'POST', ['process' => $promotionElection]);
echo 'Autorización de capturistas: ' . ($checks - $assignmentStart) . " comprobaciones HTTP/BD.\n";
