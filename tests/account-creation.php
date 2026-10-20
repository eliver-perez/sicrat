<?php

// Regresión: la cuenta automática utiliza la configuración, sin comparar privilegios manuales.
$accountRole = phase3Ok('/api/roles', 'POST', [
    'name' => 'Rol cuenta preconfigurado',
])['id'];
phase3Ok('/api/roles/' . $accountRole . '/permissions', 'PUT', [
    'context' => 'global',
    'permission' => 'personas.editar',
    'scope' => 'organizacion',
    'value' => 1,
]);
phase3Ok('/api/roles/' . $accountRole . '/permissions', 'PUT', [
    'election' => $lrElection,
    'permission' => 'permisos.administrar',
    'scope' => 'eleccion',
    'value' => 1,
]);
$accountLevel = phase3Ok('/api/structure-levels', 'POST', [
    'name' => 'Integrante con cuenta',
    'order' => 5,
    'associated_role' => $accountRole,
])['id'];
$accountOperatorPerson = phase3Ok('/api/persons', 'POST', [
    'organization' => $organizationIds[0],
    'name' => 'Responsable que crea cuentas',
])['id'];
$accountOperatorMember = phase3Ok('/api/structures/' . $lrStructure . '/members', 'POST', [
    'person' => $accountOperatorPerson,
    'levels' => [$lrElection => $lrLevels['general']],
])['id'];
$accountOperator = phase3Ok('/api/users', 'POST', [
    'organization' => $organizationIds[0],
    'process' => $lrElection,
    'person' => $accountOperatorPerson,
    'name' => 'Responsable que crea cuentas',
    'username' => 'cuentas-integrantes',
    'password' => 'Cuentas integrantes seguras',
])['id'];
phase3Ok('/api/users/' . $accountOperator . '/accesses', 'POST', [
    'process' => $lrElection,
    'type' => $lrRoles['manual'],
]);
foreach ([
    'usuarios.administrar' => 'descendientes',
    'personas.ver' => 'descendientes',
    'estructura.ver' => 'eleccion',
    'estructura.administrar' => 'descendientes',
] as $permission => $scope) {
    phase3Grant($accountOperator, $permission, $lrElection, $scope);
}
$phase3Tokens['account-operator'] = login(
    'account-operator',
    'cuentas-integrantes',
    'Cuentas integrantes seguras'
);
phase3Ok('/api/auth/election', 'POST', ['process' => $lrElection], 'account-operator');

$accountPeople = [];
$accountMembers = [];
foreach ([
    'automatic' => 'Alta automática autorizada',
    'browser' => 'Alta automática desde interfaz',
    'outside' => 'Integrante fuera de alcance',
    'missing' => 'Integrante sin rol para cuenta',
] as $key => $name) {
    $accountPeople[$key] = phase3Ok('/api/persons', 'POST', [
        'organization' => $organizationIds[0],
        'name' => $name,
    ])['id'];
    $accountMembers[$key] = phase3Ok('/api/structures/' . $lrStructure . '/members', 'POST', [
        'person' => $accountPeople[$key],
        'responsible' => $key === 'outside' ? null : $accountOperatorMember,
        'levels' => [$lrElection => $key === 'missing' ? $lrLevels['missing'] : $accountLevel],
    ])['id'];
}

$operatorStatement = $connection->query("SELECT id FROM usuarios WHERE usuario='cuentas-integrantes'");
$operatorId = (int) $operatorStatement->fetchColumn();
$permissionRepository = new App\Repositories\PermissionRepository($connection);
verify(
    !in_array('personas.editar', $permissionRepository->effective($operatorId, null)['rights'], true),
    'El creador carece del permiso global del rol configurado'
);
verify(
    !in_array('permisos.administrar', $permissionRepository->effective($operatorId, (int) $lrProcessId)['rights'], true),
    'El creador carece del permiso electoral del rol configurado'
);

$automaticData = [
    'organization' => $organizationIds[0],
    'process' => $lrElection,
    'person' => $accountPeople['automatic'],
    'name' => 'Alta automática autorizada',
    'username' => 'cuenta-automatica-autorizada',
    'password' => 'Cuenta automática segura',
    'type' => 1,
];
$automaticUser = phase3Ok('/api/users', 'POST', $automaticData, 'account-operator')['id'];
$automaticAccess = phase3Ok('/api/users/' . $automaticUser . '/accesses')['accesses'];
verify(
    $automaticAccess[0]['type'] === $accountRole,
    'Creador autorizado asigna exactamente el rol configurado, aunque sus privilegios sean distintos'
);

$outsideData = array_replace($automaticData, [
    'person' => $accountPeople['outside'],
    'username' => 'cuenta-fuera-de-alcance',
]);
phase3Status(404, '/api/users', 'POST', $outsideData, 'account-operator');
$missingData = array_replace($automaticData, [
    'person' => $accountPeople['missing'],
    'username' => 'cuenta-sin-rol-configurado',
]);
phase3Status(422, '/api/users', 'POST', $missingData, 'account-operator');

// Sin permiso para administrar cuentas, ni siquiera el rol preconfigurado permite el alta.
phase3Grant($accountOperator, 'usuarios.administrar', $lrElection, 'descendientes', 0);
$browserData = array_replace($automaticData, [
    'person' => $accountPeople['browser'],
    'username' => 'cuenta-sin-permiso',
]);
phase3Status(403, '/api/users', 'POST', $browserData, 'account-operator');

// La elección manual mantiene ambas comparaciones, aunque el cliente afirme que es automática.
phase3Grant($accountOperator, 'usuarios.administrar', $lrElection, 'eleccion');
$manualData = $automaticData;
unset($manualData['person']);
$manualData['username'] = 'cuenta-manual-global';
$manualData['type'] = $accountRole;
$manualData['automatic_role'] = true;
$manualResult = phase3Api('/api/users', 'POST', $manualData, 'account-operator');
verify(
    $manualResult['status'] === 403 && str_contains($manualResult['body'], 'permisos globales superiores'),
    'Selección manual conserva comparación de privilegios globales'
);
$manualData['username'] = 'cuenta-manual-electoral';
$manualData['type'] = $lrRoles['general'];
$manualResult = phase3Api('/api/users', 'POST', $manualData, 'account-operator');
verify(
    $manualResult['status'] === 403 && str_contains($manualResult['body'], 'permisos superiores'),
    'Selección manual conserva comparación de privilegios electorales'
);
phase3Grant($accountOperator, 'usuarios.administrar', $lrElection, 'descendientes');
verify(
    (int) $connection->query("SELECT COUNT(*) FROM usuarios WHERE usuario IN (
        'cuenta-fuera-de-alcance', 'cuenta-sin-rol-configurado', 'cuenta-sin-permiso',
        'cuenta-manual-global', 'cuenta-manual-electoral'
    )")->fetchColumn() === 0,
    'Operaciones rechazadas no dejan cuentas parciales'
);

file_put_contents($temporaryDirectory . '/account-creation.json', json_encode([
    'election' => $lrElection,
    'structure' => $lrStructure,
    'member' => $accountMembers['browser'],
    'role' => $accountRole,
], JSON_THROW_ON_ERROR));
