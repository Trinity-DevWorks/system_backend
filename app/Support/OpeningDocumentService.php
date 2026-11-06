<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType as CustomerLedgerType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteStatus;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteType;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteStatus;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteType;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Supplier\Enums\LedgerReferenceType as SupplierLedgerType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A customer's or supplier's old open balance, brought in as documents:
 * positive amount = opening invoice (they owe us / we owe the supplier),
 * negative amount = opening credit (we owe the customer / the supplier owes us).
 *
 * Opening documents are posted on save, have no lines, and post against Opening Balance Equity.
 * They are edited from the customer/supplier only, and only while nothing (payment, credit, refund) uses them.
 */
class OpeningDocumentService
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedger,
        private readonly SupplierLedgerService $supplierLedger,
        private readonly JournalPostingService $journal,
        private readonly DocumentNumberService $numbers,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * Opening documents are changed only from their customer/supplier (not reversed or printed as normal documents).
     */
    public static function assertNotOpening(Model $document): void
    {
        if ((bool) $document->getAttribute('is_opening')) {
            abort(422, 'Opening balances are managed from the customer or supplier.', [
                'X-Error-Code' => 'OPENING_DOCUMENT_MANAGED_BY_PARTY',
            ]);
        }
    }

    /**
     * An opening invoice has no items, so nothing can be returned against it (credit can still be applied to it).
     */
    public static function assertCanReturnAgainst(Model $invoice): void
    {
        if ((bool) $invoice->getAttribute('is_opening')) {
            abort(422, 'An opening invoice has no items to return. Apply an existing credit to it instead.', [
                'X-Error-Code' => 'OPENING_INVOICE_NO_RETURN',
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(Customer $customer): array
    {
        return $this->rows($this->side('customer'), (string) $customer->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForSupplier(Supplier $supplier): array
    {
        return $this->rows($this->side('supplier'), (string) $supplier->id);
    }

    /**
     * Opening balance per currency (sum of opening invoices minus opening credits, as entered).
     *
     * @param  list<string>  $partyIds
     * @return array<string, array<int, string>> party id => currency id => amount
     */
    public function totals(string $party, array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }
        $side = $this->side($party);
        $out = [];
        foreach ([[$side['invoice'], '1'], [$side['note'], '-1']] as [$class, $sign]) {
            $rows = $class::query()
                ->where('is_opening', true)
                ->whereIn($side['party_column'], $partyIds)
                ->groupBy($side['party_column'], 'currency_id')
                ->selectRaw($side['party_column'].' as party_id, currency_id, SUM(grand_total) as total')
                ->get();
            foreach ($rows as $row) {
                $current = $out[(string) $row->party_id][(int) $row->currency_id] ?? '0';
                $out[(string) $row->party_id][(int) $row->currency_id] = Decimal::round(Decimal::add($current, Decimal::mul((string) $row->total, $sign)), 4);
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncCustomer(Customer $customer, array $rows, ?string $userId): void
    {
        $this->sync($this->side('customer'), $customer, $rows, $userId);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncSupplier(Supplier $supplier, array $rows, ?string $userId): void
    {
        $this->sync($this->side('supplier'), $supplier, $rows, $userId);
    }

    /**
     * @param  array<string, mixed>  $side
     * @param  list<array<string, mixed>>  $rows
     */
    private function sync(array $side, Customer|Supplier $party, array $rows, ?string $userId): void
    {
        DB::transaction(function () use ($side, $party, $rows, $userId): void {
            $partyId = (string) $party->id;
            /** @var array<string, Model> $existing */
            $existing = [];
            foreach ([$side['invoice'], $side['note']] as $class) {
                foreach ($class::query()->where('is_opening', true)->where($side['party_column'], $partyId)->lockForUpdate()->get() as $doc) {
                    $existing[(string) $doc->getKey()] = $doc;
                }
            }

            $kept = [];
            foreach ($rows as $row) {
                $data = $this->normalize($row);
                $id = isset($row['id']) && $row['id'] !== '' ? (string) $row['id'] : null;
                $doc = $id !== null ? ($existing[$id] ?? null) : null;
                if ($id !== null && $doc === null) {
                    $this->fail('This opening document no longer exists.', 'OPENING_DOCUMENT_NOT_FOUND', 404);
                }

                if ($doc === null) {
                    $this->create($side, $party, $data, $userId);

                    continue;
                }

                $kept[$id] = true;
                $this->update($side, $party, $doc, $data, $userId);
            }

            foreach ($existing as $id => $doc) {
                if (! isset($kept[$id])) {
                    $this->assertUnused($side, $doc);
                    $this->remove($side, $doc);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $side
     * @param  array{currency_id: int, date: string, due_on: string, amount: string, original_number: ?string, branch_id: ?int}  $data
     */
    private function create(array $side, Customer|Supplier $party, array $data, ?string $userId): void
    {
        $isInvoice = Decimal::isPositive($data['amount']);
        $class = $isInvoice ? $side['invoice'] : $side['note'];
        $amount = Decimal::abs($data['amount']);
        $rate = ExchangeRateSnapshot::resolve($this->exchangeRates, $data['currency_id'], null, 'OPENING_DOCUMENT');

        $attributes = [
            'is_opening' => true,
            'original_number' => $data['original_number'],
            $side['party_column'] => (string) $party->id,
            'currency_id' => $data['currency_id'],
            'exchange_rate' => $rate,
            'subtotal' => $amount,
            'grand_total' => $amount,
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
        ];
        if ($data['branch_id'] !== null) {
            $attributes['branch_id'] = $data['branch_id'];
        }

        if ($isInvoice) {
            $attributes += [
                'status' => $side['invoice_posted'],
                'invoice_date' => $data['date'],
                'due_on' => $data['due_on'],
                'net_to_pay' => $amount,
            ];
        } else {
            $attributes += [
                'status' => $side['note_posted'],
                'type' => $side['note_type'],
                $side['note_date'] => $data['date'],
                'reason' => 'Opening balance',
                'open_credit' => $amount,
            ];
        }

        /** @var Model $doc */
        $doc = $class::query()->create($attributes);
        $this->numbers->assign(
            $doc,
            $isInvoice ? 'invoice_number' : $side['note_number'],
            $isInvoice ? $side['invoice_type'] : $side['note_type_number'],
            (int) $doc->getAttribute('branch_id'),
            $data['date'],
        );
        $this->post($side, $party, $doc->refresh(), $userId);
    }

    /**
     * @param  array<string, mixed>  $side
     * @param  array{currency_id: int, date: string, due_on: string, amount: string, original_number: ?string, branch_id: ?int}  $data
     */
    private function update(array $side, Customer|Supplier $party, Model $doc, array $data, ?string $userId): void
    {
        $isInvoice = $doc instanceof $side['invoice'];
        $dateColumn = $isInvoice ? 'invoice_date' : $side['note_date'];
        $signed = $isInvoice ? (string) $doc->getAttribute('grand_total') : Decimal::mul((string) $doc->getAttribute('grand_total'), '-1');

        $postingChanged = Decimal::cmp($signed, $data['amount']) !== 0
            || (int) $doc->getAttribute('currency_id') !== $data['currency_id']
            || $this->dateString($doc->getAttribute($dateColumn)) !== $data['date']
            || ($data['branch_id'] !== null && (int) $doc->getAttribute('branch_id') !== $data['branch_id']);

        if (! $postingChanged) {
            // Number from the old system and due date do not touch the books: always editable.
            $patch = ['original_number' => $data['original_number']];
            if ($isInvoice) {
                $patch['due_on'] = $data['due_on'];
            }
            $doc->forceFill($patch)->save();

            return;
        }

        $this->assertUnused($side, $doc);
        $this->remove($side, $doc);
        $this->create($side, $party, $data, $userId);
    }

    /**
     * @param  array<string, mixed>  $side
     */
    private function post(array $side, Customer|Supplier $party, Model $doc, ?string $userId): void
    {
        $isInvoice = $doc instanceof $side['invoice'];
        $amount = (string) $doc->getAttribute('grand_total');
        $currencyId = (int) $doc->getAttribute('currency_id');
        $date = $this->dateString($doc->getAttribute($isInvoice ? 'invoice_date' : $side['note_date']));
        $id = (string) $doc->getKey();

        if ($side['party'] === 'customer') {
            /** @var Customer $party */
            $isInvoice
                ? $this->customerLedger->postEntry($party, $currencyId, $amount, '0', CustomerLedgerType::Invoice, $id, $date)
                : $this->customerLedger->postEntry($party, $currencyId, '0', $amount, CustomerLedgerType::CreditNote, $id, $date);
            /** @var SalesInvoice|SalesCreditNote $doc */
            $doc instanceof SalesInvoice
                ? $this->journal->salesInvoice($doc, $userId)
                : $this->journal->salesCreditNote($doc, $userId);

            return;
        }

        /** @var Supplier $party */
        $isInvoice
            ? $this->supplierLedger->postEntry($party, $currencyId, '0', $amount, SupplierLedgerType::PurchaseInvoice, $id, $date)
            : $this->supplierLedger->postEntry($party, $currencyId, $amount, '0', SupplierLedgerType::DebitNote, $id, $date);
        /** @var PurchaseInvoice|PurchaseDebitNote $doc */
        $doc instanceof PurchaseInvoice
            ? $this->journal->purchaseInvoice($doc, $userId)
            : $this->journal->purchaseDebitNote($doc, $userId);
    }

    /**
     * Delete the document with its ledger rows and journal entry (only while unused).
     *
     * @param  array<string, mixed>  $side
     */
    private function remove(array $side, Model $doc): void
    {
        $isInvoice = $doc instanceof $side['invoice'];
        $id = (string) $doc->getKey();
        $sourceType = $isInvoice ? $side['invoice']::REFERENCE_TYPE : $side['note']::REFERENCE_TYPE;

        $this->journal->deleteDocumentEntries($sourceType, $id);
        if ($side['party'] === 'customer') {
            $this->customerLedger->deleteDocumentEntries($isInvoice ? CustomerLedgerType::Invoice : CustomerLedgerType::CreditNote, $id);
        } else {
            $this->supplierLedger->deleteDocumentEntries($isInvoice ? SupplierLedgerType::PurchaseInvoice : SupplierLedgerType::DebitNote, $id);
        }
        $doc->delete();
    }

    /**
     * @param  array<string, mixed>  $side
     */
    private function assertUnused(array $side, Model $doc): void
    {
        if ($this->usedAmount($side, $doc) !== null) {
            $this->fail('This opening document is already used by a payment, credit or refund.', 'OPENING_DOCUMENT_IN_USE');
        }
    }

    /**
     * What already used the document (paid / credited / applied / refunded), or null when untouched.
     *
     * @param  array<string, mixed>  $side
     */
    private function usedAmount(array $side, Model $doc): ?string
    {
        $id = (string) $doc->getKey();
        $isInvoice = $doc instanceof $side['invoice'];
        $columns = $isInvoice ? ['paid_total', $side['invoice_credited']] : ['applied_total', 'refunded_total'];
        $used = '0';
        foreach ($columns as $column) {
            $used = Decimal::add($used, (string) $doc->getAttribute($column));
        }

        $links = $isInvoice ? $side['invoice_links'] : $side['note_links'];
        $linked = false;
        foreach ($links as [$table, $column]) {
            $linked = $linked || DB::table($table)->where($column, $id)->exists();
        }

        return Decimal::isPositive($used) || $linked ? $used : null;
    }

    /**
     * @param  array<string, mixed>  $side
     * @return list<array<string, mixed>>
     */
    private function rows(array $side, string $partyId): array
    {
        $out = [];
        foreach ([$side['invoice'], $side['note']] as $class) {
            $isInvoice = $class === $side['invoice'];
            $docs = $class::query()
                ->with(['currency:id,code,symbol', 'branch:id,name'])
                ->where('is_opening', true)
                ->where($side['party_column'], $partyId)
                ->get();
            foreach ($docs as $doc) {
                $used = $this->usedAmount($side, $doc);
                $amount = (string) $doc->getAttribute('grand_total');
                $out[] = [
                    'id' => (string) $doc->getKey(),
                    'kind' => $isInvoice ? 'invoice' : 'credit',
                    'number' => $doc->getAttribute($isInvoice ? 'invoice_number' : $side['note_number']),
                    'original_number' => $doc->getAttribute('original_number'),
                    'document_date' => $this->dateString($doc->getAttribute($isInvoice ? 'invoice_date' : $side['note_date'])),
                    'due_on' => $isInvoice ? $this->dateString($doc->getAttribute('due_on')) : null,
                    'currency_id' => (int) $doc->getAttribute('currency_id'),
                    'currency_code' => $doc->getRelation('currency')?->code,
                    'branch_id' => (int) $doc->getAttribute('branch_id'),
                    'branch_name' => $doc->getRelation('branch')?->name,
                    'amount' => Decimal::round($isInvoice ? $amount : Decimal::mul($amount, '-1'), 4),
                    'open_amount' => (string) $doc->getAttribute($isInvoice ? 'net_to_pay' : 'open_credit'),
                    'used_amount' => Decimal::round($used ?? '0', 4),
                    'locked' => $used !== null,
                ];
            }
        }
        usort($out, fn (array $a, array $b): int => [$a['document_date'], $a['number']] <=> [$b['document_date'], $b['number']]);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{currency_id: int, date: string, due_on: string, amount: string, original_number: ?string, branch_id: ?int}
     */
    private function normalize(array $row): array
    {
        $amount = PriceMath::normalize((string) ($row['amount'] ?? '0'));
        if (Decimal::isZero($amount)) {
            $this->fail('An opening amount cannot be zero.', 'OPENING_DOCUMENT_AMOUNT_INVALID');
        }
        $date = Carbon::parse((string) $row['document_date'])->toDateString();
        $due = isset($row['due_on']) && $row['due_on'] !== '' ? Carbon::parse((string) $row['due_on'])->toDateString() : $date;
        if ($due < $date) {
            $this->fail('The due date cannot be before the document date.', 'OPENING_DOCUMENT_DUE_DATE_INVALID');
        }
        $original = isset($row['original_number']) ? trim((string) $row['original_number']) : '';

        return [
            'currency_id' => (int) $row['currency_id'],
            'date' => $date,
            'due_on' => $due,
            'amount' => $amount,
            'original_number' => $original !== '' ? $original : null,
            'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function side(string $party): array
    {
        return $party === 'customer'
            ? [
                'party' => 'customer',
                'party_column' => 'customer_id',
                'invoice' => SalesInvoice::class,
                'invoice_posted' => SalesInvoiceStatus::Posted,
                'invoice_type' => DocumentType::OpeningSalesInvoice,
                'invoice_credited' => 'credited_total',
                'invoice_links' => [['customer_receipt_allocations', 'sales_invoice_id'], ['sales_credit_note_allocations', 'sales_invoice_id'], ['sales_credit_notes', 'sales_invoice_id']],
                'note' => SalesCreditNote::class,
                'note_posted' => SalesCreditNoteStatus::Posted,
                'note_type' => SalesCreditNoteType::Allowance,
                'note_type_number' => DocumentType::OpeningCreditNote,
                'note_number' => 'credit_note_number',
                'note_date' => 'credit_date',
                'note_links' => [['sales_credit_note_allocations', 'sales_credit_note_id'], ['customer_refund_allocations', 'sales_credit_note_id']],
            ]
            : [
                'party' => 'supplier',
                'party_column' => 'supplier_id',
                'invoice' => PurchaseInvoice::class,
                'invoice_posted' => PurchaseInvoiceStatus::Posted,
                'invoice_type' => DocumentType::OpeningPurchaseInvoice,
                'invoice_credited' => 'debited_total',
                'invoice_links' => [['supplier_payment_allocations', 'purchase_invoice_id'], ['purchase_debit_note_allocations', 'purchase_invoice_id'], ['purchase_debit_notes', 'purchase_invoice_id']],
                'note' => PurchaseDebitNote::class,
                'note_posted' => PurchaseDebitNoteStatus::Posted,
                'note_type' => PurchaseDebitNoteType::Allowance,
                'note_type_number' => DocumentType::OpeningDebitNote,
                'note_number' => 'debit_note_number',
                'note_date' => 'debit_date',
                'note_links' => [['purchase_debit_note_allocations', 'purchase_debit_note_id'], ['supplier_refund_allocations', 'purchase_debit_note_id']],
            ];
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : Carbon::parse((string) $value)->toDateString();
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        abort($status, $message, ['X-Error-Code' => $code]);
    }
}
