<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Modules\Customer\DTOs\CustomerLedgerEntryResponseData;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Supplier\DTOs\SupplierLedgerEntryResponseData;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;

/**
 * Builds a customer / supplier account statement (the ledger export), used by
 * the Excel / PDF download and by "Email statement".
 * Labels follow app()->getLocale(), so set the locale first.
 */
final class LedgerStatementBuilder
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedger,
        private readonly SupplierLedgerService $supplierLedger,
    ) {}

    public function build(Customer|Supplier $party, ?int $currencyId, ?string $dateFrom, ?string $dateTo): TabularDocument
    {
        $isCustomer = $party instanceof Customer;
        $statement = $isCustomer
            ? $this->customerLedger->fullStatement($party, $currencyId, $dateFrom, $dateTo)
            : $this->supplierLedger->fullStatement($party, $currencyId, $dateFrom, $dateTo);

        $entries = [];
        foreach ($statement['entries'] as $entry) {
            $type = $entry->reference_type instanceof \BackedEnum ? $entry->reference_type->value : (string) $entry->reference_type;
            $key = $entry->reference_id !== null ? $type.'|'.$entry->reference_id : null;
            $number = $key !== null ? ($statement['document_numbers'][$key] ?? null) : null;
            $running = $statement['running_balances'][(int) $entry->id] ?? null;
            $reversed = isset($statement['reversed'][(int) $entry->id]);
            $entries[] = $isCustomer
                ? CustomerLedgerEntryResponseData::fromModel($entry, $number, $running, $reversed)->toArray()
                : SupplierLedgerEntryResponseData::fromModel($entry, $number, $running, $reversed)->toArray();
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

        $code = $isCustomer ? $party->customer_code : $party->supplier_code;

        return LedgerStatementDocument::make(
            partyKind: $isCustomer ? 'customer' : 'supplier',
            partyName: (string) $party->name,
            partyCode: $code !== null ? (string) $code : null,
            currencyLabel: $currencyLabel !== null ? (string) $currencyLabel : null,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            showCurrency: $currencyId === null,
            entries: $entries,
            summaries: $statement['summaries'],
        );
    }
}
