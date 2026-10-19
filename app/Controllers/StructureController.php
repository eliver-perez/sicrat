<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Pagination;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\StructureRepository;
use App\Services\StructureService;

class StructureController extends Controller
{
    private function service(): StructureService { return new StructureService(new StructureRepository((new Database())->getConnection())); }
    private function page(Request $request): array { return ['limit'=>Pagination::limit($request->query('limit',25))+1,'offset'=>Pagination::offset($request->query('offset',0))]; }
    private function paged(Response $response, array $rows, array $page): void
    {
        $limit = $page['limit']-1; $response->json(['success'=>true,'data'=>array_slice($rows,0,$limit),'pagination'=>Pagination::response($rows,$limit,$page['offset'])]);
    }
    public function index(Request $request, Response $response): void { $page=$this->page($request); $this->paged($response,$this->service()->index($page,$request->query('assignment')==='1'),$page); }
    public function assignmentOptions(Request $request, Response $response): void { $response->success($this->service()->assignmentOptions($request->route('id'),$this->page($request),(string)$request->query('search',''),$request->query('parent'))); }
    public function store(Request $request, Response $response): void { $response->success($this->service()->create($request->input()),'Estructura creada.',201); }
    public function show(Request $request, Response $response): void
    {
        $page=$this->page($request); $data=$this->service()->show($request->route('id'),$page,$request->query('parent'),(string)$request->query('search','')); $members=$data['members']; $data['members']=array_slice($members,0,$page['limit']-1);
        $response->json(['success'=>true,'data'=>$data,'pagination'=>Pagination::response($members,$page['limit']-1,$page['offset'])]);
    }
    public function associate(Request $request, Response $response): void { $this->service()->associate($request->route('id'),$request->input()); $response->success(null,'Asociación actualizada.'); }
    public function clone(Request $request, Response $response): void { $response->success($this->service()->clone($request->route('id'),$request->input()),'Estructura clonada.',201); }
    public function add(Request $request, Response $response): void { $response->success($this->service()->add($request->route('id'),$request->input()),'Integrante incorporado.',201); }
    public function detail(Request $request, Response $response): void { $response->success($this->service()->detail($request->route('id'),$request->route('member'),$this->page($request))); }
    public function level(Request $request, Response $response): void { $this->service()->changeLevel($request->route('id'),$request->route('member'),$request->input()); $response->success(null,'Nivel actualizado.'); }
    public function state(Request $request, Response $response): void { $this->service()->state($request->route('id'),$request->route('member'),$request->input()); $response->success(null,'Estado actualizado.'); }
    public function transfer(Request $request, Response $response): void { $response->success($this->service()->transfer($request->route('id'),$request->route('member'),$request->input()),'Transferencia registrada.'); }
    public function resolve(Request $request, Response $response): void { $this->service()->resolveTransfer($request->route('id'),$request->route('transfer'),$request->input()); $response->success(null,'Solicitud resuelta.'); }
    public function requests(Request $request, Response $response): void { $page=$this->page($request); $this->paged($response,$this->service()->requests($request->route('id'),$page),$page); }
    public function history(Request $request, Response $response): void { $page=$this->page($request); $this->paged($response,$this->service()->history($request->route('id'),$page),$page); }
}
