<?php

declare(strict_types=1);

namespace App\Services\Common;

class ServiceFailure extends \RuntimeException
{
	public function __construct(string $message, public readonly bool $retryable = true) { parent::__construct($message); }
}
