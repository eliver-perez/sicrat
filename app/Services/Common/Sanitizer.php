<?php

declare(strict_types=1);

namespace App\Services\Common;

final class Sanitizer
{
	// La auditoría conserva cambios administrativos; los identificadores y contactos personales se enmascaran.
	public static function clean(mixed $value, int $depth = 0): mixed
	{
		if ($depth>12) return '[omitido]';
		if (is_object($value)) $value = get_object_vars($value);
		if (is_array($value)) {
			$clean = [];
			foreach (array_slice($value,0,200,true) as $key=>$item) {
				if (preg_match('/password|passwd|contrase|hash|token|csrf|secret|llave|private.?key|api.?key|access.?key|authorization|cookie|session|sesion|dump|backup.?content|credencial|credential|ruta|physical.?path/i',(string) $key)) continue;
				if (preg_match('/^(curp|clave_elector|elector_key|ocr|email|telefono.*|phone.*|latitud|longitud|latitude|longitude|domicilio|address|calle|street|numero_exterior|numero_interior|codigo_postal|postal_code|referencias|references)$/i',(string) $key)) { $clean[$key] = $item === null ? null : '[protegido]'; continue; }
				$clean[$key] = self::clean($item,$depth+1);
			}
			return $clean;
		}
		if (is_string($value)) {
			if (!mb_check_encoding($value,'UTF-8')) return '[binario omitido]';
			$value = preg_replace('/Bearer\s+\S+|\$2[aby]\$\S+|-----BEGIN .*?PRIVATE KEY-----.*?-----END .*?PRIVATE KEY-----/si','[secreto omitido]',$value);
			$value = preg_replace('/\b(password|passwd|token|secret|api_key|csrf)\s*[:=]\s*["\']?[^\s,"\'&}]+/i','$1=[omitido]',$value);
			foreach (['DB_PASSWORD','APP_ENCRYPTION_KEY','SMTP_PASSWORD','WHATSAPP_TOKEN','BACKUP_ENCRYPTION_KEY'] as $setting) {
				$secret=(string) env($setting,'');
				if (strlen($secret)>=4) $value=str_replace($secret,'[secreto omitido]',$value);
			}
			return mb_substr($value,0,2000);
		}
		return is_scalar($value) || $value===null ? $value : '[omitido]';
	}
	public static function error(\Throwable $error): string
	{
		// Nunca persiste mensajes de PDO, SDK o SMTP: pueden contener SQL, destinatarios y credenciales.
		return $error instanceof ServiceFailure ? $error->getMessage() : 'La operación no se completó. Revisar configuración, disponibilidad e integridad.';
	}
}
