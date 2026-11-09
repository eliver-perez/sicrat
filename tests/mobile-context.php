<?php

// Usa exclusivamente las cuentas y la base desechables de la suite PWA.
$mobileStart = $checks;
$choices = pwaApi('/api/auth/elections?limit=100', client: 'pwa-a')['electoral_processes'];
verify(count($choices) === 2, 'La cuenta multielección tiene dos opciones autorizadas.');
pwaApi('/api/auth/election', 'POST', ['process' => $secondPwaElection], 'pwa-a', $pwaTokenA);
$pwaTokenA = login('pwa-a', 'pwa-a', 'Prueba PWA segura 2026');
$restored = pwaApi('/api/auth/elections?limit=100', client: 'pwa-a')['electoral_processes'];
verify(array_column(array_filter($restored, fn ($row) => $row['selected']), 'id') === [$secondPwaElection], 'Reingreso restaura la elección autorizada del navegador.');
verify(pwaApi('/api/pwa/context', client: 'pwa-a')['profile'] !== $contextA['profile'], 'La preferencia establece el contexto real de PWA.');

// La última opción se revoca y quedan dos: ninguna puede sustituirla arbitrariamente.
$thirdMobileElection = pwaApi('/api/organizations/' . $organizationIds[0] . '/electoral-process', 'POST', [
    'process' => 'Tercera elección de prueba móvil', 'type' => 1, 'character' => 1, 'status' => 1,
])['id'];
pwaApi('/api/users/' . $pwaUsers['a'] . '/accesses', 'POST', ['process' => $thirdMobileElection, 'type' => 2]);
$statement = $connection->prepare('UPDATE procesos_electorales SET estatus=? WHERE uuid=UNHEX(REPLACE(?, "-", ""))');
$statement->execute([0, $secondPwaElection]);
$pwaTokenA = login('pwa-a', 'pwa-a', 'Prueba PWA segura 2026');
$choices = pwaApi('/api/auth/elections?limit=100', client: 'pwa-a')['electoral_processes'];
verify(count($choices) === 2 && !array_filter($choices, fn ($row) => $row['selected']), 'Una preferencia revocada no selecciona otra elección.');
verify(str_contains(request('pwa-a', 'GET', '/')['body'], 'Tu última elección ya no está disponible'), 'La selección revocada se informa al usuario.');
$denied = request('pwa-a', 'POST', '/api/auth/election', ['process' => $secondPwaElection], $pwaTokenA);
verify($denied['status'] === 404, 'El servidor rechaza explícitamente la elección retirada.');
$statement->execute([1, $secondPwaElection]);
$statement->execute([0, $thirdMobileElection]);
pwaApi('/api/auth/election', 'POST', ['process' => $processIds[0]], 'pwa-a', $pwaTokenA);

// Una opción y ninguna opción, conservando el acceso general a la cuenta.
$pwaTokenB = login('pwa-b', 'pwa-b', 'Prueba PWA segura 2026');
$choices = pwaApi('/api/auth/elections?limit=100', client: 'pwa-b')['electoral_processes'];
verify(count($choices) === 1 && $choices[0]['selected'], 'La única elección se selecciona al autenticar, sin JavaScript.');
$statement->execute([0, $processIds[1]]);
$pwaTokenB = login('pwa-b', 'pwa-b', 'Prueba PWA segura 2026');
verify(pwaApi('/api/auth/elections', client: 'pwa-b')['electoral_processes'] === [], 'Sin elecciones no se inventa un contexto.');
verify(pwaApi('/api/auth/me', client: 'pwa-b')['process_id'] === null, 'La cuenta sin elección mantiene sesión general autorizada.');
$statement->execute([1, $processIds[1]]);
$pwaTokenB = login('pwa-b', 'pwa-b', 'Prueba PWA segura 2026');
echo 'Contexto móvil HTTP: ' . ($checks - $mobileStart) . " comprobaciones.\n";
