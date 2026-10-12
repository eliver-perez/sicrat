<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserAccessRepository;
use App\Services\UserAccessService;

class UserAccessController extends Controller
{
    private function service(): UserAccessService { return new UserAccessService(new UserAccessRepository((new Database())->getConnection())); }
    public function show(Request $request, Response $response): void { $response->success($this->service()->detail($request->route('id'))); }
    public function assign(Request $request, Response $response): void { $this->service()->assign($request->route('id'), $request->input()); $response->success(null, 'Acceso actualizado.'); }
    public function link(Request $request, Response $response): void
    {
        $person = $request->input('person');
        if ($person !== null && !is_string($person)) throw new \InvalidArgumentException('Persona inválida.');
        $this->service()->link($request->route('id'), $person);
        $response->success(null, 'Vínculo actualizado.');
    }
}
