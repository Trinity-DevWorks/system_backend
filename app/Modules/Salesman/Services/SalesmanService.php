<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Services;

use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Salesman\DTOs\SalesmanData;
use App\Modules\Salesman\Models\Salesman;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesmanService
{
    public function __construct(
        private readonly BranchService $branchService,
        private readonly BranchContextService $branchContext,
    ) {}

    /**
     * @return Collection<int, Salesman>
     */
    public function list(): Collection
    {
        $query = Salesman::query()
            ->with(['branch:id,name', 'warehouse:id,name'])
            ->orderBy('full_name');

        $activeBranchId = $this->branchContext->resolveActiveBranchId();
        if ($activeBranchId !== null) {
            $query->where('branch_id', $activeBranchId);
        }

        return $query->get();
    }

    public function assertVisible(Salesman $salesman): void
    {
        $activeBranchId = $this->branchContext->resolveActiveBranchId();
        if ($activeBranchId === null) {
            return;
        }

        if ((int) $salesman->branch_id !== $activeBranchId) {
            abort(403, 'This salesman is not available in the active branch.', [
                'X-Error-Code' => 'SALESMAN_BRANCH_FORBIDDEN',
            ]);
        }
    }

    public function create(SalesmanData $data): Salesman
    {
        return DB::transaction(function () use ($data): Salesman {
            $payload = $data->toArray();
            $payload['branch_id'] = $payload['branch_id'] ?? $this->branchContext->resolveActiveBranchId()
                ?? $this->branchService->defaultBranchId();

            $this->assertWritableBranch((int) $payload['branch_id']);

            $salesman = Salesman::query()->create($payload);
            $salesman->load(['branch:id,name', 'warehouse:id,name', 'user:id,name']);

            return $salesman;
        });
    }

    public function update(Salesman $salesman, SalesmanData $data): Salesman
    {
        return DB::transaction(function () use ($salesman, $data): Salesman {
            $this->assertVisible($salesman);

            $payload = $data->toArray();
            if ($payload['branch_id'] === null) {
                abort(422, 'Each salesman must be assigned to a branch.', [
                    'X-Error-Code' => 'SALESMAN_BRANCH_REQUIRED',
                ]);
            }

            $this->assertWritableBranch((int) $payload['branch_id']);

            $salesman->update($payload);
            $salesman->load(['branch:id,name', 'warehouse:id,name', 'user:id,name']);

            return $salesman->refresh();
        });
    }

    public function delete(Salesman $salesman): void
    {
        $this->assertVisible($salesman);
        $salesman->delete();
    }

    private function assertWritableBranch(int $branchId): void
    {
        $activeBranchId = $this->branchContext->resolveActiveBranchId();
        if ($activeBranchId !== null && $branchId !== $activeBranchId) {
            abort(422, 'Salesman branch must match the active branch.', [
                'X-Error-Code' => 'SALESMAN_BRANCH_MISMATCH',
            ]);
        }

        if (! $this->branchContext->canAccessBranch($branchId)) {
            abort(403, 'You cannot assign this salesman to a branch you cannot access.', [
                'X-Error-Code' => 'BRANCH_ACCESS_DENIED',
            ]);
        }
    }
}
