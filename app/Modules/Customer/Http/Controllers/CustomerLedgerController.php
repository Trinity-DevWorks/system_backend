<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Customer\DTOs\CustomerLedgerEntryResponseData;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLedgerController extends Controller
{
    public function __construct(
        private readonly CustomerLedgerService $ledgerService
    ) {}

    public function index(Request $request, Customer $customer): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = $this->ledgerService->paginateForCustomer($customer, $perPage);
        $paginator->through(
            fn ($entry) => CustomerLedgerEntryResponseData::fromModel($entry)->toArray()
        );

        return ApiResponse::success(
            $paginator->toArray(),
            'Ledger entries fetched successfully.'
        );
    }

    public function balance(Customer $customer): JsonResponse
    {
        $customer->load(['balances.currency']);
        $currencies = [];

        foreach ($customer->balances as $cb) {
            $currencyId = (int) $cb->currency_id;
            $balStr = $this->ledgerService->balanceInCurrency($customer, $currencyId);
            $balance = (float) $balStr;
            $creditLimit = (float) $cb->credit_limit;
            $outstanding = max(0.0, $balance);
            $remainingCredit = max(0.0, $creditLimit - $outstanding);

            $currencies[] = [
                'currency_id' => $currencyId,
                'currency_code' => $cb->currency?->code,
                'opening_balance' => (string) $cb->opening_balance,
                'opening_date' => $cb->opening_date?->toDateString(),
                'credit_limit' => (string) $cb->credit_limit,
                'balance' => $balStr,
                'outstanding' => number_format($outstanding, 4, '.', ''),
                'remaining_credit' => number_format($remainingCredit, 4, '.', ''),
            ];
        }

        return ApiResponse::success(
            [
                'currencies' => $currencies,
            ],
            'Customer balance retrieved successfully.'
        );
    }
}
