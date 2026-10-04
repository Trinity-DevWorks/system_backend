<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Supplier\DTOs\SupplierLedgerEntryResponseData;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierLedgerEntry;
use App\Modules\Supplier\Services\SupplierLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierLedgerController extends Controller
{
    public function __construct(
        private readonly SupplierLedgerService $ledgerService
    ) {}

    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $statement = $this->ledgerService->statement(
            $supplier,
            isset($validated['currency_id']) ? (int) $validated['currency_id'] : null,
            isset($validated['date_from']) ? (string) $validated['date_from'] : null,
            isset($validated['date_to']) ? (string) $validated['date_to'] : null,
            (int) ($validated['per_page'] ?? 25),
        );

        $statement['paginator']->through(function (SupplierLedgerEntry $entry) use ($statement): array {
            $type = $entry->reference_type instanceof \BackedEnum ? $entry->reference_type->value : (string) $entry->reference_type;
            $key = $entry->reference_id !== null ? $type.'|'.$entry->reference_id : null;

            return SupplierLedgerEntryResponseData::fromModel(
                $entry,
                $key !== null ? ($statement['document_numbers'][$key] ?? null) : null,
                $statement['running_balances'][(int) $entry->id] ?? null,
                isset($statement['reversed'][(int) $entry->id]),
            )->toArray();
        });

        $payload = $statement['paginator']->toArray();
        $payload['summary'] = $statement['summary'];
        $payload['summaries'] = $statement['summaries'];

        return ApiResponse::success(
            $payload,
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
                'outstanding' => PriceMath::normalize($outstanding),
                'remaining_credit' => PriceMath::normalize($remainingCredit),
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
