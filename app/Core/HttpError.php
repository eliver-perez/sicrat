<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class HttpError extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}
