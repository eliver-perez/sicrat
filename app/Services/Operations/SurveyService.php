<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Core\HttpError;
use App\Repositories\ScopeRepository;
use App\Services\Common\ApprovalService;
use App\Services\Common\Values;

class SurveyService extends BaseService
{
	protected function entity(): string { return 'encuestas'; }
	private function readPermission(int $process): string
	{
		foreach (['encuestas.ver','encuestas.crear','encuestas.aplicar','encuestas.ver_resultados','encuestas.corregir','encuestas.administrar','encuestas.aprobar'] as $permission) if ($this->context->allowed($permission,$process)) return $permission;
		throw new HttpError('No puedes consultar encuestas.',403);
	}
	public function listing(array $filters): array
	{
		$process=$this->context->process($filters['process'] ?? null);
		[$where,$parameters]=$this->context->predicate('encuestas',$process,$this->readPermission($process));
		if (($filters['search'] ?? '')!=='') { $where.=' AND r.encuesta LIKE ?'; $parameters[]='%'.Values::text($filters['search'],150).'%'; }
		if (($filters['state'] ?? '')!=='') { $where.=' AND r.estado=?'; $parameters[]=Catalog::choice($filters['state'],['borrador','validacion','publicada','archivada']); }
		$result=$this->repository->page("SELECT r.uuid,r.encuesta titulo,r.estado,r.fecha,r.version,r.numero_version FROM encuestas r WHERE $where ORDER BY r.id DESC",$parameters,$filters);
		foreach ($result['rows'] as &$row) $row['uuid']=Values::uuidText($row['uuid']); return $result;
	}
	public function create(array $data): array
	{
		$process=$this->context->process($data['process'] ?? null); $context=$this->context->require('encuestas',$process,'encuestas.crear');
		return $this->repository->transaction(function() use($context,$process,$data) {
			$start=Catalog::dateTime($data['start'] ?? null,true); $end=Catalog::dateTime($data['end'] ?? null,true);
			if ($start!==null && $end!==null && $end<$start) throw new \InvalidArgumentException('Periodo de aplicación inválido.');
			$row=$this->newRow('encuestas',['organizacion'=>$context['organization'],'proceso'=>$process,'encuesta'=>Values::text($data['title'] ?? null,150),'descripcion'=>Values::text($data['description'] ?? '',500,true),'creado_por'=>$context['actor'],'fecha'=>date('Y-m-d H:i:s'),'estado'=>'borrador','inicio'=>$start,'fin'=>$end,'frecuencia'=>Catalog::choice($data['frequency'] ?? 'unica',['unica','multiple']),'intervalo'=>Values::integer($data['interval'] ?? 1,1,1000),'unidad'=>Catalog::choice($data['unit'] ?? 'dias',['horas','dias','semanas','meses'])]);
			$this->repository->execute('UPDATE encuestas SET serie=id WHERE id=?',[$row['id']]);
			$this->questions((int) $row['id'],$data['questions'] ?? []);
			return ['uuid'=>$row['uuid']];
		});
	}
	private function questions(int $survey,mixed $definitions): void
	{
		$definitions=Catalog::list($definitions); $known=[];
		foreach ($definitions as $index=>$question) {
			if (!is_array($question)) throw new \InvalidArgumentException('Pregunta inválida.');
			$code=Values::text($question['key'] ?? 'p'.($index+1),60);
			if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D',$code) || isset($known[$code])) throw new \InvalidArgumentException('Cada pregunta necesita una clave única válida.');
			$type=$this->repository->one('SELECT id,codigo FROM encuestas_preguntas_tipos WHERE codigo=? AND activo=1',[$question['type'] ?? null]) ?? throw new \InvalidArgumentException('Tipo de pregunta inválido.');
			$config=$question['config'] ?? []; if (!is_array($config)) throw new \InvalidArgumentException('Configuración de pregunta inválida.');
			$clean=[];
			if (isset($config['when'])) {
				$when=$config['when'];
				if (!is_array($when) || !isset($known[$when['question'] ?? '']) || !array_key_exists('equals',$when) || !is_scalar($when['equals'])) throw new \InvalidArgumentException('La condición debe referirse a una pregunta anterior y a un valor simple.');
				if (is_string($when['equals'])) Values::text($when['equals'],500,true);
				$clean['when']=['question'=>$when['question'],'equals'=>$when['equals']];
			}
			if (in_array($type['codigo'],['NUMERO','ESCALA'],true)) {
				$min=$config['min'] ?? ($type['codigo']==='ESCALA' ? 1 : -1000000000); $max=$config['max'] ?? ($type['codigo']==='ESCALA' ? 5 : 1000000000);
				if (!is_numeric($min) || !is_numeric($max) || !is_finite((float) $min) || !is_finite((float) $max) || $min>$max || abs((float) $min)>1000000000000 || abs((float) $max)>1000000000000) throw new \InvalidArgumentException('Intervalo de pregunta inválido.');
				$clean['min']=(float) $min; $clean['max']=(float) $max;
			}
			$id=$this->repository->insert('INSERT INTO encuestas_preguntas (encuesta,codigo,pregunta,tipo,obligatorio,orden,configuracion) VALUES (?,?,?,?,?,?,?)',[$survey,$code,Values::text($question['title'] ?? null,500),$type['id'],Values::integer($question['required'] ?? 0,0,1),$index+1,Values::json($clean)]);
			$options=Catalog::list($question['options'] ?? [],50); $values=[];
			if (in_array($type['codigo'],['SELECCION_UNICA','SELECCION_MULTIPLE'],true) && count($options)<2) throw new \InvalidArgumentException('La selección requiere al menos dos opciones.');
			if (!in_array($type['codigo'],['SELECCION_UNICA','SELECCION_MULTIPLE'],true) && $options) throw new \InvalidArgumentException('Este tipo de pregunta no utiliza opciones.');
			foreach ($options as $position=>$option) {
				$label=is_array($option) ? Values::text($option['label'] ?? null,200) : Values::text($option,200);
				$value=is_array($option) ? Values::text($option['value'] ?? (string) ($position+1),100) : (string) ($position+1);
				if (isset($values[$value])) throw new \InvalidArgumentException('Las opciones deben tener valores únicos.'); $values[$value]=true;
				$this->repository->execute('INSERT INTO encuestas_opciones (pregunta,opcion,valor,orden) VALUES (?,?,?,?)',[$id,$label,$value,$position+1]);
			}
			$known[$code]=true;
		}
	}
	public function definition(array $row): array
	{
		$questions=$this->repository->rows('SELECT q.id,q.codigo `key`,q.pregunta title,t.codigo type,q.obligatorio required,q.configuracion config FROM encuestas_preguntas q JOIN encuestas_preguntas_tipos t ON t.id=q.tipo WHERE q.encuesta=? ORDER BY q.orden,q.id LIMIT 101',[$row['id']]);
		if (count($questions)>100) throw new HttpError('La encuesta excede cien preguntas; revisa su definición histórica.',422);
		foreach ($questions as &$question) {
			$question['config']=json_decode($question['config'] ?? '{}',true);
			$question['options']=$this->repository->rows('SELECT id,opcion label,COALESCE(valor,CAST(id AS CHAR)) value FROM encuestas_opciones WHERE pregunta=? ORDER BY orden,id LIMIT 51',[$question['id']]);
			if (count($question['options'])>50) throw new HttpError('La pregunta excede cincuenta opciones.',422);
		}
		return $questions;
	}
	public function detail(string $uuid): array
	{
		$raw=$this->repository->raw('encuestas',$uuid); $process=(int) $raw['proceso'];
		$permission=$this->readPermission($process);
		$row=$this->context->record('encuestas',$uuid,$permission); $row['questions']=$this->definition($row);
		$row['approval_uuid']=$row['aprobacion'] ? Values::uuidText($this->repository->one('SELECT uuid FROM aprobaciones WHERE id=?',[$row['aprobacion']])['uuid']) : null;
		$row['responses']=$this->repository->one('SELECT id FROM encuestas_aplicaciones WHERE encuesta=? LIMIT 1',[$row['id']]) ? 1 : 0;
		return $row;
	}
	public function update(string $uuid,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('encuestas',$uuid,'encuestas.crear',null,true); $this->context->version($row,$data);
			if ($row['estado']!=='borrador' || $this->repository->one('SELECT id FROM encuestas_aplicaciones WHERE encuesta=? LIMIT 1',[$row['id']])) throw new HttpError('Crea una versión nueva para cambiar una encuesta publicada o con respuestas.',409);
			$start=Catalog::dateTime($data['start'] ?? $row['inicio'],true); $end=Catalog::dateTime($data['end'] ?? $row['fin'],true);
			if ($start!==null && $end!==null && $end<$start) throw new \InvalidArgumentException('Periodo inválido.');
			$this->repository->updateRecord('encuestas',(int) $row['id'],['encuesta'=>Values::text($data['title'] ?? $row['encuesta'],150),'descripcion'=>Values::text($data['description'] ?? $row['descripcion'] ?? '',500,true),'inicio'=>$start,'fin'=>$end,'frecuencia'=>Catalog::choice($data['frequency'] ?? $row['frecuencia'],['unica','multiple']),'intervalo'=>Values::integer($data['interval'] ?? $row['intervalo'],1,1000),'unidad'=>Catalog::choice($data['unit'] ?? $row['unidad'],['horas','dias','semanas','meses'])]);
			if (isset($data['questions'])) {
				$this->repository->execute('DELETE o FROM encuestas_opciones o JOIN encuestas_preguntas q ON q.id=o.pregunta WHERE q.encuesta=?',[$row['id']]);
				$this->repository->execute('DELETE FROM encuestas_preguntas WHERE encuesta=?',[$row['id']]); $this->questions((int) $row['id'],$data['questions']);
			}
			$this->context->history('encuestas',$row,'editar_version','',null,['version'=>$row['numero_version']]);
		});
	}
	public function administer(string $uuid,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('encuestas',$uuid,'encuestas.administrar',null,true); $this->context->version($row,$data);
			if (!in_array($row['estado'],['publicada','archivada'],true)) throw new HttpError('Administra el periodo después de publicar la encuesta.',409);
			$start=Catalog::dateTime($data['start'] ?? $row['inicio'],true); $end=Catalog::dateTime($data['end'] ?? $row['fin'],true);
			if ($start!==null && $end!==null && $end<$start) throw new \InvalidArgumentException('Periodo inválido.');
			$changes=['inicio'=>$start,'fin'=>$end,'estado'=>Catalog::choice($data['state'] ?? $row['estado'],['publicada','archivada'])];
			$this->repository->updateRecord('encuestas',(int) $row['id'],$changes); $this->context->history('encuestas',$row,'administrar_periodo','',array_intersect_key($row,$changes),$changes);
		});
	}
	public function newVersion(string $uuid,array $data): array
	{
		return $this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('encuestas',$uuid,'encuestas.crear');
			$this->repository->raw('encuestas',(int) $row['serie'],true);
			$number=(int) $this->repository->one('SELECT MAX(numero_version) number FROM encuestas WHERE serie=?',[$row['serie']])['number']+1;
			$questions=array_map(static fn($question)=>['key'=>$question['key'],'title'=>$question['title'],'type'=>$question['type'],'required'=>$question['required'],'config'=>$question['config'],'options'=>array_map(static fn($option)=>['label'=>$option['label'],'value'=>$option['value']],$question['options'])],$this->definition($row));
			$created=$this->create(['process'=>$row['proceso'],'title'=>$data['title'] ?? $row['encuesta'],'description'=>$row['descripcion'] ?? '','frequency'=>$row['frecuencia'],'interval'=>$row['intervalo'],'unit'=>$row['unidad'],'start'=>$data['start'] ?? $row['inicio'],'end'=>$data['end'] ?? $row['fin'],'questions'=>$data['questions'] ?? $questions]);
			$this->repository->execute('UPDATE encuestas SET serie=?,numero_version=? WHERE uuid=?',[$row['serie'],$number,Values::binary($created['uuid'])]);
			$this->context->history('encuestas',$row,'nueva_version','',null,['version'=>$number]); return $created;
		});
	}
	public function publish(string $uuid,array $data): array
	{
		return $this->repository->transaction(function() use($uuid,$data) {
			$row=$this->repository->raw('encuestas',$uuid,true); $process=(int) $row['proceso'];
			$direct=$this->context->allowed('encuestas.publicar',$process);
			$row=$this->context->record('encuestas',$uuid,$direct ? 'encuestas.publicar' : 'encuestas.crear');
			$this->context->version($row,$data);
			if ($row['estado']!=='borrador') throw new HttpError('Sólo se publica o solicita aprobación de un borrador.',409);
			if (!$this->definition($row)) throw new \InvalidArgumentException('Agrega al menos una pregunta.');
			if ($direct) {
				$this->repository->updateRecord('encuestas',(int) $row['id'],['estado'=>'publicada','publicada_en'=>date('Y-m-d H:i:s')]);
				$this->context->history('encuestas',$row,'publicar','',null,['version'=>$row['numero_version']]); return ['state'=>'publicada'];
			}
			$approver=$this->context->approver('encuestas',$row,'encuestas.aprobar',$data['approver'] ?? null);
			$approval=(new ApprovalService($this->repository))->create((int) $row['organizacion'],$process,'encuestas',(int) $row['id'],$this->context->actor(),[$approver],['version'=>(int) $row['version']+1],['titulo'=>$row['encuesta'],'operacion'=>'Publicar encuesta','version'=>$row['numero_version']]);
			$id=$this->repository->one('SELECT id FROM aprobaciones WHERE uuid=?',[Values::binary($approval)])['id'];
			$this->repository->updateRecord('encuestas',(int) $row['id'],['estado'=>'validacion','aprobacion'=>$id]);
			return ['state'=>'validacion','approval'=>$approval];
		});
	}
	private function normalize(array $questions,mixed $answers,bool $draft): array
	{
		if (!is_array($answers) || count($answers)>100) throw new \InvalidArgumentException('Respuestas inválidas.');
		$known=array_column($questions,'key');
		foreach ($answers as $key=>$value) if (!in_array($key,$known,true)) throw new \InvalidArgumentException('La respuesta contiene una pregunta ajena a esta versión.');
		$clean=[];
		foreach ($questions as $question) {
			$key=$question['key']; $value=$answers[$key] ?? null; $when=$question['config']['when'] ?? null;
			if ($when!==null) {
				$previous=$clean[$when['question']] ?? null; $expected=$when['equals'];
				$expected=is_bool($expected) ? (int) $expected : $expected;
				$previous=is_bool($previous) ? (int) $previous : $previous;
				$visible=is_array($previous) ? in_array((string) $expected,$previous,true) : (is_scalar($previous) && (string) $previous===(string) $expected);
				if (!$visible) { if ($value!==null && $value!=='' && $value!==[]) throw new \InvalidArgumentException('No respondas preguntas que no aplican a esta respuesta.'); continue; }
			}
			if ($value===null || $value==='' || $value===[]) { if (!$draft && (int) $question['required']) throw new \InvalidArgumentException('Falta responder: '.$question['title']); continue; }
			$options=array_column($question['options'],'value');
			$value=match($question['type']) {
				'TEXTO'=>Values::text($value,500), 'TEXTO_LARGO'=>Values::text($value,4000),
				'FECHA'=>Values::date($value), 'SI_NO'=>(bool) Values::integer($value,0,1),
				'SELECCION_UNICA'=>Catalog::choice($value,$options),
				'SELECCION_MULTIPLE'=>$this->selection($value,$options),
				'NUMERO','ESCALA'=>$this->number($value,$question),
				default=>throw new \InvalidArgumentException('Tipo de pregunta no soportado.'),
			};
			$clean[$key]=$value;
		}
		return $clean;
	}
	private function selection(mixed $values,array $allowed): array
	{
		$values=Catalog::list($values,50); $clean=[]; foreach ($values as $value) $clean[]=Catalog::choice($value,$allowed); return array_values(array_unique($clean));
	}
	private function number(mixed $value,array $question): int|float
	{
		if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float) $value) || $value<($question['config']['min'] ?? -1000000000) || $value>($question['config']['max'] ?? 1000000000) || ($question['type']==='ESCALA' && floor((float) $value)!=(float) $value)) throw new \InvalidArgumentException('Valor numérico fuera del intervalo.');
		return $question['type']==='ESCALA' ? (int) $value : (float) $value;
	}
	public function answer(string $uuid,array $data): array
	{
		$survey=$this->context->record('encuestas',$uuid,'encuestas.aplicar');
		$subject=$this->context->person($data['person'] ?? null,'encuestas.aplicar',(int) $survey['proceso']);
		return (new \App\Services\Common\OperationLock($this->repository))->run('encuesta-respuesta:'.$survey['serie'].':'.$subject['id'],fn()=>$this->repository->transaction(function() use($uuid,$data) {
			$row=$this->context->record('encuestas',$uuid,'encuestas.aplicar'); $process=(int) $row['proceso'];
			$person=$this->context->person($data['person'] ?? null,'encuestas.aplicar',$process);
			// El lock común por serie/persona serializa capturistas; el recurso usa bloqueo compartido.
			$this->repository->one('SELECT id FROM personas WHERE id=? FOR UPDATE',[$person['id']]);
			$row=$this->repository->one('SELECT * FROM encuestas WHERE id=? LOCK IN SHARE MODE',[$row['id']]);
			if ($row['estado']!=='publicada' || ($row['inicio']!==null && $row['inicio']>date('Y-m-d H:i:s')) || ($row['fin']!==null && $row['fin']<date('Y-m-d H:i:s'))) throw new HttpError('La encuesta no está publicada dentro de su periodo de aplicación.',409);
			$mode=Catalog::choice($data['mode'] ?? 'asistida',['autocaptura','asistida','observacion']);
			if ($mode==='autocaptura' && (int) ($this->repository->one('SELECT persona FROM usuarios WHERE id=?',[$this->context->actor()])['persona'] ?? 0)!==(int) $person['id']) throw new HttpError('La autocaptura corresponde únicamente a tu persona vinculada.',403);
			$draft=Values::integer($data['draft'] ?? 0,0,1)===1; $questions=$this->definition($row); $answers=$this->normalize($questions,$data['answers'] ?? [],$draft);
			$application=null;
			if (($data['response'] ?? '')!=='') {
				$application=$this->repository->one('SELECT * FROM encuestas_aplicaciones WHERE uuid=? AND encuesta=? FOR UPDATE',[Values::binary($data['response']),$row['id']]) ?? throw new HttpError('Borrador no disponible.',404);
				if ($application['estado']!=='borrador' || (int) $application['persona_encuestada']!==(int) $person['id'] || (int) $application['capturado_por']!==$this->context->actor() || $application['modalidad']!==$mode) throw new HttpError('No puedes modificar ese borrador.',403);
			}
			if (!$draft) {
				$last=$this->repository->one("SELECT a.enviada_en FROM encuestas_aplicaciones a WHERE a.encuesta IN (SELECT id FROM encuestas WHERE serie=?) AND a.persona_encuestada=? AND a.estado='enviada' ORDER BY a.enviada_en DESC,a.id DESC LIMIT 1",[$row['serie'],$person['id']]);
				if ($last) {
					$interval=(int) $row['intervalo']; $unit=['horas'=>'hour','dias'=>'day','semanas'=>'week','meses'=>'month'][$row['unidad']];
				$next=$row['unidad']==='meses' ? new \DateTimeImmutable(TaskService::occurrence(substr($last['enviada_en'],0,10),$interval,['frequency'=>'mensual','every'=>1]).substr($last['enviada_en'],10)) : (new \DateTimeImmutable($last['enviada_en']))->modify('+'.$interval.' '.$unit);
				if ($row['frecuencia']==='unica' || $next>new \DateTimeImmutable()) throw new HttpError('Esta persona ya respondió; aún no corresponde una nueva aplicación.',409);
				}
			}
			$reference=$application ? Values::uuidText($application['uuid']) : Values::uuid();
			if (!$application) $id=$this->repository->insert('INSERT INTO encuestas_aplicaciones (uuid,encuesta,persona_proceso,aplicado_por,persona_encuestada,capturado_por,modalidad,estado,f_aplicacion,enviada_en) VALUES (?,?,?,?,?,?,?,?,NOW(),?)',[Values::binary($reference),$row['id'],$person['participation'],$this->context->actor(),$person['id'],$this->context->actor(),$mode,$draft ? 'borrador' : 'enviada',$draft ? null : date('Y-m-d H:i:s')]);
			else {
				$id=(int) $application['id'];
				$this->repository->execute('DELETE FROM encuestas_respuestas WHERE aplicacion=?',[$id]);
				$this->repository->execute('UPDATE encuestas_aplicaciones SET estado=?,enviada_en=?,revision=revision+1 WHERE id=?',[$draft ? 'borrador' : 'enviada',$draft ? null : date('Y-m-d H:i:s'),$id]);
			}
			$this->storeAnswers($id,$questions,$answers);
			$this->context->history('encuestas',$row,$draft ? 'guardar_borrador' : 'aplicar','',null,['modalidad'=>$mode,'preguntas'=>count($answers)]);
			return ['uuid'=>$reference,'state'=>$draft ? 'borrador' : 'enviada'];
		}));
	}
	private function storeAnswers(int $application,array $questions,array $answers): void
	{
		foreach ($questions as $question) if (array_key_exists($question['key'],$answers)) $this->repository->execute('INSERT INTO encuestas_respuestas (aplicacion,pregunta,respuesta) VALUES (?,?,?)',[$application,$question['id'],Values::json($answers[$question['key']])]);
	}
	private function storedAnswers(int $application): array
	{
		$answers=[];
		foreach ($this->repository->rows('SELECT q.codigo,t.codigo tipo,r.respuesta,COALESCE(o.valor,CAST(o.id AS CHAR)) opcion_valor FROM encuestas_respuestas r JOIN encuestas_preguntas q ON q.id=r.pregunta JOIN encuestas_preguntas_tipos t ON t.id=q.tipo LEFT JOIN encuestas_opciones o ON o.id=r.opcion WHERE r.aplicacion=? ORDER BY r.id',[$application]) as $answer) {
			$value=$answer['opcion_valor'] ?? (json_validate($answer['respuesta'] ?? '') ? json_decode($answer['respuesta'],true) : $answer['respuesta']);
			if ($answer['tipo']==='SELECCION_MULTIPLE') $answers[$answer['codigo']]=array_values(array_unique([...($answers[$answer['codigo']] ?? []),...(is_array($value) ? $value : [$value])]));
			else $answers[$answer['codigo']]=$value;
		}
		return $answers;
	}
	public function response(string $uuid,string $response): array
	{
		$raw=$this->repository->raw('encuestas',$uuid);
		$permission=$this->context->allowed('encuestas.corregir',(int) $raw['proceso']) ? 'encuestas.corregir' : 'encuestas.aplicar';
		$row=$this->context->record('encuestas',$uuid,$permission);
		$application=$this->repository->one('SELECT * FROM encuestas_aplicaciones WHERE uuid=? AND encuesta=?',[Values::binary($response),$row['id']]) ?? throw new HttpError('Respuesta no disponible.',404);
		$this->context->person((int) $application['persona_encuestada'],$permission,(int) $row['proceso'],false);
		if ((int) $application['capturado_por']!==$this->context->actor() && !$this->context->allowed('encuestas.corregir',(int) $row['proceso'])) throw new HttpError('Respuesta no disponible.',404);
		$person=$this->repository->one("SELECT uuid,CONCAT_WS(' ',nombre,paterno,materno) nombre FROM personas WHERE id=?",[$application['persona_encuestada']]);
		return ['uuid'=>$response,'person'=>Values::uuidText($person['uuid']),'person_name'=>$person['nombre'],'state'=>$application['estado'],'mode'=>$application['modalidad'],'revision'=>$application['revision'],'answers'=>$this->storedAnswers((int) $application['id'])];
	}
	public function applications(string $uuid,array $filters): array
	{
		$row=$this->repository->raw('encuestas',$uuid); $process=(int) $row['proceso'];
		$permission=$this->context->allowed('encuestas.corregir',$process) ? 'encuestas.corregir' : 'encuestas.aplicar';
		$this->context->record('encuestas',$uuid,$permission);
		[$scope,$params]=(new ScopeRepository($this->repository->connection()))->predicate($permission,$process);
		$where='a.encuesta=? AND ('.$scope.')'; $params=[$row['id'],...$params];
		if ($permission==='encuestas.aplicar') { $where.=' AND a.capturado_por=?'; $params[]=$this->context->actor(); }
		$result=$this->repository->page("SELECT a.uuid,a.estado,a.modalidad,a.f_aplicacion,a.revision,CONCAT_WS(' ',p.nombre,p.paterno,p.materno) persona_nombre,u.nombre capturista_nombre FROM encuestas_aplicaciones a JOIN personas p ON p.id=a.persona_encuestada JOIN usuarios u ON u.id=a.capturado_por WHERE $where ORDER BY a.id DESC",$params,$filters);
		foreach ($result['rows'] as &$application) $application['uuid']=Values::uuidText($application['uuid']); return $result;
	}
	public function correct(string $uuid,string $response,array $data): void
	{
		$this->repository->transaction(function() use($uuid,$response,$data) {
			$row=$this->context->record('encuestas',$uuid,'encuestas.corregir');
			$application=$this->repository->one("SELECT * FROM encuestas_aplicaciones WHERE uuid=? AND encuesta=? AND estado='enviada' FOR UPDATE",[Values::binary($response),$row['id']]) ?? throw new HttpError('Respuesta enviada no disponible.',404);
			$this->context->person((int) $application['persona_encuestada'],'encuestas.corregir',(int) $row['proceso'],false);
			if (isset($data['revision']) && Values::integer($data['revision'])!==(int) $application['revision']) throw new HttpError('La respuesta cambió; vuelve a consultarla.',409);
			$questions=$this->definition($row); $answers=$this->normalize($questions,$data['answers'] ?? [],false); $before=$this->storedAnswers((int) $application['id']); $reason=Values::text($data['reason'] ?? null,500);
			$this->repository->execute('INSERT INTO encuestas_correcciones (aplicacion,usuario,motivo,antes,despues,fecha) VALUES (?,?,?,?,?,NOW())',[$application['id'],$this->context->actor(),$reason,Values::json($before),Values::json($answers)]);
			$this->repository->execute('DELETE FROM encuestas_respuestas WHERE aplicacion=?',[$application['id']]); $this->storeAnswers((int) $application['id'],$questions,$answers);
			$this->repository->execute('UPDATE encuestas_aplicaciones SET revision=revision+1 WHERE id=?',[$application['id']]);
			// Los valores permanecen en el historial privado; la auditoría universal registra referencia y revisión.
			$this->context->history('encuestas',$row,'corregir_respuesta',$reason,['revision'=>$application['revision']],['revision'=>(int) $application['revision']+1,'respuesta'=>$response]);
		});
	}
	public function results(string $uuid,array $filters = []): array
	{
		$row=$this->context->record('encuestas',$uuid,'encuestas.ver_resultados'); $process=(int) $row['proceso'];
		[$scope,$params]=(new ScopeRepository($this->repository->connection()))->predicate('encuestas.ver_resultados',$process);
		$where="a.encuesta=? AND a.estado='enviada' AND ($scope)"; $params=[$row['id'],...$params];
		if (isset($filters['scope_permission'])) { [$extra,$args]=(new ScopeRepository($this->repository->connection()))->predicate($filters['scope_permission'],$process); $where.=" AND ($extra)"; $params=[...$params,...$args]; }
		if (($filters['from'] ?? '')!=='') { $where.=' AND a.enviada_en>=?'; $params[]=Values::date($filters['from']).' 00:00:00'; }
		if (($filters['to'] ?? '')!=='') { $where.=' AND a.enviada_en<=?'; $params[]=Values::date($filters['to']).' 23:59:59'; }
		$questions=$this->definition($row); $aggregates=[];
		foreach ($questions as $question) $aggregates[$question['id']]=['key'=>$question['key'],'title'=>$question['title'],'type'=>$question['type'],'answered'=>0,'values'=>[]];
		$last=0; $signature=hash_init('sha256'); $count=0; $modalities=[];
		do {
			$applications=$this->repository->rows("SELECT a.id,a.modalidad FROM encuestas_aplicaciones a JOIN personas p ON p.id=a.persona_encuestada WHERE $where AND a.id>? ORDER BY a.id LIMIT 250",[...$params,$last]);
			foreach ($applications as $application) {
				$last=(int) $application['id']; $count++; $modalities[$application['modalidad']]=($modalities[$application['modalidad']] ?? 0)+1; hash_update($signature,(string) $last."\n");
				$byQuestion=[];
				foreach ($this->repository->rows('SELECT r.pregunta,r.respuesta,COALESCE(o.valor,CAST(o.id AS CHAR)) valor,o.opcion FROM encuestas_respuestas r LEFT JOIN encuestas_opciones o ON o.id=r.opcion WHERE r.aplicacion=? ORDER BY r.id',[$last]) as $answer) {
					if (!isset($aggregates[$answer['pregunta']])) continue;
					$raw=$answer['respuesta']; $value=$answer['valor'] ?? (json_validate($raw ?? '') ? json_decode($raw,true) : $raw);
					$byQuestion[$answer['pregunta']][]=$value;
				}
				foreach ($byQuestion as $question=>$answers) {
					$aggregate=&$aggregates[$question]; $aggregate['answered']++;
					if (in_array($aggregate['type'],['SELECCION_UNICA','SELECCION_MULTIPLE','SI_NO','ESCALA'],true)) {
						$flat=[]; foreach ($answers as $answer) foreach (is_array($answer) ? $answer : [$answer] as $value) $flat[(string) (is_bool($value) ? (int) $value : $value)]=true;
						foreach ($flat as $value=>$_) $aggregate['values'][$value]=($aggregate['values'][$value] ?? 0)+1;
					}
					unset($aggregate);
				}
			}
		} while(count($applications)===250);
		foreach ($questions as $question) {
			$aggregate=&$aggregates[$question['id']]; $values=[]; $labels=array_column($question['options'],'label','value');
			foreach ($aggregate['values'] as $value=>$quantity) $values[]=['value'=>(string) $value,'label'=>$question['type']==='SI_NO' ? ($value ? 'Sí' : 'No') : ($labels[$value] ?? (string) $value),'count'=>$quantity,'percent'=>$aggregate['answered']>0 ? round(100*$quantity/$aggregate['answered'],2) : 0];
			$aggregate['values']=$values; unset($aggregate);
		}
		return ['survey'=>$row['encuesta'],'version'=>(int) $row['numero_version'],'responses'=>$count,'modalities'=>$modalities,'questions'=>array_values($aggregates),'signature'=>hash_final($signature)];
	}
}
