<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Http\Requests\UpdateAccountMappingsRequest;
use App\Modules\Accounting\Services\AccountMappingService;
use Illuminate\Http\JsonResponse;

class AccountMappingController extends Controller
{
    public function __construct(
        private readonly AccountMappingService $accountMappingService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->rows(), 'Account mappings fetched successfully.');
    }

    public function update(UpdateAccountMappingsRequest $request): JsonResponse
    {
        $this->accountMappingService->update($request->mappings());

        return ApiResponse::success($this->rows(), 'Account mappings updated successfully.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->accountMappingService->list() as $row) {
            $account = $row['account'];
            $rows[] = [
                'role' => $row['role']->value,
                'allowed_types' => array_map(fn (AccountType $t): string => $t->value, $row['role']->allowedTypes()),
                'is_controlled' => $row['role']->isControlled(),
                'account_id' => $account?->id,
                'account_code' => $account?->code,
                'account_name' => $account?->name,
            ];
        }

        return $rows;
    }
}
