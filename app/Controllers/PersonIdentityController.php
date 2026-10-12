<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PersonIdentityRepository;
use App\Services\PersonIdentityService;

class PersonIdentityController extends Controller
{
    private function service(): PersonIdentityService { return new PersonIdentityService(new PersonIdentityRepository((new Database())->getConnection())); }

    public function store(Request $request, Response $response): void
    {
        $person = $this->service()->create($request->input());
        if (!$person['created']) {
            $response->json(['success' => false, 'message' => $person['blocked'] ? 'Ya existe una persona con ese identificador.' : 'Hay posibles coincidencias. Revisa antes de continuar.', 'data' => $person], 409);
            return;
        }
        $response->success(['id' => $person['uuid'], 'code' => $person['code']], 'Persona registrada.', 201);
    }

    public function show(Request $request, Response $response): void { if (!is_uuid($request->route('id'))) throw new \App\Core\HttpError('La persona no está disponible.',404); $response->success($this->service()->detail($request->route('id'))); }
    public function update(Request $request, Response $response): void { $response->success($this->service()->update($request->route('id'), $request->input()), 'Información guardada.'); }
    public function participation(Request $request, Response $response): void { $this->service()->participation($request->route('id'), $request->input()); $response->success(null, 'Participación actualizada.'); }
    public function policy(Request $request, Response $response): void { $response->success($this->service()->policy($request->route('id'), $request->method() === 'GET' ? null : $request->input()), 'Configuración de identidad.'); }
    public function duplicates(Request $request, Response $response): void
    {
        $service = $this->service();
        $data = $request->input();
        $response->success($service->inspect($service->organization($data['organization'] ?? null), $service->normalize($data)));
    }
}
