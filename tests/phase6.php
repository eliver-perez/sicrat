<?php

// Sólo se incluye desde la suite completa en su base desechable.
use App\Services\Resources\Amount;
use App\Services\Common\Values;

$phase6Start=$checks;
function phase6Api(string $path,string $method = 'GET',?array $data = null,string $client = 'admin',int $status = 200): array {
	$response=phase3Api(str_starts_with($path,'/api/') ? $path : '/api/resources/'.$path,$method,$data,$client);
	verify($response['status']===$status || ($status===200 && $response['status']===201),$method.' '.$path.' esperaba '.$status.': '.$response['body']); return $response['json']['data'] ?? [];
}
phase6Api('/api/auth/election','POST',['process'=>$phase5ElectionUuid]);
foreach(['finanzas','inventario'] as $module) phase6Api('/api/organizations/'.$organizationIds[0].'/modules/'.$module,'PUT',['authorized'=>1,'selected'=>1]);
$phase6ExpenseCategory=phase6Api('categorias','POST',['nombre'=>'Operación financiera','tipo'=>'gasto'])['uuid'];
$phase6IncomeCategory=phase6Api('categorias','POST',['nombre'=>'Aportaciones monetarias','tipo'=>'ingreso'])['uuid'];
$phase6Account=phase6Api('cuentas','POST',['nombre'=>'Fondo operativo'])['uuid'];
$phase6Method=phase6Api('metodos','POST',['nombre'=>'Transferencia operativa','tipo'=>'transferencia'])['uuid'];
$phase6Budget=phase6Api('presupuestos','POST',['nombre'=>'Presupuesto de operación','monto'=>'100000.00','inicio'=>date('Y-01-01'),'fin'=>date('Y-12-31')])['uuid'];
$phase6Expense=phase6Api('gastos','POST',['categoria'=>$phase6ExpenseCategory,'beneficiario'=>$phase5People[0],'concepto'=>'Material operativo','monto'=>'35000.00','presupuesto'=>$phase6Budget,'autorizar'=>1])['uuid'];
$balance=phase6Api('presupuestos/'.$phase6Budget); verify($balance['comprometido']==='35000.00' && $balance['disponible']==='65000.00','Presupuesto disponible descuenta compromiso una sola vez.');
$phase6Income=phase6Api('ingresos','POST',['categoria'=>$phase6IncomeCategory,'concepto'=>'Ingreso recibido','fuente'=>'Aportación administrativa','monto'=>'100000.00','estado'=>'recibido','cuenta'=>$phase6Account,'metodo'=>$phase6Method])['uuid'];
$phase6Payment=phase6Api('gastos/'.$phase6Expense.'/pay','POST',['monto'=>'20000.00','cuenta'=>$phase6Account,'metodo'=>$phase6Method,'idempotencia'=>'pago-inicial'])['uuid'];
$balance=phase6Api('presupuestos/'.$phase6Budget); verify($balance['pagado']==='20000.00' && $balance['disponible']==='65000.00','Los pagos son parte del compromiso; no reducen otra vez el disponible.');
$phase6InventoryCategory=phase6Api('categorias_inventario','POST',['nombre'=>'Material de operación'])['uuid'];
$phase6Item=phase6Api('articulos','POST',['nombre'=>'Carpeta administrativa','codigo'=>'CAR-001','categoria'=>$phase6InventoryCategory,'unidad'=>'Pieza','minimo'=>'5.000'])['uuid'];
$phase6Warehouse=phase6Api('almacenes','POST',['nombre'=>'Almacén principal'])['uuid'];
$phase6Movement=phase6Api('movimientos','POST',['tipo'=>'entrada','articulo'=>$phase6Item,'almacen'=>$phase6Warehouse,'cantidad'=>'10.000','costo_unitario'=>'50.00','motivo'=>'Existencia recibida','idempotencia'=>'entrada-inicial'])['uuid'];
$stocks=phase6Api('stocks'); verify($stocks['rows'][0]['cantidad']==='10.000' && $stocks['rows'][0]['valor']==='500.00','Existencias y valoración se derivan de movimientos válidos.');
require __DIR__.'/phase6-coverage.php';
echo 'Fase 6: '.($checks-$phase6Start)." comprobaciones adicionales correctas.\n";
