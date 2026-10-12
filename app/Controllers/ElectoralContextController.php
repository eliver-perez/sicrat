<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Pagination;
use App\Core\Request;
use App\Core\Response;
use App\Services\ElectoralContextService;

class ElectoralContextController extends Controller
{
    public function index(Request $request, Response $response): void
    {
        $limit = Pagination::limit($request->query('limit', 25));
        $offset = Pagination::offset($request->query('offset', 0));
        $rows = (new ElectoralContextService((new Database())->getConnection()))->list($limit + 1, $offset);
        $response->json(['success' => true, 'data' => ['electoral_processes' => array_slice($rows, 0, $limit)], 'pagination' => Pagination::response($rows, $limit, $offset)]);
    }

    public function select(Request $request, Response $response): void
    {
        $uuid = $request->input('process');
        if (!is_string($uuid)) throw new \InvalidArgumentException('Selecciona una elección.');
        $data = (new ElectoralContextService((new Database())->getConnection()))->select($uuid);
        $response->success($data, 'Elección seleccionada.');
    }
}
