<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportLedgerStatementRequest;
use App\Http\Responses\ApiResponse;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Customer\DTOs\CustomerLedgerEntryResponseData;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Support\Export\LedgerStatementDocument;
use App\Support\Export\TabularExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CustomerLedgerController extends Controller
{
    public function __construct(
        private readonly CustomerLedgerService $ledgerService,
        private readonly TabularExportService $tabularExportService,
    ) {}

    public function index(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $statement = $this->ledgerService->statement(
            $customer,
            isset($validated['currency_id']) ? (int) $validated['currency_id'] : null,
            isset($validated['date_from']) ? (string) $validated['date_from'] : null,
            isset($validated['date_to']) ? (string) $validated['date_to'] : null,
            (int) ($validated['per_page'] ?? 25),
        );

        $statement['paginator']->through(function (CustomerLedgerEntry $entry) use ($statement): array {
            $type = $entry->reference_type instanceof \BackedEnum ? $entry->reference_type->value : (string) $entry->reference_type;
            $key = $entry->reference_id !== null ? $type.'|'.$entry->reference_id : null;

            return CustomerLedgerEntryResponseData::fromModel(
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

    public function export(ExportLedgerStatementRequest $request, Customer $customer): Response
    {
        $validated = $request->validated();
        app()->setLocale((string) ($validated['locale'] ?? 'en'));

        $currencyId = isset($validated['currency_id']) ? (int) $validated['currency_id'] : null;
        $dateFrom = isset($validated['date_from']) ? (string) $validated['date_from'] : null;
        $dateTo = isset($validated['date_to']) ? (string) $validated['date_to'] : null;
        $statement = $this->ledgerService->fullStatement($customer, $currencyId, $dateFrom, $dateTo);

        $entries = [];
        foreach ($statement['entries'] as $entry) {
            $type = $entry->reference_type instanceof \BackedEnum ? $entry->reference_type->value : (string) $entry->reference_type;
            $key = $entry->reference_id !== null ? $type.'|'.$entry->reference_id : null;
            $entries[] = CustomerLedgerEntryResponseData::fromModel(
                $entry,
                $key !== null ? ($statement['document_numbers'][$key] ?? null) : null,
                $statement['running_balances'][(int) $entry->id] ?? null,
                isset($statement['reversed'][(int) $entry->id]),
            )->toArray();
        }

        $currencyLabel = null;
        if ($currencyId !== null) {
            foreach ($statement['summaries'] as $summary) {
                if ((int) $summary['currency_id'] === $currencyId) {
                    $currencyLabel = $summary['currency_code'];

                    break;
                }
            }
        }

        $document = LedgerStatementDocument::make(
            partyKind: 'customer',
            partyName: (string) $customer->name,
            partyCode: $customer->customer_code !== null ? (string) $customer->customer_code : null,
            currencyLabel: $currencyLabel !== null ? (string) $currencyLabel : null,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            showCurrency: $currencyId === null,
            entries: $entries,
            summaries: $statement['summaries'],
        );

        return $this->tabularExportService->download($document, (string) $validated['format']);
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
                'outstanding' => PriceMath::normalize($outstanding),
                'remaining_credit' => PriceMath::normalize($remainingCredit),
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
