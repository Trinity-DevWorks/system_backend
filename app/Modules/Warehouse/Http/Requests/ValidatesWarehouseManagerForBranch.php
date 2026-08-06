<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http\Requests;

use App\Models\User;
use App\Modules\Warehouse\Enums\WarehouseType;
use Illuminate\Validation\Validator;

trait ValidatesWarehouseManagerForBranch
{
    protected function validateManagerForBranch(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $managerId = $this->input('manager_id');
        if ($managerId === null || $managerId === '') {
            return;
        }

        $type = WarehouseType::tryFrom((string) $this->input('type', ''));
        $branchId = $this->input('branch_id');

        // Only branch warehouses require the manager to belong to that branch.
        if ($type === null || ! $type->requiresBranch()) {
            return;
        }

        if ($branchId === null || $branchId === '') {
            return;
        }

        $assigned = User::query()
            ->whereKey((string) $managerId)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', (int) $branchId))
            ->exists();

        if (! $assigned) {
            $validator->errors()->add(
                'manager_id',
                'The warehouse manager must be assigned to the selected branch.'
            );
        }
    }
}
