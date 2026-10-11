<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';

use App\Auth\Authorization;
use App\Auth\Session;
use App\Core\HttpError;
use App\Core\Pagination;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security\Csrf;
use App\Core\Security\PasswordPolicy;
use App\Services\AuthService;

$checks = 0;
function check(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException($description);
    $checks++;
}
function rejects(callable $operation, string $class, string $description): void {
    try { $operation(); } catch (Throwable $exception) {
        check($exception instanceof $class, $description);
        return;
    }
    throw new RuntimeException($description);
}

session_save_path(sys_get_temp_dir());
Session::start();
$_SESSION = ['SICRAT_ID' => 2, 'SICRAT_ORGANIZATION_ID' => 10, 'SICRAT_RIGHTS' => ['captura-personas']];
Authorization::requireOperation('persons.create');
check(true, 'El capturador puede registrar personas.');
rejects(fn() => Authorization::requireOperation('users.create'), HttpError::class, 'El capturador no administra usuarios.');
rejects(fn() => Authorization::assertOrganization(11), HttpError::class, 'No se permite acceder a otra organización.');
Authorization::assertOrganization(10);
check(true, 'La organización propia está autorizada.');
check(Authorization::effectivePermissions([['permiso' => 'capture', 'valor' => 1]], [
    ['permiso' => 'capture', 'valor' => 1], ['permiso' => 'capture', 'valor' => 0],
    ['permiso' => 'extra', 'valor' => 1],
]) === ['extra'], 'La revocación prevalece sobre ambas concesiones.');
$_SESSION['SICRAT_GLOBAL_TYPE_CODE'] = 'superadmin';
Authorization::requireOperation('users.create');
check(Authorization::scopeOrganizationId() === null, 'El Super Administrador conserva acceso global.');
rejects(fn() => Authorization::requireOperation('unknown'), HttpError::class, 'Las operaciones desconocidas se deniegan.');

$token = Csrf::token();
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
Csrf::validate(new Request());
check(true, 'CSRF válido.');
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('0', 64);
rejects(fn() => Csrf::validate(new Request()), HttpError::class, 'CSRF incorrecto se rechaza.');
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
rejects(fn() => Csrf::validate(new Request()), HttpError::class, 'CSRF ausente se rechaza.');

$raw = ' contraseña con espacios ';
$hash = password_hash(PasswordPolicy::newPassword($raw), PASSWORD_BCRYPT, ['cost' => 4]);
check(PasswordPolicy::verify($raw, $hash), 'Los espacios se conservan al crear y verificar.');
check(!PasswordPolicy::verify(trim($raw), $hash), 'Una contraseña nueva con espacios no se recorta.');
$legacy = password_hash(trim($raw), PASSWORD_BCRYPT, ['cost' => 4]);
check(PasswordPolicy::verify($raw, $legacy), 'Se admite la contraseña antigua que se guardó recortada.');
check(!PasswordPolicy::verify('incorrecta', $hash), 'Una contraseña incorrecta se rechaza.');
rejects(fn() => PasswordPolicy::newPassword(str_repeat('a', 73)), InvalidArgumentException::class, 'Se evita truncamiento bcrypt.');
rejects(fn() => PasswordPolicy::newPassword("pass\0word"), InvalidArgumentException::class, 'Se rechaza NUL.');

$service = new AuthService();
$method = new ReflectionMethod($service, 'normalizeOptionalInt');
check($method->invoke($service, 0) === 0, 'El entero cero se conserva.');
check($method->invoke($service, null) === null, 'Un entero omitido sigue siendo NULL.');
rejects(fn() => $method->invoke($service, '1.5'), InvalidArgumentException::class, 'No se truncan decimales como IDs.');
rejects(fn() => $service->uuidStringToBinary('1234'), InvalidArgumentException::class, 'UUID incompleto se rechaza.');
$binary = random_bytes(16);
check($service->uuidStringToBinary($service->uuidBinaryToString($binary)) === $binary, 'UUID binario se conserva.');
check(Pagination::limit(5000000) === 100, 'El límite tiene una cota real.');
check(Pagination::offset(0) === 0 && Pagination::offset(-2) === 0, 'El desplazamiento es no negativo.');
check(Pagination::response([1, 2, 3], 2, 0)['has_more'], 'Se detecta la página siguiente.');
check(!Pagination::response([1, 2], 2, 0)['has_more'], 'Se detecta la última página.');

$payload = "</script><img src=x onerror=alert(1)>\"'&";
check(!str_contains(escape_html($payload), '<img'), 'Los valores HTML se codifican.');
check(!str_contains(json_script($payload), '</script>'), 'El JSON no cierra el script.');
ob_start();
(new Response())->exception(new RuntimeException('SQL secret /private/path line 42'));
$body = ob_get_clean();
check(!str_contains($body, 'secret') && !str_contains($body, '/private'), 'Los errores técnicos no llegan al cliente.');
check(http_response_code() === 500, 'El fallo interno conserva su código HTTP.');
Session::clear();
echo "OK: {$checks} comprobaciones PHP.\n";
