<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpError;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CommonRepository;
use App\Services\Common\Access;
use App\Services\Common\ApprovalService;
use App\Services\Common\AuditService;
use App\Services\Common\BackupService;
use App\Services\Common\ExportService;
use App\Services\Common\FileService;
use App\Services\Common\NotificationService;
use App\Services\Common\QueueService;
use App\Services\Common\RestoreService;
use App\Services\Common\Values;

class CommonServicesController extends Controller
{
	private CommonRepository $repository;
	public function __construct() { parent::__construct(); $this->repository=new CommonRepository((new Database())->getConnection()); }
	private function context(Request $request): array
	{
		$process=$request->input('process',$request->query('process',Auth::processId()));
		$process=$process===null || $process==='' ? null : Values::integer($process);
		$organization=$request->input('organization',$request->query('organization',Auth::organizationId()));
		if (($organization===null || $organization==='') && $process!==null) $organization=$this->repository->one('SELECT organizacion FROM procesos_electorales WHERE id=?',[$process])['organizacion'] ?? null;
		return [Values::integer($organization),$process];
	}
	public function audit(Request $request,Response $response): void { $response->success((new AuditService($this->repository))->listing($request->query())); }
	public function auditOptions(Request $request,Response $response): void { $response->success((new AuditService($this->repository))->options($request->query())); }
	public function approvals(Request $request,Response $response): void { $response->success((new ApprovalService($this->repository))->listing($request->query())); }
	public function approval(Request $request,Response $response): void
	{
		$service=new ApprovalService($this->repository); $uuid=$request->route('id');
		if ($request->method()==='PUT') $service->resolve($uuid,(bool) Values::integer($request->input('approved'),0,1),Values::text($request->input('reason'),500));
		$response->success($service->detail($uuid));
	}
	public function cancelApproval(Request $request,Response $response): void { (new ApprovalService($this->repository))->cancel($request->route('id'),Values::text($request->input('reason'),500)); $response->success(); }
	public function upload(Request $request,Response $response): void
	{
		[$organization,$process]=$this->context($request);
		$entity=Values::text($request->input('entity'),80); $record=$request->input('record');
		if(in_array($entity,['jornada_borradores','jornada_partidos','jornada_candidaturas'],true) && is_string($record) && !ctype_digit($record)) $record=$this->repository->one("SELECT id FROM $entity WHERE uuid=?",[Values::binary($record)])['id'] ?? throw new HttpError('Recurso electoral no disponible.',404);
		if ($entity==='personas' && is_string($record) && !ctype_digit($record)) $record=$this->repository->one('SELECT id FROM personas WHERE uuid=?',[Values::binary($record)])['id'] ?? throw new HttpError('Persona no disponible.',404);
		elseif (is_string($record) && !ctype_digit($record) && (isset(\App\Services\Operations\Catalog::ENTITIES[$entity]) || $entity==='mensajes')) {
			$record=$this->repository->one("SELECT id FROM $entity WHERE uuid=?",[Values::binary($record)])['id'] ?? throw new HttpError('Recurso no disponible.',404);
		}
		elseif (is_string($record) && !ctype_digit($record) && in_array($entity,array_column(\App\Services\Resources\Catalog::ENTITIES,0),true)) {
			$record=$this->repository->one("SELECT id FROM $entity WHERE uuid=?",[Values::binary($record)])['id'] ?? throw new HttpError('Recurso no disponible.',404);
		}
		$response->success((new FileService($this->repository))->upload($request->file('file') ?? [],$organization,$process,$entity,Values::integer($record)),'Archivo procesado.');
	}
	public function deleteFile(Request $request,Response $response): void { (new FileService($this->repository))->delete($request->route('id')); $response->success(); }
	public function media(Request $request,Response $response): void
	{
		$this->stream((new FileService($this->repository))->authorized($request->route('id'),$request->query('size')==='thumb' ? 'thumb' : ($request->query('size')===null ? 'normal' : Values::text($request->query('size'),16))));
	}
	private function stream(array $file): void
	{
		header('Content-Type: '.$file['mime']); header('Content-Length: '.$file['size']); header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store, max-age=0'); header('Pragma: no-cache'); header("Content-Security-Policy: default-src 'none'; sandbox");
		header('Content-Disposition: '.($file['attachment'] ? 'attachment' : 'inline').'; filename="archivo"; filename*=UTF-8\'\''.rawurlencode($file['name']));
		if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
		readfile($file['path']);
	}
	public function notifications(Request $request,Response $response): void { $response->success((new NotificationService($this->repository))->listing($request->query())); }
	public function readNotifications(Request $request,Response $response): void
	{
		$uuids=$request->input('ids',[]); if (!is_array($uuids)) throw new \InvalidArgumentException('Notificaciones inválidas.');
		$response->success(['updated'=>(new NotificationService($this->repository))->read($uuids,(bool) Values::integer($request->input('all',0),0,1))]);
	}
	public function notificationConfiguration(Request $request,Response $response): void
	{
		[$organization]=$this->context($request); $response->success((new NotificationService($this->repository))->configuration($organization,$request->method()==='PUT' ? $request->input() : null));
	}
	public function deliveries(Request $request,Response $response): void
	{
		[$organization,$process]=$this->context($request);
		(new Access($this->repository))->broad($organization,$process,'notificaciones.administrar');
		$limit=\App\Core\Pagination::limit($request->query('limit',25)); $offset=\App\Core\Pagination::offset($request->query('offset',0));
		$rows=$this->repository->rows('SELECT id,canal,estado,intentos,externo_id,error,fecha,enviado_en FROM notificaciones_envios WHERE organizacion=? AND proceso <=> ? ORDER BY fecha DESC,id LIMIT '.($limit+1).' OFFSET '.$offset,[$organization,$process]);
		$response->success(['rows'=>array_slice($rows,0,$limit),'pagination'=>\App\Core\Pagination::response($rows,$limit,$offset)]);
	}
	public function exports(Request $request,Response $response): void
	{
		$service=new ExportService($this->repository);
		if ($request->method()==='GET') { $response->success($service->listing($request->query())); return; }
		[$organization,$process]=$this->context($request); $filters=$request->input('filters',[]); if (!is_array($filters)) throw new \InvalidArgumentException('Filtros inválidos.');
		$response->success(['uuid'=>$service->request($organization,$process,Values::text($request->input('type'),80),Values::text($request->input('format'),8),$filters)],'Exportación en cola.',202);
	}
	public function exportDownload(Request $request,Response $response): void { $this->stream((new ExportService($this->repository))->download($request->route('id'))); }
	public function jobs(Request $request,Response $response): void { $response->success((new QueueService($this->repository))->listing($request->query())); }
	public function administerJob(Request $request,Response $response): void { if (!in_array($request->route('action'),['retry','cancel'],true)) throw new \InvalidArgumentException('Acción inválida.'); (new QueueService($this->repository))->administer($request->route('id'),$request->route('action')==='retry'); $response->success(); }
	public function backups(Request $request,Response $response): void
	{
		(new Access($this->repository))->super(); $service=new BackupService($this->repository);
		if ($request->method()==='GET') $response->success($service->listing($request->query()));
		else $response->success(['id'=>$service->request(Values::text($request->input('type'),16))],'Respaldo en cola.',202);
	}
	public function backupPolicy(Request $request,Response $response): void { $response->success((new BackupService($this->repository))->policy($request->method()==='PUT' ? $request->input() : null)); }
	public function verifyBackup(Request $request,Response $response): void
	{
		(new Access($this->repository))->super(); $id=Values::uuidText(Values::binary($request->route('id')));
		if (!$this->repository->one("SELECT id FROM respaldos WHERE id=? AND estado='completado'",[$id])) throw new HttpError('Respaldo no disponible.',404);
		$job=(new QueueService($this->repository))->enqueue('verificar_respaldo',['respaldo_id'=>$id],null,null,Auth::id(),null,5,1); $response->success(['job'=>$job],'Verificación en cola.',202);
	}
	public function protectBackup(Request $request,Response $response): void { (new BackupService($this->repository))->protect($request->route('id'),(bool) Values::integer($request->input('protected'),0,1)); $response->success(); }
	public function restorePreview(Request $request,Response $response): void { $response->success((new RestoreService($this->repository))->preview($request->route('id'))); }
	public function restore(Request $request,Response $response): void
	{
		$response->success(['id'=>(new RestoreService($this->repository))->request($request->route('id'),Values::text($request->input('confirmation'),100),Values::text($request->input('review'),64))],'Restauración en cola.',202);
	}
}
