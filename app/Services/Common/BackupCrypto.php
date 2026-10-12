<?php

declare(strict_types=1);

namespace App\Services\Common;

class BackupCrypto
{
	private const CHUNK = 1048576;
	public function __construct(private string $encodedKey) {}
	private function key(): string
	{
		$key=base64_decode($this->encodedKey,true);
		if ($key===false || strlen($key)!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) throw new ServiceFailure('Llave privada de respaldo inválida.',false);
		return $key;
	}
	public function encrypt(string $source,string $target): void
	{
		$key=$this->key(); [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key); sodium_memzero($key);
		$input=fopen($source,'rb'); $output=fopen($target,'xb'); if (!$input || !$output) throw new ServiceFailure('No se pudo preparar cifrado.'); chmod($target,0600);
		try {
			fwrite($output,'SICRATB1'.$header);
			while (!feof($input)) { $chunk=fread($input,self::CHUNK); $encrypted=sodium_crypto_secretstream_xchacha20poly1305_push($state,$chunk,'',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE); fwrite($output,pack('N',strlen($encrypted)).$encrypted); }
			$final=sodium_crypto_secretstream_xchacha20poly1305_push($state,'','',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL); fwrite($output,pack('N',strlen($final)).$final);
		} finally { fclose($input); fclose($output); sodium_memzero($state); }
	}
	public function decrypt(string $source,string $target): void
	{
		$input=fopen($source,'rb'); $output=fopen($target,'xb'); if (!$input || !$output) throw new ServiceFailure('No se pudo preparar descifrado.'); chmod($target,0600);
		$state=null; $complete=false;
		try {
			if (fread($input,8)!=='SICRATB1') throw new ServiceFailure('Cabecera cifrada inválida.',false);
			$header=fread($input,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES); $key=$this->key(); $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key); sodium_memzero($key);
			while (!feof($input)) {
				$length=fread($input,4); if (strlen($length)!==4) throw new ServiceFailure('Respaldo cifrado truncado.',false);
				$length=unpack('N',$length)[1]; if ($length<17 || $length>self::CHUNK+17) throw new ServiceFailure('Bloque cifrado inválido.',false);
				$encrypted=''; while (strlen($encrypted)<$length && !feof($input)) $encrypted.=fread($input,$length-strlen($encrypted));
				$result=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$encrypted);
				if ($result===false) throw new ServiceFailure('Integridad cifrada inválida.',false);
				[$chunk,$tag]=$result; fwrite($output,$chunk);
				if ($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) { if (fread($input,1)!=='') throw new ServiceFailure('Datos posteriores al cierre cifrado.',false); $complete=true; break; }
			}
			if (!$complete) throw new ServiceFailure('Falta el cierre autenticado del respaldo.',false);
		} catch (\Throwable $error) { fclose($output); $output=null; if (is_file($target)) unlink($target); throw $error; }
		finally { fclose($input); if (is_resource($output)) fclose($output); if (is_string($state)) sodium_memzero($state); }
	}
}
