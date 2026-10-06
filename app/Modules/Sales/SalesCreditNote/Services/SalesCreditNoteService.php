<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Services;

use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteStatus;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNoteLine;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use App\Support\PaymentAllocation;
use App\Support\SequentialCodeGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesCreditNoteService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
    ) {}

    /**
     * @param  array{status?:?string, customer_id?:?string, search?:?string, from?:?string, to?:?string}  $filters
     * @return LengthAwarePaginator<int, SalesCreditNote>
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SalesCreditNote::query()
            ->with([
                'customer:id,customer_code,name',
                'warehouse:id,name,shortcut_name',
                'currency:id,code,name,symbol',
                'salesInvoice:id,invoice_number,status',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if (! empty($filters['status'])) {
            $status = SalesCreditNoteStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }
        if (! empty($filters['search'])) {
            $search = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function ($q) use ($search): void {
                $q->where('credit_note_number', 'like', $search)
                    ->orWhereHas('salesInvoice', fn ($sq) => $sq->where('invoice_number', 'like', $search))
                    ->orWhereHas('customer', fn ($sq) => $sq->where('name', 'like', $search)
                        ->orWhere('customer_code', 'like', $search));
            });
        }
        if (! empty($filters['from'])) {
            $query->whereDate('credit_date', '>=', (string) $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('credit_date', '<=', (string) $filters['to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function find(string $id): SalesCreditNote
    {
        $note = SalesCreditNote::query()->with(['lines.item', 'lines.itemUom.uom'])->findOrFail($id);
        $this->warehouseService->assertVisibleById((int) $note->warehouse_id);

        return $note;
    }

    /**
     * Posted invoices that still have an open amount.
     *
     * @return list<array<string, mixed>>
     */
    public function openInvoices(?string $customerId): array
    {
        $query = SalesInvoice::query()
            ->where('status', SalesInvoiceStatus::Posted)
            ->where('net_to_pay', '>', 0)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');
        if (is_string($customerId) && $customerId !== '') {
            $query->where('customer_id', $customerId);
        }

        return $query->get(['id', 'invoice_number', 'customer_id', 'grand_total', 'paid_total', 'credited_total', 'net_to_pay', 'currency_id', 'status'])
            ->map(static fn (SalesInvoice $invoice): array => [
                'id' => (string) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => (string) $invoice->customer_id,
                'grand_total' => (string) $invoice->grand_total,
                'paid_total' => (string) $invoice->paid_total,
                'credited_total' => (string) $invoice->credited_total,
                'net_to_pay' => (string) $invoice->net_to_pay,
                'currency_id' => (int) $invoice->currency_id,
                'status' => $invoice->status instanceof SalesInvoiceStatus ? $invoice->status->value : (string) $invoice->status,
            ])
            ->all();
    }

    /**
     * Remaining quantities on a posted invoice that can still be credited.
     *
     * @return list<array<string, mixed>>
     */
    public function sourceLines(SalesInvoice $invoice, ?string $exceptCreditNoteId = null): array
    {
        $this->assertInvoiceCreditable($invoice);
        $invoice->loadMissing(['lines.item:id,item_code,name', 'lines.itemUom.uom:id,code,name']);
        $credited = $this->creditedQuantities((string) $invoice->id, $exceptCreditNoteId);
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $open = PaymentAllocation::normalize($invoice->net_to_pay);

        $pending = [];
        $openValue = '0';
        foreach ($invoice->lines as $line) {
            $remaining = bcsub((string) $line->quantity, $credited[(int) $line->id] ?? '0', 6);
            if (bccomp($remaining, '0', 6) <= 0) {
                continue;
            }
            $remainingTotal = $this->creditLineTotal($line, $remaining, $includeTax);
            $openValue = bcadd($openValue, $remainingTotal, 4);
            $pending[] = [
                'line' => $line,
                'remaining' => $remaining,
                'remaining_total' => $remainingTotal,
            ];
        }

        $scale = '1';
        if (bccomp($openValue, '0', 4) > 0 && bccomp($openValue, $open, 4) > 0) {
            $scale = bcdiv($open, $openValue, 12);
        }

        $rows = [];
        $openLeft = $open;
        foreach ($pending as $row) {
            /** @var SalesInvoiceLine $line */
            $line = $row['line'];
            $remaining = $row['remaining'];
            $suggested = $this->quantityCappedToOpen(
                $line,
                bcmul($remaining, $scale, 6),
                $openLeft,
                $includeTax,
            );
            if (bccomp($suggested, $remaining, 6) > 0) {
                $suggested = $remaining;
            }
            $suggestedTotal = $this->creditLineTotal($line, $suggested, $includeTax);
            $openLeft = bcsub($openLeft, $suggestedTotal, 4);
            if (bccomp($openLeft, '0', 4) < 0) {
                $openLeft = PaymentAllocation::normalize(0);
            }

            $rows[] = [
                'sales_invoice_line_id' => (int) $line->id,
                'item_id' => (string) $line->item_id,
                'item_code' => $line->item?->item_code,
                'item_name' => $line->item?->name,
                'uom_code' => $line->itemUom?->uom?->code,
                'quantity' => (string) $line->quantity,
                'remaining_quantity' => $remaining,
                'suggested_quantity' => $suggested,
                'unit_price' => (string) $line->unit_price,
                'discount_percent' => (string) $line->discount_percent,
                'tax_rate' => (string) $line->tax_rate,
                'remaining_line_total' => $row['remaining_total'],
                'suggested_line_total' => $suggestedTotal,
                'description' => $line->description,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SalesCreditNote
    {
        return DB::transaction(function () use ($data, $userId): SalesCreditNote {
            $invoice = $this->lockPostedInvoice((string) $data['sales_invoice_id']);
            $this->assertInvoiceCreditable($invoice);

            $note = SalesCreditNote::query()->create([
                'credit_note_number' => SequentialCodeGenerator::next(SalesCreditNote::class, 'credit_note_number', 'CN-'),
                'sales_invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'warehouse_id' => $invoice->warehouse_id,
                'currency_id' => $invoice->currency_id,
                'status' => SalesCreditNoteStatus::Draft,
                'credit_date' => $data['credit_date'] ?? now()->toDateString(),
                'exchange_rate' => $invoice->exchange_rate,
                'notes' => $this->nullableString($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            if (! empty($data['lines']) && is_array($data['lines'])) {
                $this->replaceLines($note, $invoice, $data['lines']);
            }

            return $this->find((string) $note->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SalesCreditNote $note, array $data): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $data): SalesCreditNote {
            $locked = $this->lockDraft($note);
            $locked->update([
                'credit_date' => $data['credit_date'] ?? $locked->credit_date?->toDateString(),
                'notes' => array_key_exists('notes', $data) ? $this->nullableString($data['notes']) : $locked->notes,
            ]);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(SalesCreditNote $note, array $lines): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $lines): SalesCreditNote {
            $locked = $this->lockDraft($note);
            $invoice = $this->lockPostedInvoice((string) $locked->sales_invoice_id);
            $this->replaceLines($locked, $invoice, $lines);

            return $this->find((string) $locked->id);
        });
    }

    public function delete(SalesCreditNote $note): void
    {
        $this->assertDraft($note);
        $this->warehouseService->assertVisibleById((int) $note->warehouse_id);
        $note->delete();
    }

    public function post(SalesCreditNote $note, ?string $userId): SalesCreditNote
    {
        $posted = DB::transaction(function () use ($note, $userId): SalesCreditNote {
            $locked = SalesCreditNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
            if ($locked->lines()->count() === 0) {
                $this->fail('Cannot post a credit note without lines.', 'CREDIT_NOTE_NO_LINES');
            }

            $invoice = $this->lockPostedInvoice((string) $locked->sales_invoice_id);
            $this->assertInvoiceCreditable($invoice);
            $this->assertLinesWithinRemaining($invoice, $locked, (string) $locked->id);

            $this->recalculateTotals($locked);
            $locked->refresh();
            if (bccomp((string) $locked->grand_total, (string) $invoice->net_to_pay, 4) > 0) {
                $this->fail('This credit exceeds the unpaid amount on the invoice.', 'CREDIT_NOTE_EXCEEDS_OPEN');
            }

            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
            if (bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    (int) $locked->currency_id,
                    '0',
                    (string) $locked->grand_total,
                    LedgerReferenceType::CreditNote,
                    (string) $locked->id,
                    $locked->credit_date->toDateString(),
                );
            }

            $lines = SalesCreditNoteLine::query()
                ->where('sales_credit_note_id', $locked->id)
                ->with('item.itemType')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                $this->postLineStock($locked, $line, $userId);
            }

            $credited = PaymentAllocation::add($invoice->credited_total, $locked->grand_total);
            $invoice->update([
                'credited_total' => $credited,
                'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $invoice->paid_total, $credited),
            ]);

            $locked->update([
                'status' => SalesCreditNoteStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find((string) $locked->id);
        });

        $invoice = SalesInvoice::query()->find((string) $posted->sales_invoice_id);
        if ($invoice instanceof SalesInvoice && $this->isFullyCredited($invoice)) {
            $this->invoiceChainRegistrationService->requestRevocation($invoice);
        }

        return $posted;
    }

    public function reverse(SalesCreditNote $note, ?string $userId): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $userId): SalesCreditNote {
            $locked = SalesCreditNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesCreditNoteStatus::Posted) {
                $this->fail('Only a posted credit note can be reversed.', 'CREDIT_NOTE_NOT_POSTED');
            }
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

            $invoice = $this->lockPostedInvoice((string) $locked->sales_invoice_id);
            if ($this->invoiceChainRegistrationService->revokeRequestedForSalesInvoice((string) $invoice->id)) {
                $this->fail(
                    'This credit note cannot be reversed because the invoice seal was cancelled on the blockchain.',
                    'CREDIT_NOTE_CHAIN_REVOKED',
                );
            }
            $credited = PaymentAllocation::subtract($invoice->credited_total, $locked->grand_total);
            if ($credited === null) {
                $this->fail('Invoice credited total is lower than this credit note.', 'CREDIT_NOTE_UNDERFLOW');
            }

            $this->stockMovementService->reverseReference(SalesCreditNote::REFERENCE_TYPE, (string) $locked->id, $userId);

            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
            if (bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    (int) $locked->currency_id,
                    (string) $locked->grand_total,
                    '0',
                    LedgerReferenceType::CreditNote,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }

            $invoice->update([
                'credited_total' => $credited,
                'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $invoice->paid_total, $credited),
            ]);

            $locked->update(['status' => SalesCreditNoteStatus::Reversed]);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceLines(SalesCreditNote $note, SalesInvoice $invoice, array $rows): void
    {
        SalesCreditNoteLine::query()->where('sales_credit_note_id', $note->id)->delete();
        $credited = $this->creditedQuantities((string) $invoice->id, (string) $note->id);
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $invoiceLines = SalesInvoiceLine::query()
            ->where('sales_invoice_id', $invoice->id)
            ->with('item.itemType')
            ->get()
            ->keyBy('id');

        foreach (array_values($rows) as $index => $row) {
            $sourceId = (int) ($row['sales_invoice_line_id'] ?? 0);
            $source = $invoiceLines->get($sourceId);
            if (! $source instanceof SalesInvoiceLine) {
                $this->fail('Credit note lines must belong to the selected invoice.', 'CREDIT_NOTE_LINE_MISMATCH');
            }
            $item = $source->item ?? Item::query()->findOrFail($source->item_id);
            if (SalesInvoiceRules::isBundleItem($item)) {
                $this->fail('Bundle lines cannot be credited yet.', 'CREDIT_NOTE_BUNDLE_NOT_ALLOWED');
            }

            $qty = number_format((float) ($row['quantity'] ?? 0), 6, '.', '');
            if (bccomp($qty, '0', 6) <= 0) {
                $this->fail('Credit note line quantity must be greater than zero.', 'CREDIT_NOTE_LINE_INVALID_QUANTITY');
            }
            $remaining = bcsub((string) $source->quantity, $credited[$sourceId] ?? '0', 6);
            if (bccomp($qty, $remaining, 6) > 0) {
                $this->fail('Cannot credit more than the remaining quantity on the invoice line.', 'CREDIT_NOTE_QTY_EXCEEDS');
            }

            $factor = (string) $source->conversion_factor;
            $baseQty = bcmul($qty, $factor, 6);
            $math = DocumentTaxMath::line(
                $qty,
                $source->unit_price,
                $source->discount_percent,
                $source->tax_rate,
                $includeTax,
            );

            SalesCreditNoteLine::query()->create([
                'sales_credit_note_id' => $note->id,
                'sales_invoice_line_id' => $source->id,
                'item_id' => $source->item_id,
                'item_uom_id' => $source->item_uom_id,
                'warehouse_id' => $source->warehouse_id,
                'lot_id' => $source->lot_id,
                'sort_order' => $index + 1,
                'quantity' => $qty,
                'base_quantity' => $baseQty,
                'conversion_factor' => $factor,
                'unit_price' => $source->unit_price,
                'discount_percent' => $source->discount_percent,
                'discount_amount' => $math['discount_amount'],
                'tax_rate' => $source->tax_rate,
                'line_subtotal' => $math['line_subtotal'],
                'tax_amount' => $math['tax_amount'],
                'line_total' => $math['line_total'],
                'description' => $source->description,
                'notes' => $this->nullableString($row['notes'] ?? $source->notes),
            ]);
        }

        $this->recalculateTotals($note);
    }

    private function recalculateTotals(SalesCreditNote $note): void
    {
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $rows = SalesCreditNoteLine::query()
            ->where('sales_credit_note_id', $note->id)
            ->orderBy('sort_order')
            ->get();

        $linePayload = [];
        foreach ($rows as $line) {
            $merchandise = bcmul((string) $line->quantity, (string) $line->unit_price, 8);
            $linePayload[] = [
                'merchandise' => $merchandise,
                'discount_amount' => (string) $line->discount_amount,
                'tax_amount' => (string) $line->tax_amount,
            ];
        }

        $totals = DocumentTaxMath::sumTotals($linePayload, '0', $includeTax);
        $note->update([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ]);
    }

    private function postLineStock(SalesCreditNote $note, SalesCreditNoteLine $line, ?string $userId): void
    {
        $item = $line->item ?? Item::query()->findOrFail($line->item_id);
        if (! $item->track_inventory) {
            return;
        }

        $warehouseId = (int) ($line->warehouse_id ?? $note->warehouse_id);
        $this->stockMovementService->post(StockMovementData::forSaleReturn(
            itemId: (string) $line->item_id,
            warehouseId: $warehouseId,
            quantityDelta: (string) $line->base_quantity,
            creditNoteId: (string) $note->id,
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: 'CN '.$note->credit_note_number,
            userId: $userId,
            lotId: $line->lot_id ? (int) $line->lot_id : null,
        ));
    }

    /**
     * @return array<int, string>
     */
    private function creditedQuantities(string $invoiceId, ?string $exceptCreditNoteId): array
    {
        $query = SalesCreditNoteLine::query()
            ->selectRaw('sales_invoice_line_id, COALESCE(SUM(quantity), 0) as qty')
            ->whereHas('creditNote', function ($q) use ($invoiceId, $exceptCreditNoteId): void {
                $q->where('sales_invoice_id', $invoiceId)
                    ->where('status', SalesCreditNoteStatus::Posted->value);
                if ($exceptCreditNoteId) {
                    $q->where('id', '!=', $exceptCreditNoteId);
                }
            })
            ->groupBy('sales_invoice_line_id');

        $out = [];
        foreach ($query->get() as $row) {
            $out[(int) $row->sales_invoice_line_id] = (string) $row->qty;
        }

        return $out;
    }

    private function assertLinesWithinRemaining(SalesInvoice $invoice, SalesCreditNote $note, string $exceptId): void
    {
        $credited = $this->creditedQuantities((string) $invoice->id, $exceptId);
        foreach ($note->lines as $line) {
            $source = SalesInvoiceLine::query()->find($line->sales_invoice_line_id);
            if ($source === null || (string) $source->sales_invoice_id !== (string) $invoice->id) {
                $this->fail('Credit note lines must belong to the selected invoice.', 'CREDIT_NOTE_LINE_MISMATCH');
            }
            $remaining = bcsub((string) $source->quantity, $credited[(int) $source->id] ?? '0', 6);
            if (bccomp((string) $line->quantity, $remaining, 6) > 0) {
                $this->fail('Cannot credit more than the remaining quantity on the invoice line.', 'CREDIT_NOTE_QTY_EXCEEDS');
            }
        }
    }

    private function lockPostedInvoice(string $invoiceId): SalesInvoice
    {
        $invoice = SalesInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);

        return $invoice;
    }

    private function creditLineTotal(SalesInvoiceLine $line, string $qty, bool $includeTax): string
    {
        if (bccomp($qty, '0', 6) <= 0) {
            return PaymentAllocation::normalize(0);
        }

        return PaymentAllocation::normalize(DocumentTaxMath::line(
            $qty,
            $line->unit_price,
            $line->discount_percent,
            $line->tax_rate,
            $includeTax,
        )['line_total']);
    }

    private function quantityCappedToOpen(SalesInvoiceLine $line, string $maxQty, string $open, bool $includeTax): string
    {
        $maxQty = number_format((float) $maxQty, 6, '.', '');
        if (bccomp($maxQty, '0', 6) <= 0 || bccomp($open, '0', 4) <= 0) {
            return number_format(0, 6, '.', '');
        }

        $full = $this->creditLineTotal($line, $maxQty, $includeTax);
        if (bccomp($full, '0', 4) <= 0 || bccomp($full, $open, 4) <= 0) {
            return $maxQty;
        }

        $qty = bcmul($maxQty, bcdiv($open, $full, 12), 6);
        $guard = 0;
        while (
            $guard < 24
            && bccomp($qty, '0', 6) > 0
            && bccomp($this->creditLineTotal($line, $qty, $includeTax), $open, 4) > 0
        ) {
            $qty = bcsub($qty, '0.000001', 6);
            $guard++;
        }

        if (bccomp($qty, '0', 6) <= 0) {
            return number_format(0, 6, '.', '');
        }

        return $qty;
    }

    /**
     * Unpaid invoice whose remaining balance is now entirely written off.
     * Partial payments never reach this: v1 cannot credit the paid portion.
     */
    private function isFullyCredited(SalesInvoice $invoice): bool
    {
        return bccomp((string) ($invoice->paid_total ?? 0), '0', 4) <= 0
            && bccomp((string) ($invoice->net_to_pay ?? 0), '0', 4) <= 0
            && bccomp((string) ($invoice->credited_total ?? 0), '0', 4) > 0;
    }

    private function assertInvoiceCreditable(SalesInvoice $invoice): void
    {
        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            $this->fail('Only a posted sales invoice can be credited.', 'CREDIT_NOTE_INVOICE_NOT_POSTED');
        }
        if (bccomp((string) $invoice->net_to_pay, '0', 4) <= 0) {
            $this->fail('This invoice has no unpaid amount left to credit.', 'CREDIT_NOTE_INVOICE_CLOSED');
        }
    }

    private function lockDraft(SalesCreditNote $note): SalesCreditNote
    {
        $locked = SalesCreditNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
        $this->assertDraft($locked);
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

        return $locked;
    }

    private function assertDraft(SalesCreditNote $note): void
    {
        if ($note->status !== SalesCreditNoteStatus::Draft) {
            $this->fail('Only a draft credit note can be changed.', 'CREDIT_NOTE_NOT_DRAFT');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function fail(string $message, string $code): never
    {
        abort(422, $message, ['X-Error-Code' => $code]);
    }
}
