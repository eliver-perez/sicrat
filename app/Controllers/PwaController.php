<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Pwa\OfflineContext;

final class PwaController extends Controller
{
    public function ping(Request $request, Response $response): void
    {
        $response->success(['sicrat_pwa' => 1]);
    }

    public function context(Request $request, Response $response): void
    {
        $response->success(OfflineContext::current());
    }
}
