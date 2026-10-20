<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteStatus;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteType;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNoteAllocation;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNoteLine;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceRules;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ListPagination;
use App\Support\PaymentAllocation;
use App\Support\ProportionalCredit;
use App\Support\SequentialCodeGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseDebitNoteService
{
    public function __construct(
        private readonly SupplierLedgerService $supplierLedgerService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PurchaseDebitNote>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = PurchaseDebitNote::query()
            ->with([
                'supplier:id,supplier_code,name,phone,is_active',
                'currency:id,code,name,symbol',
                'purchaseInvoice:id,invoice_number,invoice_date,status',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        if (! empty($filters['status'])) {
            $status = PurchaseDebitNoteStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }
        if (! empty($filters['type'])) {
            $type = PurchaseDebitNoteType::tryFrom((string) $filters['type']);
            if ($type) {
                $query->where('type', $type->value);
            }
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (string) $filters['supplier_id']);
        }
        if (! empty($filters['purchase_invoice_id'])) {
            $query->where('purchase_invoice_id', (string) $filters['purchase_invoice_id']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['debit_note_number']);
        if (! empty($filters['from'])) {
            $query->where('debit_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('debit_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function find(string $id): PurchaseDebitNote
    {
        return PurchaseDebitNote::query()
            ->with([
                'supplier',
                'currency',
                'purchaseInvoice.currency',
                'createdByUser',
                'postedByUser',
                'lines.item',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
                'allocations.purchaseInvoice',
            ])
            ->withCount('lines')
            ->findOrFail($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoiceLines(string $invoiceId): array
    {
        $invoice = $this->postedInvoice($invoiceId);
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        $usage = $this->postedUsage($invoiceId);
        $invoice->load(['lines.item', 'lines.itemUom.uom', 'lines.warehouse']);

        $rows = [];
        foreach ($invoice->lines as $line) {
            $used = $usage['lines'][(int) $line->id] ?? ProportionalCredit::zero();
            $returnable = bcsub(ProportionalCredit::qty($line->base_quantity), $used['base_quantity'] ?? '0', 6);
            if (bccomp($returnable, '0', 6) < 0) {
                $returnable = ProportionalCredit::qty(0);
            }
            $creditable = bcsub(ProportionalCredit::money($line->line_total), $used['line_total'], 4);
            if (bccomp($creditable, '0', 4) < 0) {
                $creditable = ProportionalCredit::money(0);
            }
            $rows[] = [
                'purchase_invoice_line_id' => $line->id,
                'item_id' => $line->item_id,
                'item_uom_id' => $line->item_uom_id,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'base_quantity' => (string) $line->base_quantity,
                'returned_base_quantity' => $used['base_quantity'] ?? ProportionalCredit::qty(0),
                'returnable_base_quantity' => $returnable,
                'conversion_factor' => (string) $line->conversion_factor,
                'unit_price' => (string) $line->unit_price,
                'discount_percent' => (string) $line->discount_percent,
                'tax_rate' => (string) $line->tax_rate,
                'line_total' => (string) $line->line_total,
                'debited_total' => $used['line_total'],
                'creditable_total' => ProportionalCredit::money($creditable),
                'warehouse_id' => $line->warehouse_id,
                'lot_id' => $line->lot_id,
                'track_inventory' => (bool) ($line->item?->track_inventory),
                'allow_return' => (bool) ($line->item?->allow_return ?? true),
                'item' => $line->item ? [
                    'id' => $line->item->id,
                    'name' => $line->item->name,
                    'item_code' => $line->item->item_code,
                ] : null,
                'uom' => $line->itemUom?->uom ? [
                    'id' => $line->itemUom->uom->id,
                    'code' => $line->itemUom->uom->code,
                    'name' => $line->itemUom->uom->name,
                ] : null,
                'warehouse' => $line->warehouse ? [
                    'id' => $line->warehouse->id,
                    'name' => $line->warehouse->name,
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * Posted invoices of this customer and currency that still have an open balance.
     *
     * @return list<array<string, mixed>>
     */
    public function applicableInvoices(PurchaseDebitNote $note): array
    {
        if ($note->status !== PurchaseDebitNoteStatus::Posted) {
            return [];
        }

        $query = PurchaseInvoice::query()
            ->with('currency:id,code,name,symbol')
            ->where('supplier_id', $note->supplier_id)
            ->where('currency_id', $note->currency_id)
            ->where('status', PurchaseInvoiceStatus::Posted->value)
            ->where('net_to_pay', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('invoice_number');
        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        return $query->get()->map(fn (PurchaseInvoice $invoice): array => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'grand_total' => (string) $invoice->grand_total,
            'paid_total' => (string) $invoice->paid_total,
            'debited_total' => (string) $invoice->debited_total,
            'net_to_pay' => (string) $invoice->net_to_pay,
            'currency_id' => $invoice->currency_id,
            'currency' => $invoice->currency ? [
                'id' => $invoice->currency->id,
                'code' => $invoice->currency->code,
                'symbol' => $invoice->currency->symbol,
            ] : null,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): PurchaseDebitNote
    {
        $invoice = $this->postedInvoice((string) $data['purchase_invoice_id']);
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        $this->assertDate($invoice, (string) $data['debit_date']);

        return DB::transaction(function () use ($data, $userId, $invoice): PurchaseDebitNote {
            $note = PurchaseDebitNote::query()->create([
                'debit_note_number' => SequentialCodeGenerator::next(PurchaseDebitNote::class, 'debit_note_number', 'PDN-'),
                'purchase_invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'type' => $this->type($data['type'])->value,
                'debit_date' => Carbon::parse((string) $data['debit_date'])->toDateString(),
                'reason' => trim((string) $data['reason']),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'adjustment' => ProportionalCredit::money($data['adjustment'] ?? 0),
                'status' => PurchaseDebitNoteStatus::Draft,
                'created_by' => $userId,
            ]);

            if (! empty($data['lines'])) {
                $this->replaceLines($note, $data['lines']);
            }

            return $this->find($note->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(PurchaseDebitNote $note, array $data): PurchaseDebitNote
    {
        return DB::transaction(function () use ($note, $data): PurchaseDebitNote {
            $locked = $this->lockDraft($note);
            $invoice = $this->postedInvoice((string) $locked->purchase_invoice_id);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $this->assertDate($invoice, (string) $data['debit_date']);
            $type = $this->type($data['type']);
            $typeChanged = $type !== $locked->type;

            $locked->update([
                'type' => $type->value,
                'debit_date' => Carbon::parse((string) $data['debit_date'])->toDateString(),
                'reason' => trim((string) $data['reason']),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'adjustment' => ProportionalCredit::money($data['adjustment'] ?? 0),
            ]);

            if ($typeChanged) {
                $locked->lines()->delete();
            }
            $locked->refresh();
            $this->writeTotals($locked);
            if (! $typeChanged) {
                $this->assertLimits($locked, $invoice, $this->postedUsage((string) $invoice->id));
            }

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(PurchaseDebitNote $note, array $lines): PurchaseDebitNote
    {
        return DB::transaction(function () use ($note, $lines): PurchaseDebitNote {
            $locked = $this->lockDraft($note);
            $this->replaceLines($locked, $lines);

            return $this->find($locked->id);
        });
    }

    public function delete(PurchaseDebitNote $note): void
    {
        DB::transaction(function () use ($note): void {
            $locked = $this->lockDraft($note);
            $invoice = PurchaseInvoice::query()->findOrFail($locked->purchase_invoice_id);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $locked->delete();
        });
    }

    public function post(PurchaseDebitNote $note, ?string $userId): PurchaseDebitNote
    {
        return DB::transaction(function () use ($note, $userId): PurchaseDebitNote {
            $locked = PurchaseDebitNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseDebitNoteStatus::Draft) {
                $this->fail('Only a draft debit note can be posted.', 'PURCHASE_DEBIT_NOTE_NOT_DRAFT');
            }

            $invoice = PurchaseInvoice::query()->whereKey($locked->purchase_invoice_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
                $this->fail('Debit notes can only be posted against a posted invoice.', 'PURCHASE_DEBIT_NOTE_INVOICE_NOT_POSTED');
            }
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $this->assertDate($invoice, $locked->debit_date->toDateString());

            PurchaseInvoiceLine::query()
                ->where('purchase_invoice_id', $invoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $invoice->load('lines.item.itemType');
            $this->recomputeStoredLines($locked, $invoice);
            $locked->refresh();
            $locked->load('lines.item.itemType');

            if ($locked->lines->isEmpty() || ! PaymentAllocation::isPositive($locked->grand_total)) {
                $this->fail('Add at least one line with a quantity or an amount.', 'PURCHASE_DEBIT_NOTE_NO_LINES');
            }

            $usage = $this->postedUsage((string) $invoice->id);
            $this->assertLimits($locked, $invoice, $usage);

            if ($locked->type === PurchaseDebitNoteType::Return) {
                foreach ($locked->lines as $line) {
                    $this->postReturnStock($locked, $line, $userId);
                }
            }

            $supplier = PurchaseInvoiceRules::assertSupplier((string) $locked->supplier_id);
            $this->supplierLedgerService->postEntry(
                $supplier,
                (int) $locked->currency_id,
                (string) $locked->grand_total,
                '0',
                LedgerReferenceType::DebitNote,
                (string) $locked->id,
                $locked->debit_date->toDateString(),
            );

            $this->applyToInvoice($locked, $invoice, (string) $locked->grand_total, true, $userId);
            $locked->refresh();

            $this->journalPostingService->purchaseDebitNote($locked, $userId);
            foreach ($locked->allocations()->get() as $allocation) {
                $target = PurchaseInvoice::query()->find($allocation->purchase_invoice_id);
                if ($target instanceof PurchaseInvoice) {
                    $this->journalPostingService->debitApplication($locked, $target, (string) $allocation->amount, $userId);
                }
            }

            $locked->update([
                'status' => PurchaseDebitNoteStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function apply(PurchaseDebitNote $note, array $rows, ?string $userId): PurchaseDebitNote
    {
        return DB::transaction(function () use ($note, $rows, $userId): PurchaseDebitNote {
            $locked = PurchaseDebitNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseDebitNoteStatus::Posted) {
                $this->fail('Only a posted debit note can be applied.', 'PURCHASE_DEBIT_NOTE_NOT_POSTED');
            }

            $payload = [];
            $seen = [];
            foreach ($rows as $row) {
                $invoiceId = (string) ($row['purchase_invoice_id'] ?? '');
                if ($invoiceId === '' || isset($seen[$invoiceId])) {
                    $this->fail('Each invoice can be selected once.', 'PURCHASE_DEBIT_NOTE_INVOICE_MISMATCH');
                }
                $seen[$invoiceId] = true;
                $amount = ProportionalCredit::money($row['amount'] ?? 0);
                if (! PaymentAllocation::isPositive($amount)) {
                    $this->fail('Each allocation must be greater than zero.', 'PURCHASE_DEBIT_NOTE_APPLY_EXCEEDS_OPEN');
                }
                $payload[] = ['purchase_invoice_id' => $invoiceId, 'amount' => $amount];
            }

            $total = PaymentAllocation::sum(array_column($payload, 'amount'));
            if (PaymentAllocation::exceeds($total, $locked->open_credit)) {
                $this->fail('Allocations exceed the open credit.', 'PURCHASE_DEBIT_NOTE_APPLY_EXCEEDS_OPEN');
            }

            $ids = array_keys($seen);
            sort($ids);
            $invoices = PurchaseInvoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($invoices->count() !== count($ids)) {
                $this->fail('An invoice does not match this debit note.', 'PURCHASE_DEBIT_NOTE_INVOICE_MISMATCH');
            }

            foreach ($payload as $row) {
                $invoice = $invoices->get($row['purchase_invoice_id']);
                if (! $invoice instanceof PurchaseInvoice) {
                    $this->fail('An invoice does not match this debit note.', 'PURCHASE_DEBIT_NOTE_INVOICE_MISMATCH');
                }
                $this->assertInvoiceAccepts($locked, $invoice, $row['amount']);
                $this->applyToInvoice($locked, $invoice, $row['amount'], false, $userId);
                $this->journalPostingService->debitApplication($locked, $invoice, $row['amount'], $userId);
            }

            return $this->find($locked->id);
        });
    }

    public function reverse(PurchaseDebitNote $note, ?string $userId): PurchaseDebitNote
    {
        return DB::transaction(function () use ($note, $userId): PurchaseDebitNote {
            $locked = PurchaseDebitNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseDebitNoteStatus::Posted) {
                $this->fail('Only a posted debit note can be reversed.', 'PURCHASE_DEBIT_NOTE_NOT_POSTED');
            }
            if (PaymentAllocation::isPositive($locked->refunded_total)) {
                $this->fail('Reverse the refund before reversing this debit note.', 'PURCHASE_DEBIT_NOTE_HAS_REFUNDS');
            }

            $invoice = PurchaseInvoice::query()->findOrFail($locked->purchase_invoice_id);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);

            $allocations = $locked->allocations()->orderBy('id')->get();
            $invoiceIds = $allocations->pluck('purchase_invoice_id')->map(fn ($id): string => (string) $id)->all();
            sort($invoiceIds);
            $invoices = PurchaseInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $target = $invoices->get($allocation->purchase_invoice_id);
                if (! $target instanceof PurchaseInvoice) {
                    $this->fail('An allocated invoice is no longer available.', 'PURCHASE_DEBIT_NOTE_INVOICE_MISMATCH');
                }
                $credited = PaymentAllocation::subtract($target->debited_total, $allocation->amount);
                if ($credited === null) {
                    $this->fail('This debit note cannot be reversed because an invoice credited total is too low.', 'PURCHASE_DEBIT_NOTE_AMOUNT_EXCEEDED');
                }
                $target->update([
                    'debited_total' => $credited,
                    'net_to_pay' => PaymentAllocation::netToPay($target->grand_total, $target->paid_total, $credited),
                ]);
            }

            $this->stockMovementService->reverseReference(PurchaseDebitNote::REFERENCE_TYPE, (string) $locked->id, $userId);

            $supplier = PurchaseInvoiceRules::assertSupplier((string) $locked->supplier_id);
            $this->supplierLedgerService->postEntry(
                $supplier,
                (int) $locked->currency_id,
                '0',
                (string) $locked->grand_total,
                LedgerReferenceType::DebitNote,
                (string) $locked->id,
                now()->toDateString(),
            );

            $this->journalPostingService->reverseDebitApplications((string) $locked->id, $userId);
            $this->journalPostingService->reverse(PurchaseDebitNote::REFERENCE_TYPE, (string) $locked->id, $userId);

            $locked->update(['status' => PurchaseDebitNoteStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceLines(PurchaseDebitNote $note, array $rows): void
    {
        $invoice = $this->postedInvoice((string) $note->purchase_invoice_id);
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        $invoice->load('lines.goodsReceiptLine');
        $usage = $this->postedUsage((string) $invoice->id);
        $sources = $invoice->lines->keyBy('id');
        $seen = [];
        $payload = [];

        foreach (array_values($rows) as $row) {
            $lineId = (int) ($row['purchase_invoice_line_id'] ?? 0);
            if ($lineId <= 0 || isset($seen[$lineId])) {
                $this->fail('Each invoice line can appear only once.', 'PURCHASE_DEBIT_NOTE_LINE_MISMATCH');
            }
            $seen[$lineId] = true;
            $source = $sources->get($lineId);
            if (! $source instanceof PurchaseInvoiceLine) {
                $this->fail('A line does not belong to this invoice.', 'PURCHASE_DEBIT_NOTE_LINE_MISMATCH');
            }
            $built = $this->buildLine($note, $source, $row, $usage['lines'][$lineId] ?? $this->emptyUsage());
            if ($built === null) {
                continue;
            }
            $payload[] = $built;
        }

        $note->lines()->delete();
        foreach ($payload as $index => $line) {
            $line['sort_order'] = $index + 1;
            PurchaseDebitNoteLine::query()->create($line);
        }
        $note->refresh();
        $this->writeTotals($note);
        $this->assertLimits($note, $invoice, $usage);
    }

    private function recomputeStoredLines(PurchaseDebitNote $note, PurchaseInvoice $invoice): void
    {
        $usage = $this->postedUsage((string) $invoice->id);
        $sources = $invoice->lines->keyBy('id');
        foreach ($note->lines()->orderBy('id')->get() as $line) {
            $source = $sources->get($line->purchase_invoice_line_id);
            if (! $source instanceof PurchaseInvoiceLine) {
                $this->fail('A line does not belong to this invoice.', 'PURCHASE_DEBIT_NOTE_LINE_MISMATCH');
            }
            $amounts = $this->moneyFor($note->type, $source, (string) $line->base_quantity, (string) $line->line_total, $usage['lines'][(int) $source->id] ?? $this->emptyUsage());
            $line->update($amounts);
        }
        $note->refresh();
        $this->writeTotals($note);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $used
     * @return array<string, mixed>|null
     */
    private function buildLine(PurchaseDebitNote $note, PurchaseInvoiceLine $source, array $row, array $used): ?array
    {
        $factor = ProportionalCredit::qty($source->conversion_factor);
        if (bccomp($factor, '0', 6) <= 0) {
            $factor = ProportionalCredit::qty(1);
        }

        if ($note->type === PurchaseDebitNoteType::Allowance) {
            $gross = ProportionalCredit::money($row['line_total'] ?? 0);
            if (! PaymentAllocation::isPositive($gross)) {
                return null;
            }
            $base = ProportionalCredit::qty(0);
            $quantity = ProportionalCredit::qty(0);
        } else {
            $quantity = ProportionalCredit::qty($row['quantity'] ?? 0);
            $base = ProportionalCredit::qty(bcmul($quantity, $factor, 8));
            if (bccomp($base, '0', 6) <= 0) {
                return null;
            }
            $remainingQty = bcsub(ProportionalCredit::qty($source->base_quantity), $used['base_quantity'], 6);
            if (bccomp($base, $remainingQty, 6) > 0) {
                $this->fail('Returned quantity exceeds what this invoice line still has.', 'PURCHASE_DEBIT_NOTE_QTY_EXCEEDED');
            }
            $gross = '0';
        }

        $amounts = $this->moneyFor($note->type, $source, $base, $gross, $used);
        if (bccomp(bcadd($used['line_total'], $amounts['line_total'], 4), ProportionalCredit::money($source->line_total), 4) > 0) {
            $this->fail('Credited amount exceeds what this invoice line still has.', 'PURCHASE_DEBIT_NOTE_AMOUNT_EXCEEDED');
        }

        $warehouseId = isset($row['warehouse_id']) && $row['warehouse_id'] !== null && $row['warehouse_id'] !== ''
            ? (int) $row['warehouse_id']
            : ($source->warehouse_id !== null ? (int) $source->warehouse_id : null);
        if ($warehouseId !== null) {
            $this->warehouseService->assertVisibleById($warehouseId);
        }

        return [
            'purchase_debit_note_id' => $note->id,
            'purchase_invoice_line_id' => $source->id,
            'item_id' => $source->item_id,
            'item_uom_id' => $source->item_uom_id,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
            'base_quantity' => $base,
            'conversion_factor' => $factor,
            'unit_price' => $source->unit_price,
            'discount_percent' => $source->discount_percent,
            'tax_rate' => $source->tax_rate,
            'lot_id' => $source->lot_id ?? $source->goodsReceiptLine?->lot_id,
            'description' => $this->nullableString($row['description'] ?? $source->description),
            ...$amounts,
        ];
    }

    /**
     * @param  array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $used
     * @return array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    private function moneyFor(PurchaseDebitNoteType $type, PurchaseInvoiceLine $source, string $base, string $gross, array $used): array
    {
        $invoiceMoney = [
            'line_subtotal' => (string) $source->line_subtotal,
            'discount_amount' => (string) $source->discount_amount,
            'tax_amount' => (string) $source->tax_amount,
            'line_total' => (string) $source->line_total,
        ];
        $usedMoney = [
            'line_subtotal' => $used['line_subtotal'],
            'discount_amount' => $used['discount_amount'],
            'tax_amount' => $used['tax_amount'],
            'line_total' => $used['line_total'],
        ];

        if ($type === PurchaseDebitNoteType::Allowance) {
            return ProportionalCredit::forAllowance($invoiceMoney, $gross, $usedMoney);
        }

        return ProportionalCredit::forReturn($invoiceMoney, (string) $source->base_quantity, $base, $used['base_quantity'], $usedMoney);
    }

    /**
     * @param  array{grand:string,lines:array<int, array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}>}  $usage
     */
    private function assertLimits(PurchaseDebitNote $note, PurchaseInvoice $invoice, array $usage): void
    {
        $note->load('lines.item');
        $sources = $invoice->lines->keyBy('id');
        foreach ($note->lines as $line) {
            $source = $sources->get($line->purchase_invoice_line_id);
            if (! $source instanceof PurchaseInvoiceLine) {
                $this->fail('A line does not belong to this invoice.', 'PURCHASE_DEBIT_NOTE_LINE_MISMATCH');
            }
            $used = $usage['lines'][(int) $source->id] ?? $this->emptyUsage();
            if ($note->type === PurchaseDebitNoteType::Return
                && bccomp(ProportionalCredit::qty($line->base_quantity), '0', 6) > 0
                && $line->item !== null
                && ! $line->item->allow_return) {
                $this->fail('This item cannot be returned.', 'PURCHASE_DEBIT_NOTE_ITEM_NOT_RETURNABLE');
            }
            if ($note->type === PurchaseDebitNoteType::Return) {
                $nextQty = bcadd($used['base_quantity'], ProportionalCredit::qty($line->base_quantity), 6);
                if (bccomp($nextQty, ProportionalCredit::qty($source->base_quantity), 6) > 0) {
                    $this->fail('Returned quantity exceeds what this invoice line still has.', 'PURCHASE_DEBIT_NOTE_QTY_EXCEEDED');
                }
            }
            $nextAmount = bcadd($used['line_total'], ProportionalCredit::money($line->line_total), 4);
            if (bccomp($nextAmount, ProportionalCredit::money($source->line_total), 4) > 0) {
                $this->fail('Credited amount exceeds what this invoice line still has.', 'PURCHASE_DEBIT_NOTE_AMOUNT_EXCEEDED');
            }
        }

        $nextGrand = bcadd($usage['grand'], ProportionalCredit::money($note->grand_total), 4);
        if (bccomp($nextGrand, ProportionalCredit::money($invoice->grand_total), 4) > 0) {
            $this->fail('This debit note exceeds the invoice total.', 'PURCHASE_DEBIT_NOTE_EXCEEDS_INVOICE');
        }
    }

    private function writeTotals(PurchaseDebitNote $note): void
    {
        $subtotal = '0';
        $discount = '0';
        $tax = '0';
        $linesTotal = '0';
        foreach ($note->lines()->get() as $line) {
            $subtotal = bcadd($subtotal, bcadd((string) $line->line_subtotal, (string) $line->discount_amount, 4), 4);
            $discount = bcadd($discount, (string) $line->discount_amount, 4);
            $tax = bcadd($tax, (string) $line->tax_amount, 4);
            $linesTotal = bcadd($linesTotal, (string) $line->line_total, 4);
        }
        $adjustment = ProportionalCredit::money($note->adjustment);
        $grand = ProportionalCredit::money(bcadd($linesTotal, $adjustment, 4));
        if (bccomp($grand, '0', 4) < 0) {
            $this->fail('Debit note total cannot be negative.', 'PURCHASE_DEBIT_NOTE_TOTAL_INVALID');
        }

        $note->update([
            'subtotal' => ProportionalCredit::money($subtotal),
            'discount_total' => ProportionalCredit::money($discount),
            'tax_total' => ProportionalCredit::money($tax),
            'adjustment' => $adjustment,
            'grand_total' => $grand,
        ]);
    }

    private function applyToInvoice(PurchaseDebitNote $note, PurchaseInvoice $invoice, string $amount, bool $automatic, ?string $userId): void
    {
        $amount = ProportionalCredit::money($amount);
        $open = ProportionalCredit::money($invoice->net_to_pay);
        if (bccomp($amount, $open, 4) > 0) {
            $amount = $open;
        }
        if (! PaymentAllocation::isPositive($amount)) {
            $note->update([
                'open_credit' => ProportionalCredit::money(bcsub((string) $note->grand_total, bcadd((string) $note->applied_total, (string) $note->refunded_total, 4), 4)),
            ]);

            return;
        }

        $existing = PurchaseDebitNoteAllocation::query()
            ->where('purchase_debit_note_id', $note->id)
            ->where('purchase_invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        if ($existing instanceof PurchaseDebitNoteAllocation) {
            $existing->update(['amount' => PaymentAllocation::add($existing->amount, $amount)]);
        } else {
            PurchaseDebitNoteAllocation::query()->create([
                'purchase_debit_note_id' => $note->id,
                'purchase_invoice_id' => $invoice->id,
                'amount' => $amount,
                'is_automatic' => $automatic,
                'created_by' => $userId,
            ]);
        }

        $credited = PaymentAllocation::add($invoice->debited_total, $amount);
        $invoice->update([
            'debited_total' => $credited,
            'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $invoice->paid_total, $credited),
        ]);

        $applied = PaymentAllocation::add($note->applied_total, $amount);
        $openCredit = bcsub((string) $note->grand_total, bcadd($applied, (string) $note->refunded_total, 4), 4);
        if (bccomp($openCredit, '0', 4) < 0) {
            $this->fail('Allocations exceed the open credit.', 'PURCHASE_DEBIT_NOTE_APPLY_EXCEEDS_OPEN');
        }
        $note->update([
            'applied_total' => $applied,
            'open_credit' => ProportionalCredit::money($openCredit),
        ]);
    }

    private function assertInvoiceAccepts(PurchaseDebitNote $note, PurchaseInvoice $invoice, string $amount): void
    {
        if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
            $this->fail('Only a posted invoice can receive credit.', 'PURCHASE_DEBIT_NOTE_INVOICE_NOT_POSTED');
        }
        if ((string) $invoice->supplier_id !== (string) $note->supplier_id || (int) $invoice->currency_id !== (int) $note->currency_id) {
            $this->fail('Invoice customer or currency does not match this debit note.', 'PURCHASE_DEBIT_NOTE_INVOICE_MISMATCH');
        }
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        if (! PaymentAllocation::isPositive($amount) || PaymentAllocation::exceeds($amount, $invoice->net_to_pay)) {
            $this->fail('Allocation exceeds the invoice open amount.', 'PURCHASE_DEBIT_NOTE_INVOICE_NOT_OPEN');
        }
    }

    private function postReturnStock(PurchaseDebitNote $note, PurchaseDebitNoteLine $line, ?string $userId): void
    {
        $item = $line->item ?? Item::query()->with('itemType')->findOrFail($line->item_id);
        $warehouseId = (int) ($line->warehouse_id ?? 0);
        if ($this->isBundle($item)) {
            if ($warehouseId <= 0) {
                $this->fail('A warehouse is required to return this line.', 'PURCHASE_DEBIT_NOTE_WAREHOUSE_REQUIRED');
            }
            $this->warehouseService->assertVisibleById($warehouseId);
            $this->postBundleReturn($note, $line, $item, $warehouseId, $userId);

            return;
        }
        if (! $item->track_inventory) {
            return;
        }
        if ($warehouseId <= 0) {
            $this->fail('A warehouse is required to return this line.', 'PURCHASE_DEBIT_NOTE_WAREHOUSE_REQUIRED');
        }
        $this->warehouseService->assertVisibleById($warehouseId);

        $this->stockMovementService->post(StockMovementData::forPurchaseReturn(
            itemId: (string) $line->item_id,
            warehouseId: $warehouseId,
            quantityDelta: bcmul((string) $line->base_quantity, '-1', 6),
            purchaseDebitNoteId: (string) $note->id,
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: 'PDN '.$note->debit_note_number,
            userId: $userId,
            lotId: $line->lot_id ? (int) $line->lot_id : null,
        ));
    }

    private function postBundleReturn(PurchaseDebitNote $note, PurchaseDebitNoteLine $line, Item $bundle, int $warehouseId, ?string $userId): void
    {
        $components = BundleItem::query()->where('bundle_item_id', $bundle->id)->with('childItem.itemType')->get();
        if ($components->isEmpty()) {
            $this->fail('Bundle has no components.', 'PURCHASE_DEBIT_NOTE_BUNDLE_EMPTY');
        }
        foreach ($components as $component) {
            $child = $component->childItem;
            if (! $child instanceof Item || ! $child->track_inventory) {
                continue;
            }
            if ($child->track_lots) {
                $this->fail('Lot-tracked bundle components cannot be returned on a debit note.', 'PURCHASE_DEBIT_NOTE_BUNDLE_LOT_NOT_SUPPORTED');
            }
            $baseQty = bcmul((string) $line->base_quantity, (string) $component->quantity, 6);
            $this->stockMovementService->post(StockMovementData::forPurchaseReturn(
                itemId: (string) $child->id,
                warehouseId: $warehouseId,
                quantityDelta: bcmul($baseQty, '-1', 6),
                purchaseDebitNoteId: (string) $note->id,
                itemUomId: null,
                notes: 'PDN '.$note->debit_note_number.' bundle '.$bundle->item_code,
                userId: $userId,
            ));
        }
    }

    private function isBundle(Item $item): bool
    {
        $item->loadMissing('itemType:id,code');

        return $item->itemType !== null && strtoupper((string) $item->itemType->code) === 'BUNDLE';
    }

    /**
     * @return array{grand:string,lines:array<int, array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}>}
     */
    private function postedUsage(string $invoiceId): array
    {
        $notes = PurchaseDebitNote::query()
            ->where('purchase_invoice_id', $invoiceId)
            ->where('status', PurchaseDebitNoteStatus::Posted->value)
            ->with('lines')
            ->get();

        $grand = '0';
        $lines = [];
        foreach ($notes as $note) {
            $grand = bcadd($grand, ProportionalCredit::money($note->grand_total), 4);
            foreach ($note->lines as $line) {
                $id = (int) $line->purchase_invoice_line_id;
                $slot = $lines[$id] ?? $this->emptyUsage();
                $lines[$id] = [
                    'base_quantity' => bcadd($slot['base_quantity'], ProportionalCredit::qty($line->base_quantity), 6),
                    'line_subtotal' => bcadd($slot['line_subtotal'], ProportionalCredit::money($line->line_subtotal), 4),
                    'discount_amount' => bcadd($slot['discount_amount'], ProportionalCredit::money($line->discount_amount), 4),
                    'tax_amount' => bcadd($slot['tax_amount'], ProportionalCredit::money($line->tax_amount), 4),
                    'line_total' => bcadd($slot['line_total'], ProportionalCredit::money($line->line_total), 4),
                ];
            }
        }

        return ['grand' => ProportionalCredit::money($grand), 'lines' => $lines];
    }

    /**
     * @return array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    private function emptyUsage(): array
    {
        return [
            'base_quantity' => ProportionalCredit::qty(0),
            ...ProportionalCredit::zero(),
        ];
    }

    private function postedInvoice(string $id): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::query()->find($id);
        if (! $invoice instanceof PurchaseInvoice || $invoice->status !== PurchaseInvoiceStatus::Posted) {
            $this->fail('Choose a posted purchase invoice.', 'PURCHASE_DEBIT_NOTE_INVOICE_NOT_POSTED');
        }

        return $invoice;
    }

    private function assertDate(PurchaseInvoice $invoice, string $date): void
    {
        if ($date < $invoice->invoice_date?->toDateString()) {
            $this->fail('Debit date cannot be before the invoice date.', 'PURCHASE_DEBIT_NOTE_DATE_BEFORE_INVOICE');
        }
    }

    private function type(mixed $value): PurchaseDebitNoteType
    {
        $type = PurchaseDebitNoteType::tryFrom((string) $value);
        if ($type === null) {
            $this->fail('Debit note type must be return or allowance.', 'PURCHASE_DEBIT_NOTE_TYPE_INVALID');
        }

        return $type;
    }

    private function lockDraft(PurchaseDebitNote $note): PurchaseDebitNote
    {
        $locked = PurchaseDebitNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== PurchaseDebitNoteStatus::Draft) {
            $this->fail('Only a draft debit note can be changed.', 'PURCHASE_DEBIT_NOTE_NOT_DRAFT');
        }

        return $locked;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function fail(string $message, string $code): never
    {
        abort(422, $message, ['X-Error-Code' => $code]);
    }
}
