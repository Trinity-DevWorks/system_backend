<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Supplier\DTOs\SupplierLedgerEntryResponseData;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierLedgerController extends Controller
{
    public function __construct(
        private readonly SupplierLedgerService $ledgerService
    ) {}

    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = $this->ledgerService->paginateForSupplier($supplier, $perPage);
        $paginator->through(
            fn ($entry) => SupplierLedgerEntryResponseData::fromModel($entry)->toArray()
        );

        return ApiResponse::success(
            $paginator->toArray(),
            'Ledger entries fetched successfully.'
        );
    }

    public function balance(Supplier $supplier): JsonResponse
    {
        $supplier->load(['balances.currency']);
        $currencies = [];

        foreach ($supplier->balances as $sb) {
            $currencyId = (int) $sb->currency_id;
            $balStr = $this->ledgerService->balanceInCurrency($supplier, $currencyId);
            $balance = (float) $balStr;
            $creditLimit = (float) $sb->credit_limit;
            $outstanding = max(0.0, $balance);
            $remainingCredit = max(0.0, $creditLimit - $outstanding);

            $currencies[] = [
                'currency_id' => $currencyId,
                'currency_code' => $sb->currency?->code,
                'opening_balance' => (string) $sb->opening_balance,
                'opening_date' => $sb->opening_date?->toDateString(),
                'credit_limit' => (string) $sb->credit_limit,
                'balance' => $balStr,
                'outstanding' => number_format($outstanding, 4, '.', ''),
                'remaining_credit' => number_format($remainingCredit, 4, '.', ''),
            ];
        }

        return ApiResponse::success(
            [
                'currencies' => $currencies,
            ],
            'Supplier balance retrieved successfully.'
        );
    }
}
