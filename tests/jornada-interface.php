<?php

use App\Services\Common\Values;

// Sólo fixtures en la base desechable del ejecutor de integración.
$interfaceElection = phase7Api('/api/organizations/' . $organizationIds[0] . '/electoral-process', 'POST', [
    'process' => 'Jornada interfaz ordenada',
    'type' => 1,
    'character' => 1,
])['id'];
foreach ($phase7Accounts as $account) {
    phase7Api('/api/users/' . $account . '/accesses', 'POST', [
        'process' => $interfaceElection,
        'type' => 3,
    ]);
    phase3Grant($account, 'resultados.capturar', $interfaceElection, 'propio');
}
phase7Api('/api/users/' . $phase5Accounts[0] . '/accesses', 'POST', [
    'process' => $interfaceElection,
    'type' => 2,
]);
$physical = $phase5Repository->one('SELECT uuid FROM casillas WHERE uuid IS NOT NULL ORDER BY id LIMIT 1');
file_put_contents($temporaryDirectory . '/jornada-interface.json', json_encode([
    'election' => $interfaceElection,
    'section' => $phase7Section,
    'physical' => Values::uuidText($physical['uuid']),
    'accounts' => $phase7Accounts,
    'recipient' => $phase5Accounts[0],
], JSON_THROW_ON_ERROR));
