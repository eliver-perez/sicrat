<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use DatabaseConnection;
use DateTimeImmutable;
use PDO;

class AuthMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        global $CFG;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['SICRAT_ID']) || empty($_SESSION['SICRAT_AUTH_TOKEN'])) {
            $response->json([
                'status' => 'UNAUTHORIZED',
                'message' => 'Sesión no autenticada'
            ], 401);
            return false;
        }

        $tokenHex = $_SESSION['SICRAT_AUTH_TOKEN'];

        if (!ctype_xdigit($tokenHex) || strlen($tokenHex) !== 64) {
            session_unset();
            session_destroy();

            $response->json([
                'status' => 'UNAUTHORIZED',
                'message' => 'Token inválido'
            ], 401);
            return false;
        }

        $tokenBin = hex2bin($tokenHex);
        $tokenHash = hash_hmac('sha256', $tokenBin, $CFG->hmacsalt, true);

        $database = new DatabaseConnection();
        $conn = $database->GetDatabaseConnector();

        $stmt = $conn->prepare("
            SELECT id, usuario, expira_en
            FROM usuarios_sesiones
            WHERE token_hash = :token_hash
            LIMIT 1
        ");
        $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
        $stmt->execute();

        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            session_unset();
            session_destroy();

            $response->json([
                'status' => 'UNAUTHORIZED',
                'message' => 'Sesión no encontrada'
            ], 401);
            return false;
        }

        if ((int)$session['usuario'] !== (int)$_SESSION['SICRAT_ID']) {
            session_unset();
            session_destroy();

            $response->json([
                'status' => 'UNAUTHORIZED',
                'message' => 'Sesión inconsistente'
            ], 401);
            return false;
        }

        $ahora = new DateTimeImmutable();
        $expiraEn = new DateTimeImmutable($session['expira_en']);

        if ($expiraEn < $ahora) {
            $stmt = $conn->prepare("DELETE FROM usuarios_sesiones WHERE id = :id");
            $stmt->bindValue(':id', (int)$session['id'], PDO::PARAM_INT);
            $stmt->execute();

            session_unset();
            session_destroy();

            $response->json([
                'status' => 'SESSION_EXPIRED',
                'message' => 'La sesión ha expirado'
            ], 401);
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE usuarios_sesiones
            SET ultima_actividad = NOW()
            WHERE id = :id
        ");
        $stmt->bindValue(':id', (int)$session['id'], PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['SICRAT_LAST_ACTIVITY'] = time();

        return true;
    }
}