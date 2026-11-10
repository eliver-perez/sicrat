<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Controller,Database,Request,Response};
use App\Repositories\CommonRepository;
use App\Services\Dashboard\{Notes,Summary};

final class DashboardController extends Controller
{
    private CommonRepository $repository;
    public function __construct() { parent::__construct(); $this->repository=new CommonRepository((new Database())->getConnection()); }
    public function index(Request $request,Response $response): void { $response->success((new Summary($this->repository))->get((int)$request->query('days',7))); }
    public function notes(Request $request,Response $response): void
    {
        $notes=new Notes($this->repository); $uuid=$request->route('id');
        $result=match($request->method()) {
            'GET'=>$uuid ? $notes->get($uuid) : $notes->listing((int)$request->query('offset',0)),
            'POST'=>$notes->save(null,$request->input()),
            'PUT'=>$notes->save($uuid,$request->input()),
            'DELETE'=>$notes->delete($uuid),
        };
        $response->success($result);
    }
}
