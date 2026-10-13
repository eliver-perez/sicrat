<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Repositories\CommonRepository;

final class Factory
{
	public static function service(CommonRepository $repository,string $entity): BaseService
	{
		Catalog::entity($entity);
		return match($entity) {
			'presupuestos'=>new BudgetService($repository),'gastos'=>new ExpenseService($repository),'anticipos'=>new AdvanceService($repository),'reembolsos'=>new ReimbursementService($repository),'ingresos','transferencias'=>new TreasuryService($repository,$entity),'aportaciones'=>new ContributionService($repository),'politicas'=>new PolicyService($repository),'movimientos'=>new InventoryService($repository),
			'categorias','metodos','cuentas','categorias_inventario','origenes','articulos','almacenes'=>new CatalogService($repository,$entity),default=>new BaseService($repository,$entity),
		};
	}
}
