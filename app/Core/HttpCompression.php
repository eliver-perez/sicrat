<?php

declare(strict_types=1);

namespace App\Core;

final class HttpCompression
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli' || ini_get('zlib.output_compression') || !extension_loaded('zlib') || headers_sent()) return;
        foreach (ob_list_handlers() as $handler) {
            if (str_contains(strtolower($handler), 'gz') || str_contains(strtolower($handler), 'zlib')) return;
        }
        // PHP may already have its ordinary output_buffering buffer. It does not compress bytes.
        ob_start('ob_gzhandler');
    }
}
