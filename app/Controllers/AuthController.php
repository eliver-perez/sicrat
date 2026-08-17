<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Service;
use App\Core\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Auth\Session;
use App\Core\Database;

use App\Services\AuthService;

use PDO;
use DateTimeImmutable;
use Throwable;

use function App\Helpers\getDeviceType;

class AuthController extends Controller
{

    private function getService(): AuthService
    {
        return new AuthService();
    }

    public function login(Request $request, Response $response)
    {
        $service = $this->getService();

        $salt = env('HMAC_SALT');

        if(!$salt) {
            throw new \RuntimeException('HMAL_SALT no esta configurado en .env');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $database = new Database();
        $conn = $database->getConnection();

        try {
            $username = trim((string)$request->input('username', ''));
            $password = (string)$request->input('password', '');
            $keep = (int)$request->input('keep_me_logged_in', 0) === 1;

            if ($username === '' || $password === '') {
                return $response->json([
                    'status' => 'VALIDATION_ERROR',
                    'message' => 'Usuario y password son obligatorios'.$username
                ], 422);
            }

            $stmt = $conn->prepare("
                SELECT u.id,
                       u.uuid,
                       o.id organizacion_id,
                       o.uuid organizacion_uuid,
                       o.organizacion,
                       pe.id proceso_id,
                       pe.uuid proceso_uuid,
                       pe.proceso,
                        TRIM(
                            CONCAT(
                                u.nombre, ' ',
                                COALESCE(u.paterno, ''), ' ',
                                COALESCE(u.materno, '')
                            )
                        ) nombre,
                       u.usuario,
                       u.email,
                       u.password_hash,
                       u.activo,
                       ut.id tipo_usuario_id,
                       ut.codigo tipo_usuario_codigo,
                       ut.tipo tipo_usuario
                FROM usuarios u
                    INNER JOIN usuarios_tipos ut
                        ON u.tipo_usuario = ut.id
                    LEFT JOIN usuarios_procesos up
                        ON up.usuario = u.id
                            AND up.activo = 1
                    LEFT JOIN procesos_electorales pe
                        ON up.proceso = pe.id
                    LEFT JOIN organizaciones o
                        ON pe.organizacion = o.id
                WHERE u.usuario = :usuario
                LIMIT 1
            ");
            $stmt->bindValue(':usuario', $username, PDO::PARAM_STR);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return $response->json(['status' => 'ERROR_AUTENTICACION'], 401);
            }

            if (!password_verify($password, $user['password_hash'])) {
                return $response->json(['status' => 'ERROR_AUTENTICACION'], 401);
            }

            if ((int)$user['activo'] !== 1) {
                return $response->json(['status' => 'FAIL_NOT_ACTIVE'], 403);
            }

            $organization_id = $user['organizacion_id'];
            $organization_uuid = $user['organizacion_uuid'];
            $organization = $user['organizacion'];
            $process_id = $user['proceso_id'];
            $process_uuid = $user['proceso_uuid'];
            $process = $user['proceso'];
            $user_role = null;
            $user_type_id = $user['tipo_usuario_id'];
            $user_type_code = $user['tipo_usuario_codigo'];
            $user_type = $user['tipo_usuario'];

            $stmt = $conn->prepare("
                SELECT
                up.id,
                pe.id proceso_id,
                pe.uuid proceso_uuid,
                pe.proceso,
                ut.id tipo_usuario_id,
                ut.codigo tipo_usuario_codigo,
                ut.tipo tipo_usuario
                FROM usuarios u
                    LEFT JOIN usuarios_procesos up
                        ON u.id = up.usuario
                    LEFT JOIN procesos_electorales pe
                        ON up.proceso = pe.id
                    INNER JOIN usuarios_tipos ut
                        ON up.tipo_usuario = ut.id
                WHERE up.usuario = :usuario
                    AND up.f_baja IS NULL
            ");

            $stmt->bindParam(':usuario', $user['id']);
            $stmt->execute();
            $data = $stmt->fetchAll();
            $tipos_usuario = array();
            foreach($data as $tu) {
                array_push($tipos_usuario, array('id'                   => $tu['id'],
                                                'proceso_id'            => $tu['proceso_id'],
                                                'proceso_uuid'          => $tu['proceso_uuid'],
                                                'proceso'               => $tu['proceso'],
                                                'tipo_usuario_id'       => $tu['tipo_usuario_id'],
                                                'tipo_usuario_codigo'   => $tu['tipo_usuario_codigo'],
                                                'tipo_usuario'          => $tu['tipo_usuario']));
            }

            $conn->beginTransaction();

            session_regenerate_id(true);

            $tokenBin = random_bytes(32);
            $token = bin2hex($tokenBin);
            $tokenHash = hash_hmac('sha256', $tokenBin, $salt, true);

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            if ($userAgent !== null) {
                $userAgent = mb_substr($userAgent, 0, 255);
            }

            $dispositivo = getDeviceType($userAgent);
            if ($dispositivo !== null) {
                $dispositivo = mb_substr($dispositivo, 0, 255);
            }

            $ahora = new DateTimeImmutable();
            $_SESSION['SICRAT_AUTH_TIME'] = $ahora->format('Y-m-d H:i:s');
            $expiraEn = $keep ? $ahora->modify('+30 days') : $ahora->modify('+8 hours');
            $_SESSION['SICRAT_AUTH_EXPIRES'] = $expiraEn->format('Y-m-d H:i:s');

            $session_id = random_bytes(16);
            $_SESSION['SICRAT_LAST_ACTIVITY'] = time();
            $_SESSION['SICRAT_AUTH_TOKEN'] = $token;
            $_SESSION['SICRAT_ID'] = (int)$user['id'];
            $_SESSION['SICRAT_UUID'] = $service->uuidBinaryToString($user['uuid']);
            $_SESSION['SICRAT_USERNAME'] = $user['usuario'];
            $_SESSION['SICRAT_EMAIL'] = $user['email'];
            $_SESSION['SICRAT_NAME'] = $user['nombre'];
            $_SESSION['SICRAT_ORGANIZATION_ID'] = $organization_id;
            $_SESSION['SICRAT_ORGANIZATION_UUID'] = $organization_uuid != null ? $service->uuidBinarytoString($organization_uuid) : '';
            $_SESSION['SICRAT_ORGANIZATION'] = $organization;
            if(count($tipos_usuario) > 0) {
                $_SESSION['SICRAT_ORGANIZATION_PROCESS_ID'] = count($tipos_usuario) == 1 ? $tipos_usuario[0]['proceso_id'] : null;
                $_SESSION['SICRAT_ORGANIZATION_PROCESS'] = count($tipos_usuario) == 1 ? $tipos_usuario[0]['proceso'] : null;
                $_SESSION['SICRAT_USER_ROLE'] = count($tipos_usuario) == 1 ? $tipos_usuario[0]['id'] : null;
                $_SESSION['SICRAT_USER_TYPE_CODE'] = count($tipos_usuario) == 1 ? $tipos_usuario[0]['tipo_usuario_codigo'] : null;
                $_SESSION['SICRAT_USER_TYPE'] = count($tipos_usuario) == 1 ? $tipos_usuario[0]['tipo_usuario'] : null;
            } else {
                $_SESSION['SICRAT_USER_ROLE'] = $user_role;
                $_SESSION['SICRAT_USER_TYPE_CODE'] = $user_type_code;
                $_SESSION['SICRAT_USER_TYPE'] = $user_type;
            }

            $_SESSION['SICRAT_AVAILABLE_ROLES'] = count($tipos_usuario) > 1 ? $tipos_usuario : null;

            $stmt = $conn->prepare("
                UPDATE usuarios_sesiones
                SET destruida_en = NOW()
                WHERE usuario = :uid
                    AND destruida_en IS NULL
            ");
            $stmt->bindValue(':uid', (int)$user['id'], PDO::PARAM_INT);
            $stmt->execute();

            $stmt = $conn->prepare("
                INSERT INTO usuarios_sesiones
                (
                    id,
                    usuario,
                    token_hash,
                    f_registro,
                    ultima_actividad,
                    expira_en,
                    ip,
                    user_agent,
                    dispositivo
                )
                VALUES
                (
                    :id,
                    :usuario,
                    :token_hash,
                    NOW(),
                    NOW(),
                    :expira_en,
                    :ip,
                    :user_agent,
                    :dispositivo
                )
            ");
            $stmt->bindValue(':id', $session_id, PDO::PARAM_LOB);
            $stmt->bindValue(':usuario', (int)$user['id'], PDO::PARAM_INT);
            // $stmt->bindValue(':token_aux', $token, PDO::PARAM_STR);
            $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
            $stmt->bindValue(':expira_en', $expiraEn->format('Y-m-d H:i:s'), PDO::PARAM_STR);
            $stmt->bindValue(':ip', $ip);
            $stmt->bindValue(':user_agent', $userAgent);
            $stmt->bindValue(':dispositivo', $dispositivo);
            $stmt->execute();

            $stmt = $conn->prepare("
                UPDATE usuarios
                SET f_ultima_conexion = NOW()
                WHERE id = :uid
            ");
            $stmt->bindValue(':uid', (int)$user['id'], PDO::PARAM_INT);
            $stmt->execute();

            $conn->commit();

            return $response->json([
                'status' => 'OK',
                'data' => [
                    'id' => (int)$user['id'],
                    'usuario' => $user['usuario'],
                    'nombre' => $user['nombre'],
                    'email' => $user['email']
                ]
            ]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            session_unset();
            session_destroy();

            return $response->json([
                'status' => 'ERROR_SQL',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // public function logout(Request $request, Response $response) {
    //     $salt = env('HMAC_SALT');

    //     if(!$salt) {
    //         throw new \RuntimeException('HMAL_SALT no esta configurado en .env');
    //     }

    //     if (session_status() === PHP_SESSION_NONE) {
    //         session_start();
    //     }

    //     $database = new DatabaseConnection();
    //     $conn = $database->GetDatabaseConnector();

    //     try {
    //         if (!empty($_SESSION['SICRAT_AUTH_TOKEN'])) {
    //             $tokenHex = $_SESSION['SICRAT_AUTH_TOKEN'];

    //             if (ctype_xdigit($tokenHex) && strlen($tokenHex) === 64) {
    //                 $tokenBin = hex2bin($tokenHex);
    //                 $tokenHash = hash_hmac('sha256', $tokenBin, $salt, true);

    //                 $stmt = $conn->prepare("
    //                     DELETE FROM usuarios_sesiones
    //                     WHERE token_hash = :token_hash
    //                 ");
    //                 $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
    //                 $stmt->execute();
    //             }
    //         }

    //         $_SESSION = [];
    //         session_destroy();

    //         return $response->json(['status' => 'OK']);
    //     } catch (Throwable $e) {
    //         return $response->json([
    //             'status' => 'ERROR_LOGOUT',
    //             'message' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function logout(Request $request, Response $response) {
        $salt = env('HMAC_SALT');

        $session = new Session();
        $database = new Database();
        $conn = $database->getConnection();

        try {
            $conn->beginTransaction();
            $id = $session->getId();
            $authentication_token_bin = hex2bin($session->getToken());
            $authentication_token_hash = hash_hmac('sha256', $authentication_token_bin, $salt, true);
            
            $stmt = $conn->prepare('SELECT id, destruida_en FROM usuarios_sesiones WHERE usuario = :usuario AND token_hash = :token AND destruida_en IS NULL');
            $stmt->bindParam(':usuario', $id);
            $stmt->bindParam(':token', $authentication_token_hash);
            $stmt->execute();
            $data = $stmt->fetch();

            if($data != null && $data['destruida_en'] == null) {
                $stmt = $conn->prepare('UPDATE usuarios_sesiones SET destruida_en = NOW() WHERE id = :id');
                $stmt->bindParam(':id', $data['id']);
                $stmt->execute();
            }

            $conn->commit();
            
            session_unset();     // unset $_SESSION variable for the run-time 
            session_destroy();   // destroy session data in storage
            return $response->json(['status' => 'OK']);
        } catch(Exception $ex) {
            $conn->rollBack();
            echo json_encode(array('status' => 'FAIL'));
        }
    }

    public function me(Request $request, Response $response)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['SICRAT_ID'])) {
            return $response->json([
                'status' => 'UNAUTHORIZED'
            ], 401);
        }

        return $response->json([
            'status' => 'OK',
            'data' => [
                'id' => $_SESSION['SICRAT_ID'],
                'email' => $_SESSION['SICRAT_EMAIL'] ?? null,
                'nombre' => $_SESSION['SICRAT_NAME'] ?? null,
                'auth_time' => $_SESSION['SICRAT_AUTH_TIME'] ?? null,
            ]
        ]);
    }
}