<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PersonImportRepository;
use App\Services\PersonImportService;

class PersonImportController extends Controller
{
    private function service(): PersonImportService { return new PersonImportService(new PersonImportRepository((new Database())->getConnection())); }
    public function upload(Request $request, Response $response): void { $response->success($this->service()->upload($request->file('file') ?? [], $request->input('organization'), $request->input('delimiter',',')), 'Archivo cargado.',201); }
    public function configure(Request $request, Response $response): void { $response->success($this->service()->configure($request->route('id'),$request->input())); }
    public function preview(Request $request, Response $response): void { $response->success($this->service()->preview($request->route('id'),max(0,(int) $request->input('after',0)))); }
    public function decisions(Request $request, Response $response): void
    {
        $decisions=$request->input('decisions',[]);
        if (!is_array($decisions)) throw new \InvalidArgumentException('Decisiones inválidas.');
        $this->service()->decisions($request->route('id'),$decisions); $response->success();
    }
    public function confirm(Request $request, Response $response): void { $response->success($this->service()->confirm($request->route('id'))); }
    public function apply(Request $request, Response $response): void { $response->success($this->service()->apply($request->route('id'))); }
    public function results(Request $request, Response $response): void { $response->success($this->service()->results($request->route('id'),max(0,(int) $request->query('after',0)))); }
    public function status(Request $request, Response $response): void { $response->success($this->service()->status($request->route('id'))); }
}
