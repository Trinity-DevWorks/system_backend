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
        $balanceStr = $this->ledgerService->balance($supplier);
        $balance = (float) $balanceStr;
        $creditLimit = (float) $supplier->credit_limit;
        /** Payable to supplier (AP); headroom under credit_limit */
        $outstanding = max(0.0, $balance);
        $remainingCredit = max(0.0, $creditLimit - $outstanding);

        return ApiResponse::success(
            [
                'balance' => $balanceStr,
                'credit_limit' => (string) $supplier->credit_limit,
                'outstanding' => number_format($outstanding, 4, '.', ''),
                'remaining_credit' => number_format($remainingCredit, 4, '.', ''),
            ],
            'Supplier balance retrieved successfully.'
        );
    }
}
