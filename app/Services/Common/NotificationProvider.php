<?php

declare(strict_types=1);

namespace App\Services\Common;

interface NotificationProvider
{
	public function assertReady(): void;
	/** Devuelve referencia del proveedor; nunca devuelve credenciales ni la respuesta cruda. */
	public function send(array $delivery): string;
}
