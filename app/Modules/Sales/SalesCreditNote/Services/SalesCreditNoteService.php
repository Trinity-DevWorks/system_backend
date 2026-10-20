<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\Stock\Support\InventoryCostingMath;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteStatus;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteType;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNoteAllocation;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNoteLine;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ListPagination;
use App\Support\PaymentAllocation;
use App\Support\ProportionalCredit;
use App\Support\SequentialCodeGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesCreditNoteService
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SalesCreditNote>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = SalesCreditNote::query()
            ->with([
                'customer:id,customer_code,name,phone,status,is_system',
                'currency:id,code,name,symbol',
                'salesInvoice:id,invoice_number,invoice_date,status',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('lines');

        if (! empty($filters['status'])) {
            $status = SalesCreditNoteStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }
        if (! empty($filters['type'])) {
            $type = SalesCreditNoteType::tryFrom((string) $filters['type']);
            if ($type) {
                $query->where('type', $type->value);
            }
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }
        if (! empty($filters['sales_invoice_id'])) {
            $query->where('sales_invoice_id', (string) $filters['sales_invoice_id']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['credit_note_number']);
        if (! empty($filters['from'])) {
            $query->where('credit_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('credit_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function find(string $id): SalesCreditNote
    {
        return SalesCreditNote::query()
            ->with([
                'customer',
                'currency',
                'salesInvoice.currency',
                'createdByUser',
                'postedByUser',
                'lines.item',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
                'allocations.salesInvoice',
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
                'sales_invoice_line_id' => $line->id,
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
                'credited_total' => $used['line_total'],
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
    public function applicableInvoices(SalesCreditNote $note): array
    {
        if ($note->status !== SalesCreditNoteStatus::Posted) {
            return [];
        }

        $query = SalesInvoice::query()
            ->with('currency:id,code,name,symbol')
            ->where('customer_id', $note->customer_id)
            ->where('currency_id', $note->currency_id)
            ->where('status', SalesInvoiceStatus::Posted->value)
            ->where('net_to_pay', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('invoice_number');
        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        return $query->get()->map(fn (SalesInvoice $invoice): array => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'grand_total' => (string) $invoice->grand_total,
            'paid_total' => (string) $invoice->paid_total,
            'credited_total' => (string) $invoice->credited_total,
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
    public function create(array $data, ?string $userId): SalesCreditNote
    {
        $invoice = $this->postedInvoice((string) $data['sales_invoice_id']);
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        $this->assertDate($invoice, (string) $data['credit_date']);

        return DB::transaction(function () use ($data, $userId, $invoice): SalesCreditNote {
            $note = SalesCreditNote::query()->create([
                'credit_note_number' => SequentialCodeGenerator::next(SalesCreditNote::class, 'credit_note_number', 'SCN-'),
                'sales_invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'type' => $this->type($data['type'])->value,
                'credit_date' => Carbon::parse((string) $data['credit_date'])->toDateString(),
                'reason' => trim((string) $data['reason']),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'adjustment' => ProportionalCredit::money($data['adjustment'] ?? 0),
                'status' => SalesCreditNoteStatus::Draft,
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
    public function updateHeader(SalesCreditNote $note, array $data): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $data): SalesCreditNote {
            $locked = $this->lockDraft($note);
            $invoice = $this->postedInvoice((string) $locked->sales_invoice_id);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $this->assertDate($invoice, (string) $data['credit_date']);
            $type = $this->type($data['type']);
            $typeChanged = $type !== $locked->type;

            $locked->update([
                'type' => $type->value,
                'credit_date' => Carbon::parse((string) $data['credit_date'])->toDateString(),
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
    public function syncLines(SalesCreditNote $note, array $lines): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $lines): SalesCreditNote {
            $locked = $this->lockDraft($note);
            $this->replaceLines($locked, $lines);

            return $this->find($locked->id);
        });
    }

    public function delete(SalesCreditNote $note): void
    {
        DB::transaction(function () use ($note): void {
            $locked = $this->lockDraft($note);
            $invoice = SalesInvoice::query()->findOrFail($locked->sales_invoice_id);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $locked->delete();
        });
    }

    public function post(SalesCreditNote $note, ?string $userId): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $userId): SalesCreditNote {
            $locked = SalesCreditNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesCreditNoteStatus::Draft) {
                $this->fail('Only a draft credit note can be posted.', 'SALES_CREDIT_NOTE_NOT_DRAFT');
            }

            $invoice = SalesInvoice::query()->whereKey($locked->sales_invoice_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== SalesInvoiceStatus::Posted) {
                $this->fail('Credit notes can only be posted against a posted invoice.', 'SALES_CREDIT_NOTE_INVOICE_NOT_POSTED');
            }
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $this->assertDate($invoice, $locked->credit_date->toDateString());

            SalesInvoiceLine::query()
                ->where('sales_invoice_id', $invoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $invoice->load('lines.item.itemType');
            $this->recomputeStoredLines($locked, $invoice);
            $locked->refresh();
            $locked->load('lines.item.itemType');

            if ($locked->lines->isEmpty() || ! PaymentAllocation::isPositive($locked->grand_total)) {
                $this->fail('Add at least one line with a quantity or an amount.', 'SALES_CREDIT_NOTE_NO_LINES');
            }

            $usage = $this->postedUsage((string) $invoice->id);
            $this->assertLimits($locked, $invoice, $usage);

            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
            if ($locked->type === SalesCreditNoteType::Return) {
                foreach ($locked->lines as $line) {
                    $this->postReturnStock($locked, $line, $userId);
                }
            }

            $this->customerLedgerService->postEntry(
                $customer,
                (int) $locked->currency_id,
                '0',
                (string) $locked->grand_total,
                LedgerReferenceType::CreditNote,
                (string) $locked->id,
                $locked->credit_date->toDateString(),
            );

            $this->applyToInvoice($locked, $invoice, (string) $locked->grand_total, true, $userId);
            $locked->refresh();

            $this->journalPostingService->salesCreditNote($locked, $userId);
            foreach ($locked->allocations()->get() as $allocation) {
                $target = SalesInvoice::query()->find($allocation->sales_invoice_id);
                if ($target instanceof SalesInvoice) {
                    $this->journalPostingService->creditApplication($locked, $target, (string) $allocation->amount, $userId);
                }
            }

            $locked->update([
                'status' => SalesCreditNoteStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function apply(SalesCreditNote $note, array $rows, ?string $userId): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $rows, $userId): SalesCreditNote {
            $locked = SalesCreditNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesCreditNoteStatus::Posted) {
                $this->fail('Only a posted credit note can be applied.', 'SALES_CREDIT_NOTE_NOT_POSTED');
            }

            $payload = [];
            $seen = [];
            foreach ($rows as $row) {
                $invoiceId = (string) ($row['sales_invoice_id'] ?? '');
                if ($invoiceId === '' || isset($seen[$invoiceId])) {
                    $this->fail('Each invoice can be selected once.', 'SALES_CREDIT_NOTE_INVOICE_MISMATCH');
                }
                $seen[$invoiceId] = true;
                $amount = ProportionalCredit::money($row['amount'] ?? 0);
                if (! PaymentAllocation::isPositive($amount)) {
                    $this->fail('Each allocation must be greater than zero.', 'SALES_CREDIT_NOTE_APPLY_EXCEEDS_OPEN');
                }
                $payload[] = ['sales_invoice_id' => $invoiceId, 'amount' => $amount];
            }

            $total = PaymentAllocation::sum(array_column($payload, 'amount'));
            if (PaymentAllocation::exceeds($total, $locked->open_credit)) {
                $this->fail('Allocations exceed the open credit.', 'SALES_CREDIT_NOTE_APPLY_EXCEEDS_OPEN');
            }

            $ids = array_keys($seen);
            sort($ids);
            $invoices = SalesInvoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($invoices->count() !== count($ids)) {
                $this->fail('An invoice does not match this credit note.', 'SALES_CREDIT_NOTE_INVOICE_MISMATCH');
            }

            foreach ($payload as $row) {
                $invoice = $invoices->get($row['sales_invoice_id']);
                if (! $invoice instanceof SalesInvoice) {
                    $this->fail('An invoice does not match this credit note.', 'SALES_CREDIT_NOTE_INVOICE_MISMATCH');
                }
                $this->assertInvoiceAccepts($locked, $invoice, $row['amount']);
                $this->applyToInvoice($locked, $invoice, $row['amount'], false, $userId);
                $this->journalPostingService->creditApplication($locked, $invoice, $row['amount'], $userId);
            }

            return $this->find($locked->id);
        });
    }

    public function reverse(SalesCreditNote $note, ?string $userId): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $userId): SalesCreditNote {
            $locked = SalesCreditNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesCreditNoteStatus::Posted) {
                $this->fail('Only a posted credit note can be reversed.', 'SALES_CREDIT_NOTE_NOT_POSTED');
            }
            if (PaymentAllocation::isPositive($locked->refunded_total)) {
                $this->fail('Reverse the refund before reversing this credit note.', 'SALES_CREDIT_NOTE_HAS_REFUNDS');
            }

            $invoice = SalesInvoice::query()->findOrFail($locked->sales_invoice_id);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);

            $allocations = $locked->allocations()->orderBy('id')->get();
            $invoiceIds = $allocations->pluck('sales_invoice_id')->map(fn ($id): string => (string) $id)->all();
            sort($invoiceIds);
            $invoices = SalesInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $target = $invoices->get($allocation->sales_invoice_id);
                if (! $target instanceof SalesInvoice) {
                    $this->fail('An allocated invoice is no longer available.', 'SALES_CREDIT_NOTE_INVOICE_MISMATCH');
                }
                $credited = PaymentAllocation::subtract($target->credited_total, $allocation->amount);
                if ($credited === null) {
                    $this->fail('This credit note cannot be reversed because an invoice credited total is too low.', 'SALES_CREDIT_NOTE_AMOUNT_EXCEEDED');
                }
                $target->update([
                    'credited_total' => $credited,
                    'net_to_pay' => PaymentAllocation::netToPay($target->grand_total, $target->paid_total, $credited),
                ]);
            }

            $this->stockMovementService->reverseReference(SalesCreditNote::REFERENCE_TYPE, (string) $locked->id, $userId);

            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
            $this->customerLedgerService->postEntry(
                $customer,
                (int) $locked->currency_id,
                (string) $locked->grand_total,
                '0',
                LedgerReferenceType::CreditNote,
                (string) $locked->id,
                now()->toDateString(),
            );

            $this->journalPostingService->reverseCreditApplications((string) $locked->id, $userId);
            $this->journalPostingService->reverse(SalesCreditNote::REFERENCE_TYPE, (string) $locked->id, $userId);

            $locked->update(['status' => SalesCreditNoteStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceLines(SalesCreditNote $note, array $rows): void
    {
        $invoice = $this->postedInvoice((string) $note->sales_invoice_id);
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        $invoice->load('lines');
        $usage = $this->postedUsage((string) $invoice->id);
        $sources = $invoice->lines->keyBy('id');
        $seen = [];
        $payload = [];

        foreach (array_values($rows) as $row) {
            $lineId = (int) ($row['sales_invoice_line_id'] ?? 0);
            if ($lineId <= 0 || isset($seen[$lineId])) {
                $this->fail('Each invoice line can appear only once.', 'SALES_CREDIT_NOTE_LINE_MISMATCH');
            }
            $seen[$lineId] = true;
            $source = $sources->get($lineId);
            if (! $source instanceof SalesInvoiceLine) {
                $this->fail('A line does not belong to this invoice.', 'SALES_CREDIT_NOTE_LINE_MISMATCH');
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
            SalesCreditNoteLine::query()->create($line);
        }
        $note->refresh();
        $this->writeTotals($note);
        $this->assertLimits($note, $invoice, $usage);
    }

    private function recomputeStoredLines(SalesCreditNote $note, SalesInvoice $invoice): void
    {
        $usage = $this->postedUsage((string) $invoice->id);
        $sources = $invoice->lines->keyBy('id');
        foreach ($note->lines()->orderBy('id')->get() as $line) {
            $source = $sources->get($line->sales_invoice_line_id);
            if (! $source instanceof SalesInvoiceLine) {
                $this->fail('A line does not belong to this invoice.', 'SALES_CREDIT_NOTE_LINE_MISMATCH');
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
    private function buildLine(SalesCreditNote $note, SalesInvoiceLine $source, array $row, array $used): ?array
    {
        $factor = ProportionalCredit::qty($source->conversion_factor);
        if (bccomp($factor, '0', 6) <= 0) {
            $factor = ProportionalCredit::qty(1);
        }

        if ($note->type === SalesCreditNoteType::Allowance) {
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
                $this->fail('Returned quantity exceeds what this invoice line still has.', 'SALES_CREDIT_NOTE_QTY_EXCEEDED');
            }
            $gross = '0';
        }

        $amounts = $this->moneyFor($note->type, $source, $base, $gross, $used);
        if (bccomp(bcadd($used['line_total'], $amounts['line_total'], 4), ProportionalCredit::money($source->line_total), 4) > 0) {
            $this->fail('Credited amount exceeds what this invoice line still has.', 'SALES_CREDIT_NOTE_AMOUNT_EXCEEDED');
        }

        $warehouseId = isset($row['warehouse_id']) && $row['warehouse_id'] !== null && $row['warehouse_id'] !== ''
            ? (int) $row['warehouse_id']
            : ($source->warehouse_id !== null ? (int) $source->warehouse_id : null);
        if ($warehouseId !== null) {
            $this->warehouseService->assertVisibleById($warehouseId);
        }

        return [
            'sales_credit_note_id' => $note->id,
            'sales_invoice_line_id' => $source->id,
            'item_id' => $source->item_id,
            'item_uom_id' => $source->item_uom_id,
            'warehouse_id' => $warehouseId,
            'lot_id' => $source->lot_id,
            'quantity' => $quantity,
            'base_quantity' => $base,
            'conversion_factor' => $factor,
            'unit_price' => $source->unit_price,
            'discount_percent' => $source->discount_percent,
            'tax_rate' => $source->tax_rate,
            'restock' => $note->type === SalesCreditNoteType::Return ? (bool) ($row['restock'] ?? true) : false,
            'description' => $this->nullableString($row['description'] ?? $source->description),
            ...$amounts,
        ];
    }

    /**
     * @param  array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}  $used
     * @return array{line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}
     */
    private function moneyFor(SalesCreditNoteType $type, SalesInvoiceLine $source, string $base, string $gross, array $used): array
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

        if ($type === SalesCreditNoteType::Allowance) {
            return ProportionalCredit::forAllowance($invoiceMoney, $gross, $usedMoney);
        }

        return ProportionalCredit::forReturn($invoiceMoney, (string) $source->base_quantity, $base, $used['base_quantity'], $usedMoney);
    }

    /**
     * @param  array{grand:string,lines:array<int, array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}>}  $usage
     */
    private function assertLimits(SalesCreditNote $note, SalesInvoice $invoice, array $usage): void
    {
        $note->load('lines.item');
        $sources = $invoice->lines->keyBy('id');
        foreach ($note->lines as $line) {
            $source = $sources->get($line->sales_invoice_line_id);
            if (! $source instanceof SalesInvoiceLine) {
                $this->fail('A line does not belong to this invoice.', 'SALES_CREDIT_NOTE_LINE_MISMATCH');
            }
            $used = $usage['lines'][(int) $source->id] ?? $this->emptyUsage();
            if ($note->type === SalesCreditNoteType::Return
                && bccomp(ProportionalCredit::qty($line->base_quantity), '0', 6) > 0
                && $line->item !== null
                && ! $line->item->allow_return) {
                $this->fail('This item cannot be returned.', 'SALES_CREDIT_NOTE_ITEM_NOT_RETURNABLE');
            }
            if ($note->type === SalesCreditNoteType::Return) {
                $nextQty = bcadd($used['base_quantity'], ProportionalCredit::qty($line->base_quantity), 6);
                if (bccomp($nextQty, ProportionalCredit::qty($source->base_quantity), 6) > 0) {
                    $this->fail('Returned quantity exceeds what this invoice line still has.', 'SALES_CREDIT_NOTE_QTY_EXCEEDED');
                }
            }
            $nextAmount = bcadd($used['line_total'], ProportionalCredit::money($line->line_total), 4);
            if (bccomp($nextAmount, ProportionalCredit::money($source->line_total), 4) > 0) {
                $this->fail('Credited amount exceeds what this invoice line still has.', 'SALES_CREDIT_NOTE_AMOUNT_EXCEEDED');
            }
        }

        $nextGrand = bcadd($usage['grand'], ProportionalCredit::money($note->grand_total), 4);
        if (bccomp($nextGrand, ProportionalCredit::money($invoice->grand_total), 4) > 0) {
            $this->fail('This credit note exceeds the invoice total.', 'SALES_CREDIT_NOTE_EXCEEDS_INVOICE');
        }
    }

    private function writeTotals(SalesCreditNote $note): void
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
            $this->fail('Credit note total cannot be negative.', 'SALES_CREDIT_NOTE_TOTAL_INVALID');
        }

        $note->update([
            'subtotal' => ProportionalCredit::money($subtotal),
            'discount_total' => ProportionalCredit::money($discount),
            'tax_total' => ProportionalCredit::money($tax),
            'adjustment' => $adjustment,
            'grand_total' => $grand,
        ]);
    }

    private function applyToInvoice(SalesCreditNote $note, SalesInvoice $invoice, string $amount, bool $automatic, ?string $userId): void
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

        $existing = SalesCreditNoteAllocation::query()
            ->where('sales_credit_note_id', $note->id)
            ->where('sales_invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        if ($existing instanceof SalesCreditNoteAllocation) {
            $existing->update(['amount' => PaymentAllocation::add($existing->amount, $amount)]);
        } else {
            SalesCreditNoteAllocation::query()->create([
                'sales_credit_note_id' => $note->id,
                'sales_invoice_id' => $invoice->id,
                'amount' => $amount,
                'is_automatic' => $automatic,
                'created_by' => $userId,
            ]);
        }

        $credited = PaymentAllocation::add($invoice->credited_total, $amount);
        $invoice->update([
            'credited_total' => $credited,
            'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $invoice->paid_total, $credited),
        ]);

        $applied = PaymentAllocation::add($note->applied_total, $amount);
        $openCredit = bcsub((string) $note->grand_total, bcadd($applied, (string) $note->refunded_total, 4), 4);
        if (bccomp($openCredit, '0', 4) < 0) {
            $this->fail('Allocations exceed the open credit.', 'SALES_CREDIT_NOTE_APPLY_EXCEEDS_OPEN');
        }
        $note->update([
            'applied_total' => $applied,
            'open_credit' => ProportionalCredit::money($openCredit),
        ]);
    }

    private function assertInvoiceAccepts(SalesCreditNote $note, SalesInvoice $invoice, string $amount): void
    {
        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            $this->fail('Only a posted invoice can receive credit.', 'SALES_CREDIT_NOTE_INVOICE_NOT_POSTED');
        }
        if ((string) $invoice->customer_id !== (string) $note->customer_id || (int) $invoice->currency_id !== (int) $note->currency_id) {
            $this->fail('Invoice customer or currency does not match this credit note.', 'SALES_CREDIT_NOTE_INVOICE_MISMATCH');
        }
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        if (! PaymentAllocation::isPositive($amount) || PaymentAllocation::exceeds($amount, $invoice->net_to_pay)) {
            $this->fail('Allocation exceeds the invoice open amount.', 'SALES_CREDIT_NOTE_INVOICE_NOT_OPEN');
        }
    }

    private function postReturnStock(SalesCreditNote $note, SalesCreditNoteLine $line, ?string $userId): void
    {
        if (! $line->restock) {
            return;
        }
        $item = $line->item ?? Item::query()->with('itemType')->findOrFail($line->item_id);
        $invoice = SalesInvoice::query()->findOrFail($note->sales_invoice_id);
        $warehouseId = (int) ($line->warehouse_id ?? 0);
        if ($warehouseId <= 0) {
            $this->fail('A warehouse is required to restock this line.', 'SALES_CREDIT_NOTE_WAREHOUSE_REQUIRED');
        }
        $this->warehouseService->assertVisibleById($warehouseId);

        if (SalesInvoiceRules::isBundleItem($item)) {
            $this->postBundleReturn($note, $line, $item, $warehouseId, $userId);

            return;
        }
        if (! $item->track_inventory) {
            return;
        }

        $lotId = $line->lot_id ? (int) $line->lot_id : null;
        $valuation = $this->returnValuation(
            (string) $note->id,
            (string) $invoice->id,
            (string) $line->item_id,
            $warehouseId,
            $lotId,
            StockMovementType::Sale,
            (string) $line->base_quantity,
        );
        $this->stockMovementService->post(StockMovementData::forSalesReturn(
            itemId: (string) $line->item_id,
            warehouseId: $warehouseId,
            quantityDelta: (string) $line->base_quantity,
            salesCreditNoteId: (string) $note->id,
            unitCost: $valuation['unit_cost'],
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: 'SCN '.$note->credit_note_number,
            userId: $userId,
            lotId: $lotId,
            valueDelta: $valuation['value'],
        ));
    }

    private function postBundleReturn(SalesCreditNote $note, SalesCreditNoteLine $line, Item $bundle, int $warehouseId, ?string $userId): void
    {
        $components = BundleItem::query()->where('bundle_item_id', $bundle->id)->with('childItem.itemType')->get();
        if ($components->isEmpty()) {
            $this->fail('Bundle has no components.', 'SALES_CREDIT_NOTE_BUNDLE_EMPTY');
        }
        $invoiceId = (string) $note->sales_invoice_id;
        foreach ($components as $component) {
            $child = $component->childItem;
            if (! $child instanceof Item || ! $child->track_inventory) {
                continue;
            }
            if ($child->track_lots) {
                $this->fail('Lot-tracked bundle components cannot be returned on a credit note.', 'SALES_CREDIT_NOTE_BUNDLE_LOT_NOT_SUPPORTED');
            }
            $baseQty = bcmul((string) $line->base_quantity, (string) $component->quantity, 6);
            $valuation = $this->returnValuation(
                (string) $note->id,
                $invoiceId,
                (string) $child->id,
                $warehouseId,
                null,
                StockMovementType::BundleSale,
                $baseQty,
            );
            $this->stockMovementService->post(StockMovementData::forSalesReturn(
                itemId: (string) $child->id,
                warehouseId: $warehouseId,
                quantityDelta: $baseQty,
                salesCreditNoteId: (string) $note->id,
                unitCost: $valuation['unit_cost'],
                itemUomId: null,
                notes: 'SCN '.$note->credit_note_number.' bundle '.$bundle->item_code,
                userId: $userId,
                valueDelta: $valuation['value'],
            ));
        }
    }

    /**
     * Cost put back into stock. A full return of what the invoice issued puts that value back.
     * A partial return takes a share, and the return that finishes the quantity takes what is left.
     *
     * @return array{unit_cost: ?string, value: ?string}
     */
    private function returnValuation(
        string $creditNoteId,
        string $invoiceId,
        string $itemId,
        int $warehouseId,
        ?int $lotId,
        StockMovementType $type,
        string $returnQty,
    ): array {
        $sold = $this->movementTotals(SalesInvoice::REFERENCE_TYPE, [$invoiceId], $itemId, $warehouseId, $lotId, $type, null);
        $soldQty = InventoryCostingMath::absQty($sold['qty']);
        $soldValue = InventoryCostingMath::moneyRound(
            bccomp($sold['value'], '0', 4) < 0 ? bcmul($sold['value'], '-1', 8) : $sold['value'],
        );
        if (bccomp($soldQty, '0', 6) === 0 || bccomp($soldValue, '0', 4) <= 0) {
            return ['unit_cost' => null, 'value' => null];
        }

        $noteIds = SalesCreditNote::query()
            ->where('sales_invoice_id', $invoiceId)
            ->where(function ($query) use ($creditNoteId): void {
                $query->where('status', SalesCreditNoteStatus::Posted->value)
                    ->orWhere('id', $creditNoteId);
            })
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $already = $this->movementTotals(
            SalesCreditNote::REFERENCE_TYPE,
            $noteIds,
            $itemId,
            $warehouseId,
            $lotId,
            StockMovementType::SalesReturn,
            $type === StockMovementType::BundleSale,
        );
        $remainingQty = bcsub($soldQty, InventoryCostingMath::absQty($already['qty']), 6);
        $remainingValue = bcsub($soldValue, InventoryCostingMath::moneyRound($already['value']), 4);
        if (bccomp($remainingValue, '0', 4) < 0) {
            $remainingValue = InventoryCostingMath::money(0);
        }

        $value = InventoryCostingMath::remainingSlice($remainingValue, $returnQty, $remainingQty);
        if (bccomp($value, '0', 4) <= 0) {
            return ['unit_cost' => null, 'value' => null];
        }

        return [
            'unit_cost' => InventoryCostingMath::unitCostFromValue(InventoryCostingMath::qty($returnQty), $value),
            'value' => $value,
        ];
    }

    /**
     * @param  list<string>  $referenceIds
     * @return array{qty: string, value: string}
     */
    private function movementTotals(
        string $referenceType,
        array $referenceIds,
        string $itemId,
        int $warehouseId,
        ?int $lotId,
        StockMovementType $type,
        ?bool $bundleReturn,
    ): array {
        if ($referenceIds === []) {
            return ['qty' => '0', 'value' => '0'];
        }

        $query = StockMovement::query()
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', $referenceIds)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('type', $type->value)
            ->where(function ($inner): void {
                $inner->whereNull('notes')->orWhere('notes', 'not like', 'Reverse%');
            });
        if ($bundleReturn === true) {
            $query->where('notes', 'like', '% bundle %');
        } elseif ($bundleReturn === false) {
            $query->where(function ($inner): void {
                $inner->whereNull('notes')->orWhere('notes', 'not like', '% bundle %');
            });
        }
        $lotId === null ? $query->whereNull('lot_id') : $query->where('lot_id', $lotId);

        $qty = '0';
        $value = '0';
        foreach ($query->get(['quantity_delta', 'value_delta']) as $movement) {
            $qty = bcadd($qty, (string) $movement->quantity_delta, 6);
            $value = bcadd($value, (string) $movement->value_delta, 4);
        }

        return ['qty' => $qty, 'value' => $value];
    }

    /**
     * @return array{grand:string,lines:array<int, array{base_quantity:string,line_subtotal:string,discount_amount:string,tax_amount:string,line_total:string}>}
     */
    private function postedUsage(string $invoiceId): array
    {
        $notes = SalesCreditNote::query()
            ->where('sales_invoice_id', $invoiceId)
            ->where('status', SalesCreditNoteStatus::Posted->value)
            ->with('lines')
            ->get();

        $grand = '0';
        $lines = [];
        foreach ($notes as $note) {
            $grand = bcadd($grand, ProportionalCredit::money($note->grand_total), 4);
            foreach ($note->lines as $line) {
                $id = (int) $line->sales_invoice_line_id;
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

    private function postedInvoice(string $id): SalesInvoice
    {
        $invoice = SalesInvoice::query()->find($id);
        if (! $invoice instanceof SalesInvoice || $invoice->status !== SalesInvoiceStatus::Posted) {
            $this->fail('Choose a posted sales invoice.', 'SALES_CREDIT_NOTE_INVOICE_NOT_POSTED');
        }

        return $invoice;
    }

    private function assertDate(SalesInvoice $invoice, string $date): void
    {
        if ($date < $invoice->invoice_date?->toDateString()) {
            $this->fail('Credit date cannot be before the invoice date.', 'SALES_CREDIT_NOTE_DATE_BEFORE_INVOICE');
        }
    }

    private function type(mixed $value): SalesCreditNoteType
    {
        $type = SalesCreditNoteType::tryFrom((string) $value);
        if ($type === null) {
            $this->fail('Credit note type must be return or allowance.', 'SALES_CREDIT_NOTE_TYPE_INVALID');
        }

        return $type;
    }

    private function lockDraft(SalesCreditNote $note): SalesCreditNote
    {
        $locked = SalesCreditNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== SalesCreditNoteStatus::Draft) {
            $this->fail('Only a draft credit note can be changed.', 'SALES_CREDIT_NOTE_NOT_DRAFT');
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
