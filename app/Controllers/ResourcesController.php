<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CommonRepository;
use App\Services\Resources\Factory;
use App\Services\Resources\Catalog;
use App\Services\Resources\ExpenseService;
use App\Services\Resources\BudgetService;
use App\Services\Resources\AdvanceService;
use App\Services\Resources\ReimbursementService;
use App\Services\Resources\TreasuryService;
use App\Services\Resources\InventoryService;
use App\Services\Resources\ContributionService;

class ResourcesController extends Controller
{
	private CommonRepository $repository;
	public function __construct() {
		parent::__construct();
		$this->repository=new CommonRepository((new Database())->getConnection());
	}

	public function index(Request $request,Response $response): void
	{
		$service=Factory::service($this->repository,$request->route('entity'));
		$response->success($request->method()==='POST' ? $service->create($request->input()) : $service->listing($request->query()),$request->method()==='POST' ? 'Registro creado.' : '',$request->method()==='POST' ? 201 : 200);
	}

	public function detail(Request $request,Response $response): void
	{
		$service=Factory::service($this->repository,$request->route('entity'));
		$response->success($request->method()==='PUT' ? $service->update($request->route('id'),$request->input()) : $service->detail($request->route('id')));
	}

	public function related(Request $request,Response $response): void
	{
		$service=Factory::service($this->repository,$request->route('entity'));
		$response->success(match($request->route('action')) {
			'history'=>$service->history($request->route('id'),$request->query()),'files'=>$service->files($request->route('id'),$request->query()),default=>throw new \InvalidArgumentException('Consulta no disponible.')
		});
	}

	public function action(Request $request,Response $response): void
	{
		$entity=$request->route('entity');
		$service=Factory::service($this->repository,$entity);
		$uuid=$request->route('id');
		$data=$request->input();
		$action=$request->route('action');
		$result=match(true) {
			$service instanceof ExpenseService && $action==='commit'=>$service->commit($uuid), $service instanceof ExpenseService && $action==='submit'=>$service->submit($uuid),$service instanceof ExpenseService && $action==='pay'=>$service->pay($uuid,$data),$service instanceof ExpenseService && $action==='cancel'=>$service->cancel($uuid,$data),
			$service instanceof BudgetService && $action==='revise'=>$service->revise($uuid,$data),$service instanceof BudgetService && $action==='transfer'=>$service->transfer($uuid,$data),
			$service instanceof AdvanceService && $action==='deliver'=>$service->deliver($uuid,$data),$service instanceof AdvanceService && $action==='justify'=>$service->justify($uuid,$data),$service instanceof AdvanceService && $action==='return'=>$service->returnMoney($uuid,$data),$service instanceof AdvanceService && $action==='cancel'=>$service->cancel($uuid,$data),
			$service instanceof ReimbursementService && $action==='review'=>$service->review($uuid,$data),$service instanceof ReimbursementService && $action==='pay'=>$service->pay($uuid,$data),
			$service instanceof TreasuryService && $action==='receive'=>$service->receive($uuid,$data),$service instanceof TreasuryService && $action==='cancel'=>$service->cancel($uuid,$data),
			$service instanceof ContributionService && $action==='validate'=>$service->validate($uuid,$data),$service instanceof ContributionService && $action==='receive'=>$service->receive($uuid,$data),$service instanceof ContributionService && $action==='cancel'=>$service->cancel($uuid,$data),
			$service instanceof InventoryService && $action==='return'=>$service->returnItems($uuid,$data),$service instanceof InventoryService && $action==='reverse'=>$service->reverse($uuid,$data),$entity==='pagos' && $action==='reverse'=>(new ExpenseService($this->repository))->reversePayment($uuid,$data),
			default=>throw new \InvalidArgumentException('Acción no disponible para el recurso.'),
		};
		$response->success($result,'Operación registrada.');
	}

	public function options(Request $request,Response $response): void {
		$response->success((new \App\Services\Resources\Options($this->repository))->listing($request->query()));
	}

	public function summary(Request $request,Response $response): void
	{
		$module=$request->query('module') ?? 'finanzas';
		if(!in_array($module,['finanzas','inventario'],true)) throw new \InvalidArgumentException('Módulo inválido.');
		$response->success((new \App\Services\Resources\Overview($this->repository))->summary($module));
	}

	public function flow(Request $request,Response $response): void {
		$response->success((new TreasuryService($this->repository))->flow($request->query()));
	}

	public function stocks(Request $request,Response $response): void {
		$response->success((new InventoryService($this->repository))->stocks($request->query()));
	}

	public function transferStock(Request $request,Response $response): void {
		$response->success((new InventoryService($this->repository))->transfer($request->input()),'Transferencia registrada.',201);
	}

	public function minimum(Request $request,Response $response): void {
		$response->success((new InventoryService($this->repository))->minimum($request->input()));
	}
}
