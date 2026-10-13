<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\Values;

class TreasuryService extends BaseService
{
	public function __construct(CommonRepository $repository,string $entity = 'ingresos') {
		parent::__construct($repository,$entity);
	}

	public function cash(array $row,string $type,int $record,int $account,int $sign,int $amount,string $date): void
	{
		if($amount<1 || !in_array($sign,[-1,1],true)) throw new \InvalidArgumentException('Movimiento de caja inválido.');
		if($sign===-1 && $amount>$this->accountBalance($account)) throw new HttpError('La cuenta no tiene fondos recibidos suficientes para esta salida.',409);
		$this->repository->execute('INSERT INTO finanzas_caja (organizacion,proceso,cuenta,tipo,registro,signo,monto,fecha,usuario) VALUES (?,?,?,?,?,?,?,?,?)',[$row['organizacion'],$row['proceso'],$account,$type,$record,$sign,Amount::format($amount),$date,$this->context->actor()]);
	}

	public function accountBalance(int $id): int {
		return Amount::signed($this->repository->one('SELECT COALESCE(SUM(monto*signo),0) monto FROM finanzas_caja WHERE cuenta=?',[$id])['monto']);
	}

	private function contributionState(int $income,string $state,string $reason): void
	{
		foreach($this->repository->rows('SELECT * FROM finanzas_aportaciones WHERE ingreso=?',[$income]) as $row) {
			if($row['estado']===$state) continue;
			$this->repository->update('aportaciones',(int)$row['id'],['estado'=>$state]);
			$this->context->history('aportaciones',$row,'estado_ingreso',$reason,['estado'=>$row['estado']],['estado'=>$state]);
		}
	}

	public function create(array $data): array
	{
		if($this->entity==='transferencias') return $this->transfer($data);
		$ctx=$this->creation($data);
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$category=$this->reference('categorias',$data['categoria'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona categoría de ingreso.');
			if($this->repository->raw('categorias',$category)['tipo']!=='ingreso') throw new \InvalidArgumentException('Categoría incompatible.');
			$state=$data['estado'] ?? 'prometido';
			if(!in_array($state,['prometido','recibido'],true)) throw new \InvalidArgumentException('Estado de ingreso inválido.');
			$account=$this->reference('cuentas',$data['cuenta'] ?? null,$ctx['organizacion']);
			$method=$this->reference('metodos',$data['metodo'] ?? null,$ctx['organizacion']);
			if($state==='recibido' && ($account===null || $method===null)) throw new \InvalidArgumentException('Selecciona cuenta y método de recepción.');
			$amount=Amount::parse($data['monto'] ?? null);
			$row=$this->repository->create('ingresos',[...$this->document($ctx,$state),'categoria'=>$category,'concepto'=>Values::text($data['concepto'] ?? null,180),'fuente'=>Values::text($data['fuente'] ?? null,180),'monto'=>Amount::format($amount),'cuenta'=>$account,'metodo'=>$method,'fecha_operacion'=>Values::date($data['fecha_operacion'] ?? date('Y-m-d')),'notas'=>Values::text($data['notas'] ?? '',4000,true)]);
			if($state==='recibido') $this->cash($row,'ingreso',(int) $row['id'],$account,1,$amount,$row['fecha_operacion']);
			$this->context->history('ingresos',$row,'crear','',null,['estado'=>$state,'monto'=>$row['monto']]);
			return Context::present($row);
		});
	}

	public function receive(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('ingresos',$uuid,'finanzas.ingresos.crear',true);
			if($row['estado']==='recibido') return Context::present($row);
			if($row['estado']!=='prometido') throw new HttpError('El ingreso no admite recepción.',409);
			$account=$this->reference('cuentas',$data['cuenta'] ?? null,(int) $row['organizacion']) ?? throw new \InvalidArgumentException('Selecciona cuenta.');
			$method=$this->reference('metodos',$data['metodo'] ?? null,(int) $row['organizacion']) ?? throw new \InvalidArgumentException('Selecciona método.');
			$date=Values::date($data['fecha_operacion'] ?? date('Y-m-d'));
			$this->repository->update('ingresos',(int) $row['id'],['estado'=>'recibido','cuenta'=>$account,'metodo'=>$method,'fecha_operacion'=>$date]);
			$this->cash($row,'ingreso',(int) $row['id'],$account,1,Amount::parse($row['monto']),$date);
			$this->context->history('ingresos',$row,'recibir','',null,['monto'=>$row['monto']]);
			$this->contributionState((int)$row['id'],'recibido','Recepción del ingreso asociado');
			return $this->detail($uuid);
		});
	}

	public function cancel(string $uuid,array $data): array
	{
		return $this->repository->write($this->context->organization(),function() use($uuid,$data) {
			$row=$this->context->record('ingresos',$uuid,'finanzas.ingresos.crear',true);
			$reason=Values::text($data['motivo'] ?? null,500);
			if($row['estado']==='cancelado') return Context::present($row);
			if($row['estado']==='recibido') {
				$amount=Amount::parse($row['monto']);
				if($this->accountBalance((int) $row['cuenta'])<$amount) throw new HttpError('El ingreso tiene fondos utilizados. Registra una corrección sin borrar su historia.',409);
				$this->cash($row,'ingreso_reversa',(int) $row['id'],(int) $row['cuenta'],-1,$amount,Values::date($data['fecha_operacion'] ?? date('Y-m-d')));
			}
			$this->repository->update('ingresos',(int) $row['id'],['estado'=>'cancelado']);
			$this->context->history('ingresos',$row,'cancelar',$reason,['estado'=>$row['estado']],['estado'=>'cancelado']);
			$this->contributionState((int)$row['id'],'cancelado',$reason);
			return $this->detail($uuid);
		});
	}

	public function transfer(array $data): array
	{
		$ctx=$this->creation($data,'finanzas.administrar');
		return $this->repository->write($ctx['organizacion'],function() use($ctx,$data) {
			$origin=$this->reference('cuentas',$data['origen'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona origen.');
			$dest=$this->reference('cuentas',$data['destino'] ?? null,$ctx['organizacion']) ?? throw new \InvalidArgumentException('Selecciona destino.');
			$amount=Amount::parse($data['monto'] ?? null);
			if($origin===$dest) throw new \InvalidArgumentException('Selecciona cuentas distintas.');
			$key=Values::text($data['idempotencia'] ?? null,80);
			$existing=$this->repository->one('SELECT * FROM finanzas_transferencias WHERE organizacion=? AND idempotencia=?',[$ctx['organizacion'],$key]);
			if($existing) {
				if((int) $existing['origen']!==$origin || (int) $existing['destino']!==$dest || $existing['monto']!==Amount::format($amount)) throw new HttpError('La referencia corresponde a otra transferencia.',409);
				return Context::present($existing);
			}
			if($amount>$this->accountBalance($origin)) throw new HttpError('La cuenta de origen no tiene saldo suficiente para la transferencia.',409);
			$reason=Values::text($data['motivo'] ?? null,500);
			$service=new self($this->repository,'transferencias');
			$row=$this->repository->create('transferencias',[...$service->document($ctx,'aplicado'),'origen'=>$origin,'destino'=>$dest,'monto'=>Amount::format($amount),'motivo'=>$reason,'idempotencia'=>$key]);
			foreach([[$origin,-1],[$dest,1]] as [$account,$sign]) $this->cash($row,'transferencia',(int) $row['id'],$account,$sign,$amount,date('Y-m-d'));
			$this->context->history('transferencias',$row,'transferir',$reason,null,['monto'=>$row['monto']]);
			return Context::present($row);
		});
	}

	public function flow(array $filters = []): array
	{
		$process=$this->context->process($filters['proceso'] ?? null);
		$ctx=$this->context->require('ingresos','finanzas.ver',$process ?? Auth::processId());
		if(!$this->context->broad($ctx,'finanzas.ver',$process===null)) throw new HttpError('El flujo de caja agregado requiere alcance de elección u organización.',403);
		$where='organizacion=?';
		$params=[$ctx['organization']];
		if($process!==null) {
			$where.=' AND proceso=?';
			$params[]=$process;
		}
		foreach(['desde','hasta'] as $key) if(!empty($filters[$key])) {
			$where.=' AND fecha'.($key==='desde' ? '>=' : '<=').'?';
			$params[]=Values::date($filters[$key]);
		}
		$income=0;
		$payments=0;
		$advance=0;
		$returns=0;
		foreach($this->repository->rows("SELECT tipo,signo,COALESCE(SUM(monto),0) monto FROM finanzas_caja WHERE $where AND tipo<>'transferencia' GROUP BY tipo,signo",$params) as $entry) {
			$value=Amount::parse($entry['monto'],2,true)*(int) $entry['signo'];
			if(str_starts_with($entry['tipo'],'ingreso')) $income=Amount::add($income,$value);
			elseif(str_starts_with($entry['tipo'],'pago')) $payments=Amount::add($payments,-$value);
			elseif($entry['tipo']==='anticipo') $advance=Amount::add($advance,-$value);
			else $returns=Amount::add($returns,$value);
		}
		return ['ingresos_recibidos'=>Amount::format($income),'pagos_reales'=>Amount::format($payments),'anticipos_entregados'=>Amount::format($advance),'devoluciones'=>Amount::format($returns),'flujo'=>Amount::format($income-$payments-$advance+$returns),'moneda'=>'MXN'];
	}
}
