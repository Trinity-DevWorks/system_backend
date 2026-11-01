<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Treasury\DTOs\TreasuryResponseData;
use App\Modules\Treasury\Http\Requests\SaveCashBankAccountRequest;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Services\CashBankAccountService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBankAccountController extends Controller
{
    use ResolvesListSection;

    public function __construct(private readonly CashBankAccountService $service) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            function (): array {
                // Balances ride along so transfer/voucher forms can show what is available.
                $accounts = $this->service->list();
                $balances = $this->service->balances($accounts->map(fn (CashBankAccount $a): int => (int) $a->account_id)->values()->all());

                return $accounts->map(fn (CashBankAccount $a): array => TreasuryResponseData::account($a, $balances[(int) $a->account_id] ?? null))->values()->all();
            },
            'Cash and bank accounts fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        $page = $this->service->paginate(ListPagination::search($request), ListPagination::perPage($request));
        $balances = $this->service->balances($page->getCollection()->map(fn (CashBankAccount $a): int => (int) $a->account_id)->values()->all());

        return ListPagination::json(
            $page,
            fn (CashBankAccount $a): array => TreasuryResponseData::account($a, $balances[(int) $a->account_id] ?? null),
            'Cash and bank accounts fetched successfully.'
        );
    }

    public function store(SaveCashBankAccountRequest $request): JsonResponse
    {
        $account = $this->service->create($request->validated())->load(['currency:id,code', 'account:id,code,name', 'branch:id,name']);

        return ApiResponse::created(TreasuryResponseData::account($account), 'Cash/bank account created successfully.');
    }

    public function show(CashBankAccount $cash_bank_account): JsonResponse
    {
        $cash_bank_account->load(['currency:id,code', 'account:id,code,name', 'branch:id,name']);
        $balance = $this->service->balances([(int) $cash_bank_account->account_id])[(int) $cash_bank_account->account_id] ?? null;

        return ApiResponse::success(TreasuryResponseData::account($cash_bank_account, $balance), 'Cash/bank account fetched successfully.');
    }

    public function update(SaveCashBankAccountRequest $request, CashBankAccount $cash_bank_account): JsonResponse
    {
        $account = $this->service->update($cash_bank_account, $request->validated())->load(['currency:id,code', 'account:id,code,name', 'branch:id,name']);

        return ApiResponse::success(TreasuryResponseData::account($account), 'Cash/bank account updated successfully.');
    }

    public function destroy(CashBankAccount $cash_bank_account): JsonResponse
    {
        $this->service->delete($cash_bank_account);

        return ApiResponse::success(null, 'Cash/bank account deleted successfully.');
    }
}
