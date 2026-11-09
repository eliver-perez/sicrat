<?php

// Included only by integration.php; all fixtures belong to its disposable database.
$pwaStart = $checks;
function pwaApi(string $path, string $method = 'GET', ?array $data = null, string $client = 'admin', ?string $token = null): array
{
    global $adminToken;
    $result = request($client, $method, $path, $data, $method === 'GET' ? null : ($token ?? $adminToken));
    verify(in_array($result['status'], [200, 201], true), 'PWA fixture ' . $path . ': ' . $result['body']);
    return $result['json']['data'] ?? [];
}
function pwaConditional(string $client, string $path, ?string $etag = null): array
{
    global $baseUrl, $temporaryDirectory;
    $headers = [];
    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $temporaryDirectory . '/' . $client . '.cookies',
        CURLOPT_HTTPHEADER => ['X-Sicrat-Offline: 1', ...($etag ? ['If-None-Match: ' . $etag] : [])],
        CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$headers) {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower(trim($key))] = trim($value); }
            return strlen($line);
        }]);
    $body = curl_exec($curl);
    return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body, 'headers' => $headers];
}

$pwaUsers = [];
foreach (['a', 'b'] as $index => $suffix) {
    $pwaUsers[$suffix] = pwaApi('/api/users', 'POST', [
        'username' => 'pwa-' . $suffix, 'password' => 'Prueba PWA segura 2026', 'type' => 2,
        'organization' => $organizationIds[$index], 'process' => $processIds[$index],
        'name' => 'PWA ' . strtoupper($suffix), 'last_name' => 'Pruebas',
    ])['id'];
    pwaApi('/api/persons', 'POST', ['organization' => $organizationIds[$index], 'name' => 'Persona privada PWA ' . strtoupper($suffix)]);
}
$pwaTokenA = login('pwa-a', 'pwa-a', 'Prueba PWA segura 2026');
$pwaTokenB = login('pwa-b', 'pwa-b', 'Prueba PWA segura 2026');
$contextA = pwaApi('/api/pwa/context', client: 'pwa-a');
$contextB = pwaApi('/api/pwa/context', client: 'pwa-b');
verify($contextA['profile'] !== $contextB['profile'], 'PWA contexts isolate users, organizations and elections.');
verify($contextA['expires_at'] - $contextA['validated_at'] === 43200000, 'Offline lease is exactly 12 hours.');
verify(!isset($contextA['csrf_token'], $contextA['token']), 'Offline context contains no server authentication secret.');
$path = '/api/persons?limit=2&search=Persona%20privada%20PWA';
$first = pwaConditional('pwa-a', $path);
verify($first['status'] === 200 && isset($first['headers']['etag']), 'Authorized response supplies ETag.');
$second = pwaConditional('pwa-a', $path, $first['headers']['etag']);
verify($second['status'] === 304 && $second['body'] === '', 'Unchanged authorized data transfers no JSON.');
verify($second['headers']['cache-control'] === 'no-store', 'Private HTTP cache remains disabled even on 304.');
$other = pwaConditional('pwa-b', $path, $first['headers']['etag']);
verify($other['status'] === 200 && !str_contains($other['body'], 'Persona privada PWA A'), 'Another account cannot reuse the ETag or response.');
$me = pwaConditional('pwa-a', '/api/auth/me');
verify(!isset($me['headers']['etag']), 'Authentication/CSRF response is excluded from offline storage.');
$gzipHeaders = [];
$gzip = curl_init($baseUrl . $path);
curl_setopt_array($gzip, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $temporaryDirectory . '/pwa-a.cookies',
    CURLOPT_HTTPHEADER => ['Accept-Encoding: gzip'], CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$gzipHeaders) {
        if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $gzipHeaders[strtolower(trim($key))] = trim($value); }
        return strlen($line);
    }]);
$compressed = curl_exec($gzip);
verify(($gzipHeaders['content-encoding'] ?? '') === 'gzip' && json_decode(gzdecode($compressed), true)['success'], 'JSON compression produces valid negotiated gzip.');

pwaApi('/api/users/' . $pwaUsers['a'] . '/permissions', 'PUT', ['permission' => 'personas.ver', 'election' => $processIds[0], 'value' => 0, 'scope' => 'organizacion']);
$denied = pwaConditional('pwa-a', $path, $first['headers']['etag']);
verify($denied['status'] === 403 && !isset($denied['headers']['etag']), 'Revoked permissions are checked BEFORE If-None-Match.');
pwaApi('/api/users/' . $pwaUsers['a'] . '/permissions', 'PUT', ['permission' => 'personas.ver', 'election' => $processIds[0], 'value' => 1, 'scope' => 'organizacion']);
pwaApi('/api/auth/election', 'POST', ['process' => $processIds[0]]);
pwaApi('/api/organizations/' . $organizationIds[0] . '/modules/estructura', 'PUT', ['authorized' => 1, 'selected' => 1]);
$structure = pwaApi('/api/structures', 'POST', ['name' => 'Rama PWA consultada', 'elections' => [$processIds[0]]])['id'];
$secondPwaElection = pwaApi('/api/organizations/' . $organizationIds[0] . '/electoral-process', 'POST', [
    'process' => 'Segunda elección PWA', 'type' => 1, 'character' => 1, 'status' => 1,
])['id'];
pwaApi('/api/users/' . $pwaUsers['a'] . '/accesses', 'POST', ['process' => $secondPwaElection, 'type' => 2]);
file_put_contents($temporaryDirectory . '/pwa.json', json_encode([
    'database' => $databaseName, 'a' => $contextA, 'b' => $contextB, 'users' => $pwaUsers, 'structure' => $structure,
    'election' => $processIds[0], 'secondElection' => $secondPwaElection, 'personsPath' => $path,
], JSON_THROW_ON_ERROR));
echo 'PWA HTTP: ' . ($checks - $pwaStart) . " comprobaciones.\n";

require __DIR__ . '/mobile-context.php';
