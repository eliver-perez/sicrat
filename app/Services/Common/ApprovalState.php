<?php

declare(strict_types=1);

namespace App\Services\Common;

use App\Core\HttpError;

final class ApprovalState
{
	public static function pending(string $state): void { if ($state!=='pendiente') throw new HttpError('La solicitud ya fue resuelta.',409); }
	public static function resolver(int $assigned,int $actor,bool $super): void { if ($assigned!==$actor && !$super) throw new HttpError('Esta solicitud corresponde a otro aprobador.',403); }
	public static function decision(bool $approved): string { return $approved ? 'aprobada' : 'rechazada'; }
}
