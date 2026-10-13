<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CommonRepository;
use App\Services\Jornada\Captures;
use App\Services\Jornada\Configuration;
use App\Services\Jornada\Notifications;
use App\Services\Jornada\Queries;

class JornadaController extends Controller
{
    private CommonRepository $repository;
    public function __construct() { parent::__construct();$this->repository=new CommonRepository((new Database())->getConnection()); }
    private function input(Request $request): array
    {
        $data=$request->input();
        foreach(['process','proceso','organization','organizacion'] as $key) if(array_key_exists($key,$data)) throw new \InvalidArgumentException('El contexto se elige en el selector de elección, no en el cuerpo de la operación.');
        return $data;
    }
    public function capabilities(Request $request,Response $response): void { $response->success((new Queries($this->repository))->capabilities()); }
    public function accounts(Request $request,Response $response): void { $response->success((new Queries($this->repository))->accounts($request->query())); }
    public function territories(Request $request,Response $response): void { $response->success((new Queries($this->repository))->territories($request->query())); }
    public function configuration(Request $request,Response $response): void
    {
        $service=new Configuration($this->repository);$response->success($request->method()==='PUT' ? $service->save($this->input($request)) : $service->view());
    }
    public function catalog(Request $request,Response $response): void { $response->success((new Configuration($this->repository))->catalog($request->route('entity'),$this->input($request),$request->route('id')), 'Catálogo guardado.'); }
    public function physical(Request $request,Response $response): void { $response->success((new Configuration($this->repository))->booths($request->query())); }
    public function booths(Request $request,Response $response): void
    {
        $response->success($request->method()==='POST' ? (new Configuration($this->repository))->associate($this->input($request)) : (new Queries($this->repository))->listing($request->query()));
    }
    public function booth(Request $request,Response $response): void { $response->success((new Queries($this->repository))->booth($request->route('id'))); }
    public function history(Request $request,Response $response): void { $response->success((new Queries($this->repository))->history($request->route('id'),$request->query())); }
    public function assignments(Request $request,Response $response): void { $response->success((new Configuration($this->repository))->assign($this->input($request))); }
    public function state(Request $request,Response $response): void { $response->success((new Configuration($this->repository))->state($request->route('action'),$this->input($request))); }
    public function draft(Request $request,Response $response): void { $response->success((new Captures($this->repository))->draft($this->input($request))); }
    public function capture(Request $request,Response $response): void {
        if(!in_array($request->route('action'),['captures','corrections'],true)) throw new \InvalidArgumentException('Operación de Jornada Electoral no disponible.');
        $response->success((new Captures($this->repository))->submit($this->input($request),$request->route('action')==='corrections'),'Versión registrada.',201);
    }
    public function version(Request $request,Response $response): void { $response->success((new Queries($this->repository))->version($request->route('id'))); }
    public function decide(Request $request,Response $response): void { $response->success((new Captures($this->repository))->decide($request->route('id'),$this->input($request))); }
    public function summary(Request $request,Response $response): void { $response->success((new Queries($this->repository))->summary($request->query())); }
    public function notifications(Request $request,Response $response): void { $response->success((new Notifications($this->repository))->settings($request->query(),$request->method()==='PUT' ? $this->input($request) : null)); }
}
