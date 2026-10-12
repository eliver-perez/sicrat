<?php

declare(strict_types=1);

namespace App\Services\Common;

interface BackupDestination
{
	public function path(string $relative,bool $createParent = false): string;
	public function available(): ?float;
	public function remove(string $relative): void;
	public function label(): string;
}
