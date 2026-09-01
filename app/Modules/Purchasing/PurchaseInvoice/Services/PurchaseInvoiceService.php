<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Purchasing\PurchaseInvoice\Enums\PurchaseInvoiceStatus;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoice;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoiceLine;
use App\Modules\Purchasing\PurchaseInvoice\Support\PurchaseInvoiceLineQuantity;
use App\Modules\Purchasing\PurchaseInvoice\Support\PurchaseInvoiceRules;
use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function __construct(
        private readonly PurchaseInvoiceQueryService $queryService,
        private readonly SupplierLedgerService $ledgerService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   supplier_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, PurchaseInvoice>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryService->paginate($filters, $perPage);
    }

    public function find(string $id): PurchaseInvoice
    {
        return PurchaseInvoice::query()
            ->with([
                'supplier:id,supplier_code,name,is_active,payment_terms_id,payment_method_id,is_exempted,exempted_from,exempted_to',
                'currency:id,code,name,symbol,iso_code,is_active',
                'paymentTerm:id,code,name,due_days',
                'paymentMethod:id,code,name',
                'purchaseOrder:id,po_number,status',
                'goodsReceipt:id,grn_number,status',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
                'lines.vatGroup:id,abrv,name,percentage',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array{
     *   supplier_id:string,
     *   currency_id:int,
     *   payment_terms_id?:?int,
     *   payment_method_id?:?int,
     *   purchase_order_id?:?string,
     *   goods_receipt_id?:?string,
     *   invoice_date?:string,
     *   due_date?:?string,
     *   supplier_reference?:?string,
     *   notes?:?string,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): PurchaseInvoice
    {
        $supplier = PurchaseInvoiceRules::assertSupplier((string) $data['supplier_id']);
        PurchaseInvoiceRules::assertCurrency((int) $data['currency_id']);

        return DB::transaction(function () use ($data, $userId, $supplier): PurchaseInvoice {
            $invoiceDate = (string) ($data['invoice_date'] ?? now()->toDateString());
            $paymentTermsId = $this->resolvePaymentTermsId($data, $supplier);
            $dueDate = $this->resolveDueDate($data['due_date'] ?? null, $invoiceDate, $paymentTermsId);

            $invoice = PurchaseInvoice::query()->create([
                'supplier_id' => $supplier->id,
                'currency_id' => (int) $data['currency_id'],
                'payment_terms_id' => $paymentTermsId,
                'payment_method_id' => $this->nullableInt($data['payment_method_id'] ?? $supplier->payment_method_id),
                'purchase_order_id' => $this->nullableUuid($data['purchase_order_id'] ?? null),
                'goods_receipt_id' => $this->nullableUuid($data['goods_receipt_id'] ?? null),
                'status' => PurchaseInvoiceStatus::Draft,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'supplier_reference' => $this->normalizeOptionalString($data['supplier_reference'] ?? null, 128),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'subtotal' => '0.0000',
                'tax_total' => '0.0000',
                'grand_total' => '0.0000',
                'created_by' => $userId,
            ]);

            $invoice->update(['invoice_number' => $this->formatInvoiceNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($invoice, $data['lines']);
            }

            return $this->find($invoice->id);
        });
    }

    /**
     * @param  array{
     *   supplier_id?:string,
     *   currency_id?:int,
     *   payment_terms_id?:?int,
     *   payment_method_id?:?int,
     *   purchase_order_id?:?string,
     *   goods_receipt_id?:?string,
     *   invoice_date?:string,
     *   due_date?:?string,
     *   supplier_reference?:?string,
     *   notes?:?string
     * }  $data
     */
    public function updateHeader(PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data): PurchaseInvoice {
            $invoice = $this->lockDraft($invoice);

            $supplierId = array_key_exists('supplier_id', $data)
                ? (string) $data['supplier_id']
                : (string) $invoice->supplier_id;
            $supplier = PurchaseInvoiceRules::assertSupplier($supplierId);

            if (array_key_exists('currency_id', $data) && $data['currency_id'] !== null) {
                PurchaseInvoiceRules::assertCurrency((int) $data['currency_id']);
            }

            $invoiceDate = array_key_exists('invoice_date', $data) && $data['invoice_date']
                ? (string) $data['invoice_date']
                : $invoice->invoice_date?->toDateString() ?? now()->toDateString();

            $paymentTermsId = array_key_exists('payment_terms_id', $data)
                ? $this->nullableInt($data['payment_terms_id'])
                : $invoice->payment_terms_id;

            $dueDate = array_key_exists('due_date', $data)
                ? $this->resolveDueDate($data['due_date'], $invoiceDate, $paymentTermsId)
                : $this->resolveDueDate(
                    $invoice->due_date?->toDateString(),
                    $invoiceDate,
                    $paymentTermsId
                );

            $invoice->update([
                'supplier_id' => $supplier->id,
                'currency_id' => array_key_exists('currency_id', $data) && $data['currency_id'] !== null
                    ? (int) $data['currency_id']
                    : $invoice->currency_id,
                'payment_terms_id' => $paymentTermsId,
                'payment_method_id' => array_key_exists('payment_method_id', $data)
                    ? $this->nullableInt($data['payment_method_id'])
                    : $invoice->payment_method_id,
                'purchase_order_id' => array_key_exists('purchase_order_id', $data)
                    ? $this->nullableUuid($data['purchase_order_id'])
                    : $invoice->purchase_order_id,
                'goods_receipt_id' => array_key_exists('goods_receipt_id', $data)
                    ? $this->nullableUuid($data['goods_receipt_id'])
                    : $invoice->goods_receipt_id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'supplier_reference' => array_key_exists('supplier_reference', $data)
                    ? $this->normalizeOptionalString($data['supplier_reference'], 128)
                    : $invoice->supplier_reference,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $invoice->notes,
            ]);

            if ($invoice->lines()->exists()) {
                $this->recalculateTotals($invoice);
            }

            return $this->find($invoice->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, PurchaseInvoiceLine>
     */
    public function syncLines(PurchaseInvoice $invoice, array $lines): Collection
    {
        return DB::transaction(function () use ($invoice, $lines): Collection {
            $invoice = $this->lockDraft($invoice);
            $this->replaceLines($invoice, $lines);

            return PurchaseInvoiceLine::query()
                ->where('purchase_invoice_id', $invoice->id)
                ->with(['item', 'itemUom.uom', 'vatGroup'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(PurchaseInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = $this->lockDraft($invoice);
            $invoice->delete();
        });
    }

    public function post(PurchaseInvoice $invoice, ?string $userId): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): PurchaseInvoice {
            $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            PurchaseInvoiceRules::assertPostable($locked);

            $supplier = PurchaseInvoiceRules::assertSupplier((string) $locked->supplier_id);
            PurchaseInvoiceRules::assertCurrency((int) $locked->currency_id);

            $this->recalculateTotals($locked);
            $locked->refresh();

            if (bccomp((string) $locked->grand_total, '0', 4) <= 0) {
                abort(422, 'Cannot post a purchase invoice with zero grand total.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_ZERO_TOTAL',
                ]);
            }

            $this->ledgerService->postEntry(
                $supplier,
                (int) $locked->currency_id,
                '0',
                (string) $locked->grand_total,
                LedgerReferenceType::PurchaseInvoice,
                (string) $locked->id,
                $locked->invoice_date?->toDateString() ?? now()->toDateString(),
            );

            $locked->update([
                'status' => PurchaseInvoiceStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(PurchaseInvoice $invoice, array $lines): void
    {
        $supplier = Supplier::query()->findOrFail($invoice->supplier_id);
        $taxContext = DocumentTaxContext::current();
        $invoiceDate = $invoice->invoice_date?->toDateString() ?? now()->toDateString();
        $pricesIncludeTax = $taxContext->pricesIncludeTax();

        $seenItems = [];
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = (string) ($row['item_id'] ?? '');
            if ($itemId === '') {
                abort(422, 'Select an item for each purchase invoice line.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_ITEM_REQUIRED',
                ]);
            }

            if (isset($seenItems[$itemId])) {
                abort(422, 'Duplicate items are not allowed on a purchase invoice.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_DUPLICATE_ITEM',
                ]);
            }
            $seenItems[$itemId] = true;

            $item = Item::query()->with('vatGroup')->findOrFail($itemId);
            PurchaseInvoiceRules::assertPurchasableItem($item);

            $qty = PurchaseInvoiceLineQuantity::resolve(
                $item,
                (float) ($row['quantity'] ?? 0),
                isset($row['item_uom_id']) && $row['item_uom_id'] !== '' && $row['item_uom_id'] !== null
                    ? (int) $row['item_uom_id']
                    : null,
            );

            $unitPrice = $this->normalizeUnitPrice($row['unit_price'] ?? null);
            $taxRate = $taxContext->purchaseLineTaxRatePercent($supplier, $item, $invoiceDate);
            $tax = DocumentTaxMath::calculateLine(
                $qty['quantity'],
                $unitPrice,
                $taxRate,
                $pricesIncludeTax,
            );

            $normalized[] = [
                'purchase_invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'purchase_order_line_id' => $this->nullableInt($row['purchase_order_line_id'] ?? null),
                'goods_receipt_line_id' => $this->nullableInt($row['goods_receipt_line_id'] ?? null),
                'quantity' => $qty['quantity'],
                'base_quantity' => $qty['base_quantity'],
                'item_uom_id' => $qty['item_uom_id'],
                'unit_price' => $unitPrice,
                'vat_group_id' => $item->vat_group_id,
                'tax_rate' => $taxRate,
                'line_subtotal' => $tax['line_subtotal'],
                'tax_amount' => $tax['tax_amount'],
                'line_total' => $tax['line_total'],
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        PurchaseInvoiceLine::query()->where('purchase_invoice_id', $invoice->id)->delete();

        foreach ($normalized as $line) {
            PurchaseInvoiceLine::query()->create($line);
        }

        $totals = DocumentTaxMath::sumTotals(array_map(
            static fn (array $line): array => [
                'line_subtotal' => $line['line_subtotal'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ],
            $normalized,
        ));

        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ]);
    }

    private function recalculateTotals(PurchaseInvoice $invoice): void
    {
        $lines = PurchaseInvoiceLine::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (PurchaseInvoiceLine $line): array => [
                'item_id' => $line->item_id,
                'quantity' => (string) $line->quantity,
                'item_uom_id' => $line->item_uom_id,
                'unit_price' => (string) $line->unit_price,
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'goods_receipt_line_id' => $line->goods_receipt_line_id,
                'notes' => $line->notes,
            ])
            ->all();

        $this->replaceLines($invoice, $lines);
    }

    private function lockDraft(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        PurchaseInvoiceRules::assertDraft($locked);

        return $locked;
    }

    private function formatInvoiceNumber(): string
    {
        $seq = PurchaseInvoice::query()->count();

        return 'PI-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePaymentTermsId(array $data, Supplier $supplier): ?int
    {
        if (array_key_exists('payment_terms_id', $data)) {
            return $this->nullableInt($data['payment_terms_id']);
        }

        return $supplier->payment_terms_id !== null ? (int) $supplier->payment_terms_id : null;
    }

    private function resolveDueDate(mixed $dueDate, string $invoiceDate, ?int $paymentTermsId): ?string
    {
        if ($dueDate !== null && $dueDate !== '') {
            return (string) $dueDate;
        }

        if ($paymentTermsId === null) {
            return null;
        }

        $term = PaymentTerm::query()->find($paymentTermsId);
        if ($term === null) {
            return null;
        }

        return Carbon::parse($invoiceDate)->addDays((int) $term->due_days)->toDateString();
    }

    private function normalizeUnitPrice(mixed $value): string
    {
        if ($value === null || $value === '') {
            abort(422, 'Unit price is required.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_UNIT_PRICE_REQUIRED',
            ]);
        }

        $price = DocumentTaxMath::money($value);
        if (bccomp($price, '0', 4) < 0) {
            abort(422, 'Unit price cannot be negative.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINE_INVALID_UNIT_PRICE',
            ]);
        }

        return $price;
    }

    private function normalizeNotes(mixed $value): ?string
    {
        return $this->normalizeOptionalString($value, 2000);
    }

    private function normalizeOptionalString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $max);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableUuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
