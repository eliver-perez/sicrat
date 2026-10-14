<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CommonRepository;
use App\Services\GeographyService;

final class PollingBoothsController extends Controller
{
    private function service(): GeographyService {return new GeographyService(new CommonRepository((new Database())->getConnection()));}
    public function types(Request $request,Response $response): void {$response->success($this->service()->boothTypes());}
    public function index(Request $request,Response $response): void {$response->success($request->method()==='POST' ? $this->service()->saveBooth(null,$request->input()) : $this->service()->booths($request->query()));}
    public function detail(Request $request,Response $response): void {$response->success($request->method()==='PUT' ? $this->service()->saveBooth($request->route('id'),$request->input()) : $this->service()->booth($request->route('id')));}
}
