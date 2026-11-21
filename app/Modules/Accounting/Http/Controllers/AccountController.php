<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\DTOs\AccountData;
use App\Modules\Accounting\DTOs\AccountResponseData;
use App\Modules\Accounting\Http\Requests\StoreAccountRequest;
use App\Modules\Accounting\Http\Requests\UpdateAccountRequest;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly AccountMappingService $accountMappingService,
        private readonly AccountBalanceService $accountBalanceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tree = $request->boolean('tree');
        $accounts = $this->chartOfAccountsService->list($tree ? [] : [
            'search' => $request->query('search'),
            'type' => $request->query('type'),
            'active' => $request->has('active') ? $request->boolean('active') : null,
            'postable' => $request->boolean('postable'),
            'prefix' => $request->query('prefix'),
        ]);

        $roles = $this->accountMappingService->rolesByAccount();
        $sums = $this->accountBalanceService->rollUp(
            $tree ? $accounts : $this->chartOfAccountsService->list(),
            $this->accountBalanceService->totals(),
        );

        $rows = $accounts->map(fn (Account $account): array => AccountResponseData::fromModel(
            $account,
            $roles[$account->id] ?? [],
            $sums[$account->id] ?? null,
        )->toArray())->values()->all();

        return ApiResponse::success(
            $tree ? $this->nest($rows) : $rows,
            'Accounts fetched successfully.'
        );
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->chartOfAccountsService->create(AccountData::fromStoreRequest($request));

        return ApiResponse::created(
            AccountResponseData::fromModel($account)->toArray(),
            'Account created successfully.'
        );
    }

    public function show(Account $account): JsonResponse
    {
        $account->loadMissing('currency:id,code,name');
        $roles = $this->accountMappingService->rolesByAccount();
        $sums = $this->accountBalanceService->rollUp(
            $this->chartOfAccountsService->list(),
            $this->accountBalanceService->totals(),
        );

        $data = AccountResponseData::fromModel($account, $roles[$account->id] ?? [], $sums[$account->id] ?? null)->toArray();
        $data['has_lines'] = $this->chartOfAccountsService->hasLines($account);
        $data['has_children'] = $account->children()->exists();

        return ApiResponse::success($data, 'Account fetched successfully.');
    }

    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $updated = $this->chartOfAccountsService->update($account, AccountData::fromUpdateRequest($request));
        $roles = $this->accountMappingService->rolesByAccount();

        return ApiResponse::success(
            AccountResponseData::fromModel($updated, $roles[$updated->id] ?? [])->toArray(),
            'Account updated successfully.'
        );
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->chartOfAccountsService->delete($account);

        return ApiResponse::success(null, 'Account deleted successfully.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function nest(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[$row['parent_id'] ?? 0][] = $row;
        }

        $build = function (int $parentId) use (&$build, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $row) {
                $children = $build((int) $row['id']);
                if ($children !== []) {
                    $row['children'] = $children;
                }
                $nodes[] = $row;
            }

            return $nodes;
        };

        return $build(0);
    }
}
