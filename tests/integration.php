<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';
loadEnv(dirname(__DIR__) . '/.env');
date_default_timezone_set('America/Mexico_City');
if (in_array('--phase5',$argv,true)) {
    if (!in_array('--phase4',$argv,true)) $argv[]='--phase4';
    if (!in_array('--ux',$argv,true)) $argv[]='--ux';
}

// Solo crea y elimina una base desechable con nombre generado. Nunca escribe en DB_DATABASE.
$databaseName = 'sicrat_phase1_test_' . bin2hex(random_bytes(6));
$connection = new PDO('mysql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT', 3306) . ';charset=utf8mb4', env('DB_USERNAME'), env('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$checks = 0;
$process = null;
$temporaryDirectory = sys_get_temp_dir() . '/' . $databaseName;
mkdir($temporaryDirectory, 0700);
$baseUrl = '';

function verify(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException($description);
    $checks++;
}

function request(string $client, string $method, string $path, ?array $data = null, ?string $csrf = null): array {
    global $baseUrl, $temporaryDirectory;
    $headers=[];
    $curl = curl_init($baseUrl . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEFILE => $temporaryDirectory . '/' . $client . '.cookies',
        CURLOPT_COOKIEJAR => $temporaryDirectory . '/' . $client . '.cookies',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($handle,$line) use (&$headers) { if (str_contains($line,':')) { [$key,$value]=explode(':',$line,2); $headers[strtolower(trim($key))]=trim($value); } return strlen($line); },
    ];
    if ($data !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }
    if ($csrf !== null) $options[CURLOPT_HTTPHEADER][] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException('No respondió el servidor de pruebas.');
    return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body, 'json' => json_decode($body, true), 'headers'=>$headers];
}

function login(string $client, string $username, string $password): string {
    $page = request($client, 'GET', '/autenticacion/');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $matches);
    verify(isset($matches[1]), 'La pantalla de login entrega CSRF.');
    $response = request($client, 'POST', '/api/auth/login', ['username' => $username, 'password' => $password], $matches[1]);
    verify($response['status'] === 200, 'Login válido de ' . $username . ': ' . $response['body']);
    $me = request($client, 'GET', '/api/auth/me');
    verify($me['status'] === 200, 'La sesión nueva es válida.');
    return $me['json']['data']['csrf_token'];
}

try {
    $connection->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection->exec('USE `' . $databaseName . '`');
    foreach (explode(';', file_get_contents(dirname(__DIR__) . '/database/database.sql')) as $statement) {
        if (trim($statement) !== '') $connection->exec($statement);
    }
    $password = ' legacy secret ';
    $legacyHash = password_hash(trim($password), PASSWORD_BCRYPT, ['cost' => 4]);
    $statement = $connection->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = 1');
    $statement->execute(['hash' => $legacyHash]);
    $uuidService = new App\Services\AuthService();
    $organizationIds = [];
    foreach (['Cliente A', 'Cliente B'] as $name) {
        $binary = random_bytes(16);
        $statement = $connection->prepare('INSERT INTO organizaciones (uuid,organizacion,contacto,registro,f_registro) VALUES (?,?,?,1,NOW())');
        $statement->execute([$binary, $name, 'Contacto']);
        $organizationIds[] = $uuidService->uuidBinaryToString($binary);
    }
    $processIds = [];
    foreach ([1, 2] as $organization) {
        $binary = random_bytes(16);
        $statement = $connection->prepare('INSERT INTO procesos_electorales (uuid,organizacion,proceso,tipo,caracter,registro,f_registro) VALUES (?,?,?,1,1,1,NOW())');
        $statement->execute([$binary, $organization, 'Proceso ' . $organization]);
        $processIds[] = $uuidService->uuidBinaryToString($binary);
    }
    foreach (['captura-a', 'captura-b'] as $index => $username) {
        $organization = $index + 1;
        $statement = $connection->prepare('INSERT INTO usuarios (uuid,organizacion,usuario,nombre,paterno,password_hash,tipo_usuario,registro,f_registro) VALUES (?,?,?, ?,?, ?,8,1,NOW())');
        $statement->execute([random_bytes(16), $organization, $username, '<img src=x onerror=alert(1)>', 'Prueba', $legacyHash]);
        $statement = $connection->prepare('INSERT INTO usuarios_procesos (proceso,usuario,tipo_usuario,f_registro) VALUES (?,?,8,NOW())');
        $statement->execute([$organization, $organization + 1]);
    }
    $connection->exec("INSERT INTO estados (estado) VALUES ('Estado de prueba')");
    $connection->exec("INSERT INTO municipios (estado,municipio) VALUES (1,'Municipio de prueba')");
    $connection->exec("INSERT INTO localidades (municipio,localidad,codigo_postal) VALUES (1,'Localidad de prueba','12345')");
    $connection->exec("INSERT INTO colonias (localidad,colonia,codigo_postal) VALUES (1,'Colonia de prueba','12345')");
    $sectionUuid = random_bytes(16);
    $statement = $connection->prepare('INSERT INTO secciones_electorales (uuid,estado,municipio,seccion,registro,f_registro) VALUES (?,1,1,100,1,NOW())');
    $statement->execute([$sectionUuid]);
    foreach ([1, 2] as $organization) {
        for ($index = 0; $index < 4; $index++) {
            $statement = $connection->prepare('INSERT INTO personas (uuid,organizacion,seccion,nombre,paterno,genero,registrado_por,f_registro) VALUES (?,?,1,?,?,\'H\',1,NOW())');
            $statement->execute([random_bytes(16), $organization, 'Persona cliente ' . $organization, 'Prueba ' . $index]);
        }
    }

    $existingPersons = $connection->query('SELECT id,uuid,nombre,paterno,organizacion,seccion FROM personas ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $existingUsers = $connection->query('SELECT id,uuid,password_hash FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    (new App\Core\Migrations($connection))->run();
    verify($connection->query('SELECT id,uuid,nombre,paterno,organizacion,seccion FROM personas ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $existingPersons, 'Migraciones preservan las personas existentes.');
    verify($connection->query('SELECT id,uuid,password_hash FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $existingUsers, 'Migraciones preservan cuentas y contraseñas.');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $environment = getenv();
    foreach ($_ENV as $key => $value) $environment[$key] = (string) $value;
    $environment['DB_DATABASE'] = $databaseName;
    $environment['APP_URL'] = $baseUrl;
    $environment['APP_DEBUG'] = 'true';
    $environment['PRIVATE_STORAGE_PATH'] = $temporaryDirectory . '/private';
    $environment['BACKUP_STORAGE_PATH'] = $temporaryDirectory . '/backups';
    $environment['SMTP_HOST'] = '';
    $environment['WHATSAPP_TOKEN'] = '';
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temporaryDirectory, '-S', $address, '-t', dirname(__DIR__) . '/public', __DIR__ . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $temporaryDirectory . '/server.log', 'a'], 2 => ['file', $temporaryDirectory . '/server.log', 'a']], $pipes, dirname(__DIR__), $environment);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    verify(request('guest', 'GET', '/api/persons')['status'] === 401, 'Se exige sesión para consultas.');
    verify(request('guest', 'POST', '/api/auth/login', ['username' => 'administrador', 'password' => $password])['status'] === 403, 'Login sin CSRF se rechaza.');
    $adminToken = login('admin', 'administrador', $password);
    $captureToken = login('capture', 'captura-a', $password);
    verify(request('capture', 'GET', '/api/users')['status'] === 403, 'Capturador no lista usuarios.');
    verify(request('capture', 'POST', '/api/users', ['type' => 1], $captureToken)['status'] === 403, 'No se puede elevar el tipo por API.');
    $organizations = request('capture', 'GET', '/api/organizations');
    verify(count($organizations['json']['data']['organizations']) === 1, 'Solo se presenta la organización propia.');
    verify(request('capture', 'GET', '/api/organizations/' . $organizationIds[1])['status'] === 404, 'El detalle de otro cliente se oculta.');
    verify(request('capture', 'GET', '/api/organizations/' . $organizationIds[1] . '/electoral-process')['status'] === 404, 'Los procesos de otro cliente se ocultan.');
    $persons = request('capture', 'GET', '/api/persons?limit=2&offset=0');
    verify($persons['status'] === 200 && count($persons['json']['data']['persons']) === 2, 'Sección omitida y offset cero funcionan.');
    verify($persons['json']['pagination']['has_more'], 'La primera página indica continuidad.');
    $second = request('capture', 'GET', '/api/persons?limit=2&offset=2');
    verify(!$second['json']['pagination']['has_more'], 'La última página termina.');
    verify($persons['json']['data']['persons'][0]['id'] !== $second['json']['data']['persons'][0]['id'], 'Las páginas no repiten filas.');
    verify(count(request('capture', 'GET', '/api/persons?search=cliente%202')['json']['data']['persons']) === 0, 'La búsqueda respeta el aislamiento.');
    verify(request('admin', 'GET', '/api/users?search=__sin_coincidencias__')['json']['data']['users'] === [], 'Búsqueda de usuarios sin coincidencias.');
    $users = request('admin', 'GET', '/api/users?limit=1&offset=0');
    verify(count($users['json']['data']['users']) === 1 && $users['json']['pagination']['has_more'], 'Paginación de usuarios aplicada en SQL.');
    verify(request('admin', 'GET', '/api/users?limit=5000000')['json']['pagination']['limit'] === 100, 'Se acota un límite excesivo.');
    verify(request('capture', 'GET', '/api/states/1/municipalities')['status'] === 200, 'Ruta de municipios.');
    verify(request('capture', 'GET', '/api/municipalities/1/localities')['json']['data']['localities'][0]['codigo_postal'] === '12345', 'Ruta y columna de localidades.');
    verify(request('capture', 'GET', '/api/localities/1/neighborhoods')['json']['data']['neighborhoods'][0]['colonia'] === 'Colonia de prueba', 'Parámetro de colonias.');
    verify(request('capture', 'GET', '/api/municipalities/invalid/localities')['status'] === 422, 'Identificador de ruta inválido se rechaza.');
    foreach (['electoral-process', 'sections', 'persons'] as $resource) {
        verify(request('admin', 'GET', '/api/' . $resource . '/no-detail')['status'] === 404, 'Ruta de detalle incompleta retirada: ' . $resource);
    }
    verify(count(request('capture', 'GET', '/api/electoral-process')['json']['data']['processes']) === 1, 'Los procesos se restringen al cliente propio.');
    verify(request('admin', 'GET', '/api/organizations/' . $organizationIds[0])['json']['data']['processes_pagination']['limit'] === 25, 'El detalle limita los procesos relacionados.');
    verify(request('admin', 'GET', '/api/organizations/' . $organizationIds[0] . '/electoral-process?limit=1')['json']['pagination']['limit'] === 1, 'El selector electoral dispone de paginación.');
    $organization = ['organization' => 'Cliente inactivo', 'contact' => 'Contacto', 'active' => 0];
    verify(request('admin', 'POST', '/api/organizations', $organization, $adminToken)['status'] === 201, 'Alta de organización inactiva.');
    verify((int) $connection->query("SELECT activo FROM organizaciones WHERE organizacion='Cliente inactivo'")->fetchColumn() === 0, 'Se conserva el cero en organización.');
    $processData = ['process' => 'Proceso inactivo', 'type' => 1, 'character' => 1, 'status' => 0];
    verify(request('admin', 'POST', '/api/organizations/' . $organizationIds[0] . '/electoral-process', $processData, $adminToken)['status'] === 201, 'Alta de proceso inactivo.');
    verify((int) $connection->query("SELECT estatus FROM procesos_electorales WHERE proceso='Proceso inactivo'")->fetchColumn() === 0, 'Se conserva el cero en proceso.');
    verify(count(request('capture', 'GET', '/api/electoral-process')['json']['data']['processes']) === 1, 'No se presentan procesos no asignados del mismo cliente.');
    verify(request('capture', 'POST', '/api/states', ['nombre' => 'Otro'], $captureToken)['status'] === 403, 'Los catálogos también exigen autorización.');
    verify(request('admin', 'POST', '/api/states', ['nombre' => 'Otro'], $adminToken)['status'] === 201, 'El alta de estado reutiliza el esquema existente.');
    verify(request('admin', 'POST', '/api/states/1/municipalities', ['nombre' => 'Otro municipio', 'state_id' => 2], $adminToken)['status'] === 201, 'El alta de municipio respeta el parámetro de ruta.');
    verify((int) $connection->query("SELECT estado FROM municipios WHERE municipio='Otro municipio'")->fetchColumn() === 1, 'El cuerpo no sustituye al padre de la ruta.');
    verify(request('admin', 'POST', '/api/municipalities/1/localities', ['nombre' => 'Otra localidad', 'codigo_postal' => '00001'], $adminToken)['status'] === 201, 'El alta de localidad funciona.');
    verify(request('admin', 'POST', '/api/sections', ['section' => 101, 'state' => 2, 'municipality' => 1], $adminToken)['status'] === 422, 'La sección no cruza estado y municipio.');
    verify(request('admin', 'POST', '/api/sections', ['section' => 101, 'state' => 1, 'municipality' => 1], $adminToken)['status'] === 201, 'Alta de sección válida.');
    $person = ['organization' => $organizationIds[0], 'section' => $uuidService->uuidBinaryToString($sectionUuid), 'gender' => 'H', 'name' => '<img src=x onerror=alert(1)>', 'last_name' => 'Regresión'];
    verify(request('capture', 'POST', '/api/persons', $person, 'invalid')['status'] === 403, 'CSRF inválido bloquea el alta.');
    verify(request('capture', 'POST', '/api/persons', $person, $captureToken)['status'] === 201, 'CSRF válido permite el alta propia.');
    $person['organization'] = $organizationIds[1];
    verify(request('capture', 'POST', '/api/persons', $person, $captureToken)['status'] === 404, 'Se bloquea alta en otro cliente.');
    $newUser = ['username' => 'nuevo', 'password' => ' nueva contraseña ', 'type' => 8, 'organization' => $organizationIds[0], 'process' => $processIds[1], 'status' => 0, 'name' => 'Nombre', 'last_name' => 'Apellido'];
    verify(request('admin', 'POST', '/api/users', $newUser, $adminToken)['status'] === 422, 'Proceso de otra organización se rechaza.');
    $newUser['process'] = $processIds[0];
    verify(request('admin', 'POST', '/api/users', $newUser, $adminToken)['status'] === 201, 'Alta existente de usuario funciona.');
    verify((int) $connection->query("SELECT activo FROM usuarios WHERE usuario='nuevo'")->fetchColumn() === 0, 'El alta conserva el estado solicitado.');
    verify(request('admin', 'POST', '/api/users', $newUser, $adminToken)['status'] === 422, 'Se valida el nombre de usuario duplicado.');
    $adminUuid = $uuidService->uuidBinaryToString($connection->query('SELECT uuid FROM usuarios WHERE id=1')->fetchColumn());
    $changePath = '/api/profiles/' . $adminUuid . '/change-password';
    verify(request('admin', 'PUT', $changePath, ['old_password' => 'incorrecta', 'new_password' => ' nueva contraseña '], $adminToken)['status'] === 422, 'Contraseña actual incorrecta no anuncia éxito.');
    verify(request('admin', 'PUT', $changePath, ['old_password' => $password, 'new_password' => ' nueva contraseña '], $adminToken)['json']['data']['changed'], 'Cambio de contraseña correcto.');
    verify(password_verify(' nueva contraseña ', $connection->query('SELECT password_hash FROM usuarios WHERE id=1')->fetchColumn()), 'La contraseña nueva conserva espacios.');
    $page = request('capture', 'GET', '/?callBack=' . rawurlencode('</script><script>alert(1)</script>'));
    verify($page['status'] === 200 && !str_contains($page['body'], '<script>alert(1)</script>'), 'El GET no introduce un script.');
    verify(!str_contains($page['body'], '<img src=x onerror=alert(1)>'), 'El nombre en header está escapado.');
    login('second-capture', 'captura-a', $password);
    verify(request('capture', 'GET', '/api/auth/me')['status'] === 401, 'Un login nuevo conserva la política de revocar sesiones anteriores.');
    $captureToken = login('capture', 'captura-a', $password);
    $captureUuid = $uuidService->uuidBinaryToString($connection->query('SELECT uuid FROM usuarios WHERE id=2')->fetchColumn());
    verify(request('capture', 'GET', '/api/profiles/' . $captureUuid)['status'] === 200, 'El perfil propio sigue disponible.');
    verify(request('capture', 'GET', '/api/profiles/' . $adminUuid)['status'] === 403, 'El perfil ajeno se deniega.');
    $connection->exec('UPDATE usuarios SET activo=0 WHERE id=2');
    verify(request('capture', 'GET', '/api/auth/me')['status'] === 401, 'Una cuenta desactivada no conserva acceso.');
    $connection->exec('UPDATE usuarios SET activo=1 WHERE id=2');
    $captureToken = login('capture', 'captura-a', $password);
    $connection->exec('UPDATE usuarios_sesiones SET expira_en="2000-01-01 00:00:00" WHERE usuario=2');
    verify(request('capture', 'GET', '/persons/')['status'] === 302, 'La página también rechaza una sesión expirada.');
    verify(request('capture', 'GET', '/api/auth/me')['status'] === 401, 'Sesión expirada se rechaza.');
    $captureToken = login('capture', 'captura-a', $password);
    $connection->exec('UPDATE usuarios_sesiones SET destruida_en=NOW() WHERE usuario=2');
    verify(request('capture', 'GET', '/api/persons')['status'] === 401, 'Sesión revocada se rechaza.');
    $captureToken = login('capture', 'captura-a', $password);
    $statement = $connection->prepare("INSERT INTO permisos_usuarios (uuid,permiso,usuario,organizacion,valor,f_actualizacion) VALUES (?,'captura-personas',2,1,0,NOW())");
    $statement->execute([random_bytes(16)]);
    verify(request('capture', 'GET', '/api/persons')['status'] === 403, 'Revocación individual vence al rol en una petición real.');
    $connection->exec('DELETE FROM permisos_usuarios WHERE usuario=2');
    $statement = $connection->prepare("INSERT INTO permisos_usuarios (uuid,permiso,usuario,organizacion,valor,f_actualizacion) VALUES (?,'captura-personas',2,2,0,NOW())");
    $statement->execute([random_bytes(16)]);
    verify(request('capture', 'GET', '/api/persons')['status'] === 200, 'Permiso de otra organización no altera el contexto propio.');
    verify(request('capture', 'POST', '/api/auth/logout', [], $captureToken)['status'] === 200, 'Logout funciona.');
    verify(request('capture', 'GET', '/api/auth/me')['status'] === 401, 'Logout invalida la sesión.');
    $broken = request('admin', 'POST', '/api/persons', ['organization' => 'not-a-uuid'], $adminToken);
    verify(!str_contains($broken['body'], 'SQLSTATE') && !str_contains($broken['body'], '/opt/') && !isset($broken['json']['file']), 'Error de validación sin información interna.');
    // El fallo técnico se provoca únicamente dentro de la base desechable.
    $connection->exec('RENAME TABLE generos TO generos_test_error');
    $error = request('admin', 'GET', '/api/genders');
    verify($error['status'] === 500 && !str_contains($error['body'], 'SQLSTATE') && !str_contains($error['body'], 'generos') && !str_contains($error['body'], '/opt/'), 'El error interno se oculta incluso con APP_DEBUG activo.');
    $connection->exec('RENAME TABLE generos_test_error TO generos');
    if (in_array('--phase2', $argv, true) || (in_array('--phase3', $argv, true) || in_array('--phase4', $argv, true))) require __DIR__ . '/phase2.php';
    if ((in_array('--phase3', $argv, true) || in_array('--phase4', $argv, true))) require __DIR__ . '/phase3.php';
    if (in_array('--phase4', $argv, true)) require __DIR__ . '/phase4.php';
    if (in_array('--ux', $argv, true)) {
        if (!in_array('--phase4',$argv,true)) throw new RuntimeException('--ux requiere --phase4.');
        require __DIR__ . '/ux.php';
    }
    if (in_array('--phase5',$argv,true)) require __DIR__.'/phase5.php';
    if (in_array('--browser', $argv, true)) {
        if (!(in_array('--phase3',$argv,true) || in_array('--phase4',$argv,true))) throw new RuntimeException('--browser requiere --phase3.');
        $browserProcess=proc_open(['node',__DIR__ . '/phase3-browser.js',$baseUrl,$temporaryDirectory,...(in_array('--phase4',$argv,true) ? ['--phase4'] : []),...(in_array('--ux',$argv,true) ? ['--ux'] : []),...(in_array('--phase5',$argv,true) ? ['--phase5'] : []),...(in_array('--phase5-browser-only',$argv,true) ? ['--only-phase5'] : [])],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$browserPipes,dirname(__DIR__));
        fclose($browserPipes[0]); verify(proc_close($browserProcess)===0,'Validación de las pantallas en navegador real.');
    }
    echo "OK: {$checks} comprobaciones HTTP/BD (" . $connection->query('SELECT VERSION()')->fetchColumn() . ").\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/', $databaseName)) {
        $connection->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
    // Los logs y cookies de pruebas quedan en el directorio temporal para diagnóstico.
    echo 'Artefactos temporales: ' . $temporaryDirectory . "\n";
}
