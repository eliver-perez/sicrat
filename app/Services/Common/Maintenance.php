<?php

declare(strict_types=1);

namespace App\Services\Common;

final class Maintenance
{
	public static function path(): string { return (string) env('SICRAT_MAINTENANCE_FILE',dirname(__DIR__,3).'/storage/private/maintenance.flag'); }
	public static function active(): bool { return is_file(self::path()); }
}
