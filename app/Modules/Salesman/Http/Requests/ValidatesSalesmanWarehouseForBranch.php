<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Http\Requests;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Validation\Validator;

trait ValidatesSalesmanWarehouseForBranch
{
    protected function validateWarehouseForBranch(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $warehouseId = $this->input('warehouse_id');
        if ($warehouseId === null || $warehouseId === '') {
            return;
        }

        $branchId = $this->input('branch_id');
        if ($branchId === null || $branchId === '') {
            $branchId = app(BranchService::class)->defaultBranchId();
        }

        $warehouse = Warehouse::query()->find((int) $warehouseId);
        if ($warehouse === null) {
            return;
        }

        $allowed = $warehouse->branch_id === null
            || (int) $warehouse->branch_id === (int) $branchId;

        if (! $allowed) {
            $validator->errors()->add(
                'warehouse_id',
                'The selected warehouse must belong to the salesman branch or be a shared warehouse (no branch).'
            );
        }
    }
}
