<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AdministrationRepository;
use App\Services\AdministrationService;

class AdministrationController extends Controller
{
    private function service(): AdministrationService { return new AdministrationService(new AdministrationRepository((new Database())->getConnection())); }
    public function levels(Request $request, Response $response): void { $response->success($this->service()->levels($request->query('election'))); }
    public function levelRoles(Request $request, Response $response): void { $response->success($this->service()->levelRoles()); }
    public function level(Request $request, Response $response): void { $response->success($this->service()->saveLevel($request->route('level'),$request->input()),'Nivel guardado.'); }
    public function configuration(Request $request, Response $response): void { $response->success($this->service()->configuration($request->method()==='GET' ? null : $request->input())); }
    public function permissions(Request $request, Response $response): void { $response->success($this->service()->permissions()); }
    public function roles(Request $request, Response $response): void { $response->success($this->service()->roles()); }
    public function role(Request $request, Response $response): void { $response->success($this->service()->saveRole($request->route('role') ? (int) $request->route('role') : null,$request->input())); }
    public function rolePermission(Request $request, Response $response): void { $response->success($this->service()->rolePermission((int) $request->route('role'),$request->method()==='GET' ? null : $request->input())); }
    public function individual(Request $request, Response $response): void { $response->success($this->service()->individual($request->route('id'),$request->method()==='GET' ? null : $request->input())); }
    public function territories(Request $request, Response $response): void { $response->success($this->service()->territories($request->route('id'),$request->method()==='GET' ? null : $request->input())); }
    public function modules(Request $request, Response $response): void { $response->success($this->service()->modules($request->route('id'),$request->query('election'))); }
    public function module(Request $request, Response $response): void { $this->service()->saveModule($request->route('id'),$request->route('module'),$request->input()); $response->success(null,'Módulo actualizado.'); }
}
