<?php

use App\Services\Common\Values;
use App\Repositories\CommonRepository;
use App\Services\Common\WorkerService;

// Todos los registros, procesos concurrentes y archivos pertenecen al entorno desechable.
$rulesStart = $checks;
$_ENV['PRIVATE_STORAGE_PATH'] = $temporaryDirectory . '/private';
function rulesApi(string $route, string $method = 'GET', ?array $data = null, string $client = 'admin', int $expected = 200): array {
    global $baseUrl, $temporaryDirectory, $phase3Tokens, $adminToken;
    $curl = curl_init($baseUrl . '/api/' . $route);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEFILE => $temporaryDirectory . '/' . $client . '.cookies',
        CURLOPT_COOKIEJAR => $temporaryDirectory . '/' . $client . '.cookies'];
    if ($data !== null) {
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'X-CSRF-Token: ' . ($phase3Tokens[$client] ?? $adminToken)];
        $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    verify($status === $expected || ($expected === 200 && $status === 201), "$method $route esperaba $expected: $body");
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    return $json['data'] ?? ['message' => $json['message'] ?? ''];
}
$rulesElection = phase3Ok('/api/organizations/' . $organizationIds[0] . '/electoral-process', 'POST', [
    'process' => 'Reglas de Jornada', 'type' => 1, 'character' => 1,
])['id'];
rulesApi('auth/election', 'POST', ['process' => $rulesElection]);
rulesApi('organizations/' . $organizationIds[0] . '/modules/jornada_electoral', 'PUT', ['authorized' => 1, 'selected' => 1]);
$rulesConfig = rulesApi('jornada/configuration', 'PUT', [
    'zona' => 'America/Monterrey', 'captura_local' => gmdate('Y-m-d\TH:i', time() - 86400),
    'politica_inicial' => 'validar', 'foto_obligatoria' => 0, 'denominador' => 'total_acta',
]);
$rulesOption = rulesApi('jornada/catalogs/opciones', 'POST', ['nombre' => 'Fila única', 'tipo' => 'otro', 'orden' => 1, 'clasificacion' => 'valido'])['uuid'];
$rulesSection = Values::uuidText($connection->query('SELECT uuid FROM secciones_electorales ORDER BY id LIMIT 1')->fetchColumn());
$rulesBooths = [];
for ($index = 0; $index < 10; $index++) {
    $rulesBooths[] = rulesApi('jornada/booths', 'POST', ['seccion' => $rulesSection, 'nombre' => 'Reglas casilla ' . $index, 'tipo' => 'BASICA'])['uuid'];
}
$rulesUsers = [];
$rulesUserIds = [];
foreach ([0, 1] as $index) {
    $rulesUsers[] = rulesApi('users', 'POST', [
        'organization' => $organizationIds[0], 'process' => $rulesElection, 'type' => 3,
        'username' => 'reglas-captura-' . $index, 'name' => 'Capturista reglas ' . $index, 'password' => 'Reglas Jornada seguras',
    ])['id'];
    foreach (['resultados.capturar', 'resultados.corregir', 'resultados.ver'] as $right) {
        phase3Grant($rulesUsers[$index], $right, $rulesElection, 'propio');
    }
    $client = 'rules-' . $index;
    $phase3Tokens[$client] = login($client, 'reglas-captura-' . $index, 'Reglas Jornada seguras');
    rulesApi('auth/election', 'POST', ['process' => $rulesElection], $client);
    $statement = $connection->prepare('SELECT id FROM usuarios WHERE uuid=?');
    $statement->execute([Values::binary($rulesUsers[$index])]);
    $rulesUserIds[] = (int) $statement->fetchColumn();
}
rulesApi('jornada/assignments', 'PUT', ['usuarios' => $rulesUsers, 'casillas' => $rulesBooths, 'activo' => 1]);
$rulesConfig = rulesApi('jornada/configuration');
rulesApi('jornada/state/abrir', 'POST', ['version' => $rulesConfig['configuration']['version'], 'motivo' => 'Inicio de pruebas de reglas']);
$rulesProcess = (int) $connection->query("SELECT id FROM procesos_electorales WHERE proceso='Reglas de Jornada'")->fetchColumn();
function rulesCapture(string $booth, string $client = 'rules-0'): array {
    global $rulesOption;
    $key = Values::uuid();
    $draft = rulesApi('jornada/drafts', 'POST', ['casilla' => $booth, 'clave' => $key], $client)['uuid'];
    return ['casilla' => $booth, 'clave' => $key, 'borrador' => $draft, 'votos' => [$rulesOption => 100]];
}
function rulesCorrect(string $booth): array {
    global $rulesOption;
    $current = rulesApi('jornada/booths/' . $booth);
    return ['casilla' => $booth, 'clave' => Values::uuid(), 'actual_esperada' => $current['actual_esperada'],
        'votos' => [$rulesOption => 110], 'motivo' => 'Corrección justificada'];
}
$emptyCorrection = ['casilla' => $rulesBooths[0], 'clave' => Values::uuid(), 'actual_esperada' => null,
    'votos' => [$rulesOption => 110], 'motivo' => 'No debe crear la primera versión'];
rulesApi('jornada/drafts', 'POST', ['casilla' => $rulesBooths[0], 'clave' => Values::uuid(), 'correccion' => 1], 'admin', 409);
rulesApi('jornada/corrections', 'POST', $emptyCorrection, 'admin', 409);
$firstData = rulesCapture($rulesBooths[0]);
$first = rulesApi('jornada/captures', 'POST', $firstData, 'rules-0');
$firstView = rulesApi('jornada/versions/' . $first['uuid']);
verify($firstView['origen'] === 'captura' && $first['numero'] === 1, 'La primera versión siempre es captura');
verify(!rulesApi('jornada/booths/' . $rulesBooths[0])['puede_capturar'], 'Con historial no se ofrece otra captura inicial');
verify(rulesApi('jornada/captures', 'POST', $firstData, 'rules-0')['uuid'] === $first['uuid'], 'El reintento idéntico sigue siendo idempotente');
rulesApi('jornada/versions/' . $first['uuid'], 'PUT', ['aprobar' => 1, 'actual_esperada' => null]);
$correction = rulesCorrect($rulesBooths[0]);
$missingReason = $correction;
unset($missingReason['motivo']);
rulesApi('jornada/corrections', 'POST', $missingReason, 'admin', 422);
$proposed = rulesApi('jornada/corrections', 'POST', $correction);
foreach (['', '   '] as $empty) {
    rulesApi('jornada/versions/' . $proposed['uuid'], 'PUT', ['aprobar' => 0, 'motivo' => $empty, 'actual_esperada' => $first['uuid']], 'admin', 422);
}
rulesApi('jornada/versions/' . $proposed['uuid'], 'PUT', ['aprobar' => 0, 'motivo' => 'El acta confirma los datos anteriores', 'actual_esperada' => $first['uuid']]);
$history = rulesApi('jornada/booths/' . $rulesBooths[0] . '/history')['rows'];
verify(count($history) === 2 && $history[0]['origen'] === 'correccion', 'Corrección y rechazo preservan ambas versiones');
$decision = $connection->query("SELECT motivo FROM jornada_decisiones WHERE accion='aprobar' AND proceso=$rulesProcess ORDER BY id LIMIT 1")->fetchColumn();
verify($decision === '', 'Validar sin observaciones queda registrado sin motivo inventado');

// Dos conexiones y actores independientes guardan formularios iniciales preparados antes de la carrera.
$raceRequests = [];
foreach ([0, 1] as $index) {
    $raceRequests[] = ['actor' => $rulesUserIds[$index], 'data' => rulesCapture($rulesBooths[1], 'rules-' . $index)];
}
$barrier = $temporaryDirectory . '/rules-barrier';
$writers = [];
foreach ($raceRequests as $requestData) {
    $writer = proc_open([PHP_BINARY, __DIR__ . '/jornada-writer.php'], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, dirname(__DIR__), $environment);
    fwrite($pipes[0], json_encode(['process' => $rulesProcess, 'action' => 'capture', 'barrier' => $barrier, ...$requestData], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $writers[] = [$writer, $pipes];
}
foreach ($writers as [, $pipes]) verify(trim(fgets($pipes[1])) === 'READY', 'Escritor preparado');
file_put_contents($barrier, 'go');
$race = [];
foreach ($writers as [$writer, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    verify(proc_close($writer) === 0, 'Conexión concurrente termina correctamente: ' . $error);
    $race[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}
unlink($barrier);
$statuses = array_column($race, 'status');
sort($statuses);
verify($statuses === [200,409], 'Sólo una primera captura se registra con dos conexiones');
verify(count(rulesApi('jornada/booths/' . $rulesBooths[1] . '/history')['rows']) === 1, 'La carrera conserva una sola versión en la base');
$loser = array_values(array_filter($race, static fn($row) => $row['status'] === 409))[0];
verify(str_contains($loser['message'], 'Ya existe una captura'), 'El intento concurrente recibe un mensaje claro');
$winner = array_values(array_filter($race, static fn($row) => $row['status'] === 200))[0]['data'];
rulesApi('jornada/versions/' . $winner['uuid'], 'PUT', ['aprobar' => 1, 'actual_esperada' => null]);

// Una captura pendiente también habilita correcciones, sin publicar automáticamente los votos.
$pending = rulesApi('jornada/captures', 'POST', rulesCapture($rulesBooths[2]), 'rules-0');
$pendingCorrection = rulesApi('jornada/corrections', 'POST', rulesCorrect($rulesBooths[2]));
verify($pendingCorrection['estado'] === 'pendiente', 'Se permite corregir una captura inicial aún pendiente');
rulesApi('jornada/versions/' . $pendingCorrection['uuid'], 'PUT', ['aprobar' => 0, 'motivo' => 'Conservar captura inicial', 'actual_esperada' => null]);
rulesApi('jornada/versions/' . $pending['uuid'], 'PUT', ['aprobar' => 1, 'actual_esperada' => null]);
for ($index = 3; $index < 9; $index++) {
    $capture = rulesApi('jornada/captures', 'POST', rulesCapture($rulesBooths[$index]), 'rules-0');
    rulesApi('jornada/versions/' . $capture['uuid'], 'PUT', ['aprobar' => 1, 'actual_esperada' => null]);
}
$before = rulesApi('jornada/summary?modo=validado');
verify($before['progreso']['esperadas'] === 10 && $before['progreso']['porcentaje_captura'] == 90, 'Escenario 9 capturadas de 10');
$snapshot = $connection->query("SELECT v.* FROM jornada_versiones v WHERE v.proceso=$rulesProcess ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
// La casilla física ya existe en el catálogo, pero todavía no en esta Jornada.
$sectionId = $connection->query('SELECT id FROM secciones_electorales ORDER BY id LIMIT 1')->fetchColumn();
$physicalUuid = Values::uuid();
$connection->prepare('INSERT INTO casillas(uuid,seccion,tipo,casilla,registro,f_registro) VALUES(?,?,?,?,1,NOW())')->execute([Values::binary($physicalUuid), $sectionId, 'BASICA', 'Faltante del catálogo reglas']);
$added = rulesApi('jornada/booths', 'POST', ['fisica' => $physicalUuid])['uuid'];
$after = rulesApi('jornada/summary?modo=validado');
verify($after['progreso']['esperadas'] === 11 && $after['progreso']['pendientes'] === 2, 'La casilla nueva se incorpora a esperadas y pendientes');
verify($after['progreso']['porcentaje_captura'] == 81.82 && $after['progreso']['porcentaje_validacion'] == 81.82, 'Avances recalculados de 90 a 81.82 por ciento');
verify($after['total_acta'] === $before['total_acta'] && $after['opciones'] === $before['opciones'], 'Los votos previos permanecen intactos');
verify(rulesApi('jornada/booths/' . $added)['capturas'] === 0, 'La casilla incorporada inicia sin historial');
$audit = $connection->query("SELECT * FROM auditoria WHERE proceso=$rulesProcess AND entidad='jornada_casillas' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$auditData = json_decode($audit['datos_nuevos'], true, 512, JSON_THROW_ON_ERROR);
verify((int)$audit['usuario'] === 1 && $audit['f_registro'] && $auditData['incorporada_iniciada'] === true && $auditData['estado_jornada'] === 'abierta' && $auditData['casilla'], 'Auditoría identifica actor, fecha, elección, casilla e incorporación iniciada');
$addedNew = rulesApi('jornada/booths', 'POST', ['seccion' => $rulesSection, 'nombre' => 'Nueva faltante reglas', 'tipo' => 'BASICA'])['uuid'];
rulesApi('jornada/assignments', 'PUT', ['usuarios' => [$rulesUsers[0]], 'casillas' => [$added, $addedNew], 'activo' => 1]);
$myBooths = rulesApi('jornada/booths?mis=1', 'GET', null, 'rules-0')['rows'];
verify(in_array($added, array_column($myBooths, 'uuid'), true) && in_array($addedNew, array_column($myBooths, 'uuid'), true), 'Nuevas casillas disponibles en Mis casillas y asignaciones');
rulesApi('jornada/booths', 'POST', ['seccion' => $rulesSection, 'nombre' => 'No autorizado', 'tipo' => 'BASICA'], 'rules-0', 403);
rulesApi('jornada/catalogs/opciones/' . $rulesOption, 'PUT', ['nombre' => 'Cambio destructivo', 'tipo' => 'otro', 'orden' => 1], 'admin', 409);
$config = rulesApi('jornada/configuration');
rulesApi('jornada/configuration', 'PUT', ['zona'=>'UTC', 'captura_local'=>'2026-10-20T00:00', 'politica_inicial'=>'preliminar', 'denominador'=>'total_acta', 'version'=>$config['configuration']['version']], 'admin', 409);
verify($connection->query("SELECT v.* FROM jornada_versiones v WHERE v.proceso=$rulesProcess ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $snapshot, 'Incorporar casillas no altera versiones existentes');
$originalPhysical = $connection->prepare('SELECT c.* FROM casillas c JOIN jornada_casillas jc ON jc.casilla=c.id WHERE jc.uuid=?');
$originalPhysical->execute([Values::binary($rulesBooths[0])]);
$physicalBefore = $originalPhysical->fetch(PDO::FETCH_ASSOC);
$existingAssociation = rulesApi('jornada/booths', 'POST', [
    'fisica' => Values::uuidText($physicalBefore['uuid']),
    'nombre' => 'Intento de alterar una casilla histórica',
    'tipo' => 'CONTIGUA',
]);
$originalPhysical->execute([Values::binary($rulesBooths[0])]);
verify($existingAssociation['uuid'] === $rulesBooths[0] && $originalPhysical->fetch(PDO::FETCH_ASSOC) === $physicalBefore,
    'Reasociar es idempotente y no modifica la casilla histórica');
$deleteResponse = request('admin', 'DELETE', '/api/jornada/booths/' . $rulesBooths[0], [], $adminToken);
verify(in_array($deleteResponse['status'], [404,405], true), 'No se habilita una operación destructiva de asociaciones');
verify((int)$connection->query("SELECT COUNT(*) FROM jornada_versiones WHERE proceso=$rulesProcess AND numero=1 AND origen<>'captura'")->fetchColumn() === 0,
    'Ninguna primera versión tiene origen corrección');


// Reporte real: el mismo cálculo de avance incluye todas las casillas recién asociadas.
$repository = new CommonRepository($connection);
$export = rulesApi('exports', 'POST', ['type'=>'jornada_avance', 'format'=>'csv', 'filters'=>['modo'=>'validado']], 'admin', 202);
(new WorkerService($repository))->run(20, 30, 'exportacion');
$file = request('admin', 'GET', '/api/exports/' . $export['uuid'] . '/download');
$exportState = $connection->query('SELECT estado,error FROM exportaciones ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
verify($file['status'] === 200 && str_contains($file['body'], 'esperadas') && preg_match('/esperadas[^\r\n]*12/', $file['body']) === 1,
    'Reporte de avance actualizado a 12 casillas: ' . json_encode([$exportState, substr($file['body'], 0, 1500)]));
// El resumen administrativo usa el total actualizado al generar el contenido.
$recipientPerson = phase3Ok('/api/persons', 'POST', [
    'organization' => $organizationIds[0], 'name' => 'Destinatario reglas Jornada',
])['id'];
$recipientUser = rulesApi('users', 'POST', [
    'organization'=>$organizationIds[0], 'process'=>$rulesElection, 'type'=>2,
    'person'=>$recipientPerson, 'name'=>'Destinatario reglas Jornada',
    'username'=>'reglas-destinatario', 'password'=>'Resumen de Jornada seguro',
])['id'];
$personQuery = $connection->prepare('SELECT persona FROM usuarios WHERE uuid=?');
$personQuery->execute([Values::binary($recipientUser)]);
$recipientPersonId = $personQuery->fetchColumn();
$connection->prepare("INSERT INTO canales_contacto(persona,tipo,valor,principal,verificado,permite_contacto,f_consentimiento)
    SELECT ?,id,'reglas@example.invalid',1,1,1,NOW() FROM canales_contacto_tipos WHERE codigo='EMAIL'")->execute([$recipientPersonId]);
rulesApi('notification-config', 'PUT', ['email'=>1, 'whatsapp'=>0]);
rulesApi('jornada/notifications', 'PUT', ['usuario'=>$recipientUser, 'canal'=>'email', 'modo'=>'validado', 'frecuencia'=>30, 'activo'=>1]);
$noticeId = (int) $connection->query("SELECT id FROM jornada_avisos WHERE proceso=$rulesProcess ORDER BY id DESC LIMIT 1")->fetchColumn();
(new App\Services\Common\ActorContext($repository))->run(1, $rulesProcess, function () use ($repository, $noticeId) {
    (new App\Services\Jornada\Notifications($repository))->prepare($noticeId, 1);
});
$summaryContent = $connection->query("SELECT contenido FROM notificaciones_envios WHERE proceso=$rulesProcess ORDER BY fecha DESC LIMIT 1")->fetchColumn();
$summaryMessage = json_decode($summaryContent, true, 512, JSON_THROW_ON_ERROR);
verify(str_contains($summaryMessage['summary'], '9/12'), 'Resumen administrativo incluye nueve capturadas de doce esperadas');
$uiPending = rulesApi('jornada/corrections', 'POST', rulesCorrect($rulesBooths[0]));
file_put_contents($temporaryDirectory . '/jornada-rules.json', json_encode([
    'election'=>$rulesElection, 'booths'=>$rulesBooths, 'added'=>$added, 'option'=>$rulesOption,
    'pending'=>$uiPending['uuid'], 'user'=>$rulesUsers[0],
], JSON_THROW_ON_ERROR));
echo 'Reglas Jornada: ' . ($checks - $rulesStart) . " comprobaciones HTTP/BD y concurrencia.\n";
