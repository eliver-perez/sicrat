<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CommonRepository;
use App\Services\Operations\BaseService;
use App\Services\Operations\Catalog;
use App\Services\Operations\CaseService;
use App\Services\Operations\EventService;
use App\Services\Operations\GoalService;
use App\Services\Operations\InteractionService;
use App\Services\Operations\MessagingService;
use App\Services\Operations\OptionsService;
use App\Services\Operations\SurveyService;
use App\Services\Operations\TaskService;
use App\Services\Operations\TimelineService;
use App\Services\Operations\TransportService;

class OperationsController extends Controller
{
	private CommonRepository $repository;
	public function __construct() { parent::__construct(); $this->repository=new CommonRepository((new Database())->getConnection()); }
	private function service(string $entity): BaseService
	{
		Catalog::entity($entity);
		return match($entity) {
			'interacciones'=>new InteractionService($this->repository), 'encuestas'=>new SurveyService($this->repository),
			'tareas'=>new TaskService($this->repository), 'metas'=>new GoalService($this->repository), 'eventos'=>new EventService($this->repository),
			'peticiones','incidencias'=>new CaseService($this->repository,$entity), 'transporte'=>new TransportService($this->repository), 'conversaciones'=>new MessagingService($this->repository),
		};
	}
	public function index(Request $request,Response $response): void
	{
		$service=$this->service($request->route('entity'));
		if ($request->method()==='GET') $response->success($service->listing($request->query()));
		elseif ($service instanceof TransportService) throw new HttpError('Actualiza la logística desde la persona participante.',405);
		else $response->success($service->create($request->input()),'Registro creado.',201);
	}
	public function detail(Request $request,Response $response): void
	{
		$service=$this->service($request->route('entity')); $uuid=$request->route('id');
		if ($request->method()==='PUT') {
			if ($service instanceof InteractionService || $service instanceof MessagingService) throw new HttpError('Usa la acción específica del módulo.',405);
			$result=$service->update($uuid,$request->input());
			$response->success($result ?? null,'Cambios guardados.'); return;
		}
		$response->success($service->detail($uuid));
	}
	public function related(Request $request,Response $response): void
	{
		$entity=$request->route('entity');
		if (!in_array($entity,['interacciones','encuestas','tareas','metas','eventos','peticiones','incidencias'],true)) throw new HttpError('Consulta no disponible para este recurso.',405);
		$service=$this->service($entity); $uuid=$request->route('id');
		$action=$request->route('action') ?? basename($request->path());
		$result=match($action) {
			'history'=>$service->history($uuid,$request->query()), 'files'=>$service->files($uuid,$request->query()),
			default=>throw new \InvalidArgumentException('Consulta inválida.'),
		}; $response->success($result);
	}
	public function action(Request $request,Response $response): void
	{
		$entity=$request->route('entity'); $service=$this->service($entity); $uuid=$request->route('id'); $data=$request->input(); $action=$request->route('action');
		$result=match(true) {
			$action==='comment' && in_array($entity,['tareas','eventos','peticiones','incidencias'],true)=>$service->comment($uuid,$data),
			$service instanceof SurveyService && $action==='administer'=>$service->administer($uuid,$data),
			$service instanceof SurveyService && $action==='publish'=>$service->publish($uuid,$data),
			$service instanceof SurveyService && $action==='version'=>$service->newVersion($uuid,$data),
			$service instanceof SurveyService && $action==='answer'=>$service->answer($uuid,$data),
			$service instanceof TaskService && $action==='state'=>$service->state($uuid,$data),
			$service instanceof GoalService && $action==='progress'=>$service->recordProgress($uuid,$data),
			$service instanceof EventService && $action==='attendance'=>$service->attendance($uuid,$data),
			$service instanceof CaseService && $action==='reassign'=>$service->reassign($uuid,$data),
			$service instanceof CaseService && $action==='close'=>$service->close($uuid,$data),
			$service instanceof CaseService && $action==='cancel'=>$service->cancel($uuid,$data),
			$service instanceof TransportService && $action==='confirm'=>$service->confirm($uuid,$data),
			$service instanceof MessagingService && $action==='send'=>$service->send($uuid,$data),
			$service instanceof MessagingService && $action==='read'=>$service->read($uuid,$data),
			$service instanceof MessagingService && $action==='mute'=>$service->mute($uuid,$data),
			$service instanceof MessagingService && $action==='membership'=>$service->membership($uuid,$data),
			default=>throw new \InvalidArgumentException('Acción inválida para este módulo.'),
		}; $response->success(is_int($result) ? ['updated'=>$result] : $result,'Acción realizada.');
	}
	public function query(Request $request,Response $response): void
	{
		$service=$this->service($request->route('entity')); $uuid=$request->route('id'); $action=$request->route('action');
		$result=match(true) {
			$service instanceof SurveyService && $action==='results'=>$service->results($uuid,$request->query()),
			$service instanceof SurveyService && $action==='applications'=>$service->applications($uuid,$request->query()),
			$service instanceof TaskService && $action==='items'=>$service->items($uuid,$request->query()),
			$service instanceof GoalService && $action==='children'=>$service->children($uuid,$request->query()),
			$service instanceof EventService && $action==='participants'=>$service->participants($uuid,$request->query()),
			$service instanceof MessagingService && $action==='messages'=>$service->messages($uuid,$request->query()),
			$service instanceof MessagingService && $action==='members'=>$service->members($uuid,$request->query()),
			default=>throw new \InvalidArgumentException('Consulta inválida para este módulo.'),
		}; $response->success($result);
	}
	public function people(Request $request,Response $response): void { $response->success((new OptionsService($this->repository))->people($request->route('entity'),$request->query())); }
	public function capabilities(Request $request,Response $response): void
	{
		$process=\App\Core\Auth::processId();
		$organization=$process ? $this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$process])['organizacion'] : null;
		$modules=$organization===null ? [] : (new \App\Repositories\ModuleRepository($this->repository->connection()))->catalog((int) $organization,$process);
		$election=$process ? $this->repository->one('SELECT uuid,proceso nombre FROM procesos_electorales WHERE id=?',[$process]) : null;
		if ($election) $election['uuid']=\App\Services\Common\Values::uuidText($election['uuid']);
		$response->success(['election'=>$election,'modules'=>array_values(array_map(static fn($module)=>['code'=>$module['codigo'],'enabled'=>$module['habilitado'] && (bool) $module['implementado']],$modules))]);
	}
	public function elections(Request $request,Response $response): void { $response->success((new OptionsService($this->repository))->elections($request->query())); }
	public function approvers(Request $request,Response $response): void { $response->success((new OptionsService($this->repository))->approvers($request->route('entity'),$request->query())); }
	public function timeline(Request $request,Response $response): void { $response->success((new TimelineService($this->repository))->activity($request->route('id'),$request->query())); }
	public function types(Request $request,Response $response): void { $response->success((new EventService($this->repository))->types($request->query(),$request->method()==='POST' ? $request->input() : null)); }
	public function indicators(Request $request,Response $response): void { $response->success((new GoalService($this->repository))->indicators()); }
	public function transportSummary(Request $request,Response $response): void { $response->success((new TransportService($this->repository))->summary($request->query())); }
	public function contacts(Request $request,Response $response): void { $response->success((new MessagingService($this->repository))->contacts($request->query())); }
	public function policy(Request $request,Response $response): void { $response->success((new MessagingService($this->repository))->policy($request->query(),$request->method()==='PUT' ? $request->input() : null)); }
	public function outgoing(Request $request,Response $response): void { $response->success(['id'=>(new MessagingService($this->repository))->outgoing($request->input())],'Comunicación autorizada en cola.',202); }
	public function qr(Request $request,Response $response): void { $response->success((new EventService($this->repository))->qr($request->route('id'),$request->route('participant'))); }
	public function surveyResponse(Request $request,Response $response): void
	{
		$service=new SurveyService($this->repository);
		if ($request->method()==='PUT') $service->correct($request->route('id'),$request->route('response'),$request->input());
		$response->success($service->response($request->route('id'),$request->route('response')));
	}
	public function receipts(Request $request,Response $response): void { $response->success((new MessagingService($this->repository))->receipts($request->route('id'),$request->route('message'),$request->query())); }
	public function moderate(Request $request,Response $response): void { (new MessagingService($this->repository))->moderate($request->route('id'),$request->route('message'),$request->input()); $response->success(); }
}
