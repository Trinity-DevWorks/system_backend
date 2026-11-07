<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostMethod;
use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostSourceType;
use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostStatus;
use App\Modules\Inventory\Purchasing\LandedCost\Models\LandedCost;
use App\Modules\Inventory\Purchasing\LandedCost\Models\LandedCostAllocation;
use App\Modules\Inventory\Purchasing\LandedCost\Models\LandedCostCharge;
use App\Modules\Inventory\Purchasing\LandedCost\Models\LandedCostReceipt;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\InventoryCostingService;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Models\ExpenseVoucherLine;
use App\Support\Decimal;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Landed cost documents: pick receipts and bill charges, spread the charges, post them onto stock.
 */
class LandedCostService
{
    public function __construct(
        private readonly LandedCostSources $sources,
        private readonly LandedCostAllocator $allocator,
        private readonly StockMovementService $stockMovements,
        private readonly InventoryCostingService $costing,
        private readonly JournalPostingService $journal,
    ) {}

    /**
     * @param  array{status?: string|null, search?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, LandedCost>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return LandedCost::query()
            ->withSum('charges as total_amount', 'amount')
            ->withCount('receipts')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('cost_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('cost_date', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('landed_cost_number', 'ilike', '%'.$v.'%')
                ->orWhere('reference', 'ilike', '%'.$v.'%')))
            ->orderByDesc('cost_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function find(string $id): LandedCost
    {
        return LandedCost::query()
            ->with(['receipts', 'charges.allocations', 'createdByUser:id,name', 'postedByUser:id,name'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): LandedCost
    {
        return DB::transaction(function () use ($data, $userId): LandedCost {
            $plan = $this->plan($data, null);
            $document = LandedCost::query()->create([
                'cost_date' => $data['cost_date'] ?? now()->toDateString(),
                'reference' => $this->nullable($data['reference'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
                'status' => LandedCostStatus::Draft,
                'created_by' => $userId,
            ]);
            $this->store($document, $plan);

            return $this->find((string) $document->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LandedCost $document, array $data): LandedCost
    {
        return DB::transaction(function () use ($document, $data): LandedCost {
            $locked = $this->lockDraft($document);
            $plan = $this->plan($data, (string) $locked->id);
            $locked->update([
                'cost_date' => $data['cost_date'] ?? $locked->cost_date->toDateString(),
                'reference' => $this->nullable($data['reference'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
            ]);
            $this->store($locked, $plan);

            return $this->find((string) $locked->id);
        });
    }

    public function delete(LandedCost $document): void
    {
        DB::transaction(fn () => $this->lockDraft($document)->delete());
    }

    /**
     * Unsaved allocation for the drawer.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(array $data, ?string $landedCostId = null): array
    {
        // Manual shares are typed on the preview's lines, so unfinished shares must not hide the lines.
        return $this->present($this->plan($data, $landedCostId, true, true));
    }

    public function post(LandedCost $document, ?string $userId): LandedCost
    {
        return DB::transaction(function () use ($document, $userId): LandedCost {
            $locked = $this->lockDraft($document);
            app(DocumentNumberService::class)->assign($locked, 'landed_cost_number', DocumentType::LandedCost, (int) $locked->branch_id, $locked->cost_date);

            // Lock the bills so two landed costs cannot use the same open amount at once.
            $this->lockChargeSources($locked);

            // Allocations are rebuilt from current data so the open amounts and receipts are checked again.
            $plan = $this->plan($this->toInput($locked), (string) $locked->id);
            $this->store($locked, $plan);

            $label = 'Landed cost '.$locked->landed_cost_number;
            $totals = ['inventory' => '0', 'cogs' => '0', 'variance' => '0'];
            $allocations = LandedCostAllocation::query()
                ->whereIn('landed_cost_charge_id', LandedCostCharge::query()->where('landed_cost_id', $locked->id)->select('id'))
                ->orderBy('id')
                ->get();
            $items = Item::query()->whereIn('id', $allocations->pluck('item_id')->unique()->all())->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $item = $items->get((string) $allocation->item_id);
                $balance = StockBalance::lockRow((string) $allocation->item_id, (int) $allocation->warehouse_id, $allocation->lot_id);
                $basis = $this->costing->landedCostBasis($item, $balance, $allocation->source_movement_id, (string) $allocation->received_quantity);

                if ($basis === null) {
                    $allocation->update(['on_hand_basis' => '0', 'inventory_amount' => '0', 'cogs_amount' => '0', 'variance_amount' => $allocation->amount]);
                    $totals['variance'] = Decimal::add($totals['variance'], $allocation->amount);

                    continue;
                }

                $inventory = Decimal::round(Decimal::div(Decimal::mul($allocation->amount, $basis), $allocation->received_quantity, 12), 4);
                $cogs = Decimal::round(Decimal::sub($allocation->amount, $inventory), 4);
                $this->stockMovements->postLandedCost(
                    (string) $allocation->item_id,
                    (int) $allocation->warehouse_id,
                    $allocation->lot_id,
                    $inventory,
                    $allocation->source_movement_id,
                    (string) $locked->id,
                    $label,
                    $userId,
                );
                $allocation->update(['on_hand_basis' => $basis, 'inventory_amount' => $inventory, 'cogs_amount' => $cogs, 'variance_amount' => '0']);
                $totals['inventory'] = Decimal::add($totals['inventory'], $inventory);
                $totals['cogs'] = Decimal::add($totals['cogs'], $cogs);
            }

            $this->journal->landedCost($locked, $totals, $userId, false);

            $locked->update(['status' => LandedCostStatus::Posted, 'posted_by' => $userId, 'posted_at' => now()]);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * Takes the cost back off. The part still in stock comes off stock (never more than was added there);
     * the rest comes off cost of sales, so the clearing account gets the full amount back.
     */
    public function reverse(LandedCost $document, ?string $userId): LandedCost
    {
        return DB::transaction(function () use ($document, $userId): LandedCost {
            $locked = LandedCost::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LandedCostStatus::Posted) {
                abort(422, 'Only a posted landed cost can be reversed.', ['X-Error-Code' => 'LANDED_COST_NOT_POSTED']);
            }

            $label = 'Landed cost '.$locked->landed_cost_number.' reversed';
            $totals = ['inventory' => '0', 'cogs' => '0', 'variance' => '0'];
            $allocations = LandedCostAllocation::query()
                ->whereIn('landed_cost_charge_id', LandedCostCharge::query()->where('landed_cost_id', $locked->id)->select('id'))
                ->orderByDesc('id')
                ->get();
            $items = Item::query()->whereIn('id', $allocations->pluck('item_id')->unique()->all())->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                if (Decimal::isPositive($allocation->variance_amount)) {
                    $totals['variance'] = Decimal::add($totals['variance'], $allocation->variance_amount);

                    continue;
                }

                $inventory = '0';
                if (Decimal::isPositive($allocation->inventory_amount) && Decimal::isPositive($allocation->on_hand_basis)) {
                    $item = $items->get((string) $allocation->item_id);
                    $balance = StockBalance::lockRow((string) $allocation->item_id, (int) $allocation->warehouse_id, $allocation->lot_id);
                    $current = $this->costing->landedCostBasis($item, $balance, $allocation->source_movement_id, (string) $allocation->received_quantity) ?? '0';
                    $ratioBasis = Decimal::min($current, $allocation->on_hand_basis);
                    $inventory = Decimal::round(Decimal::div(Decimal::mul($allocation->inventory_amount, $ratioBasis), $allocation->on_hand_basis, 12), 4);
                    $this->stockMovements->postLandedCost(
                        (string) $allocation->item_id,
                        (int) $allocation->warehouse_id,
                        $allocation->lot_id,
                        Decimal::neg($inventory, 4),
                        $allocation->source_movement_id,
                        (string) $locked->id,
                        $label,
                        $userId,
                    );
                }
                $totals['inventory'] = Decimal::add($totals['inventory'], $inventory);
                $totals['cogs'] = Decimal::add($totals['cogs'], Decimal::sub($allocation->amount, $inventory));
            }

            $this->journal->landedCost($locked, $totals, $userId, true);
            $locked->update(['status' => LandedCostStatus::Reversed]);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * Block reversing a receipt or bill that a posted landed cost uses.
     */
    public static function assertNotUsed(string $documentType, string $documentId): void
    {
        $posted = fn ($q) => $q->select('id')->from('landed_costs')->where('status', LandedCostStatus::Posted->value);

        $used = match ($documentType) {
            'goods_receipt', 'purchase_invoice_receipt' => LandedCostReceipt::query()
                ->where('source_type', $documentType === 'goods_receipt' ? LandedCostSourceType::GoodsReceipt->value : LandedCostSourceType::PurchaseInvoice->value)
                ->where('source_id', $documentId)
                ->whereIn('landed_cost_id', $posted)
                ->exists(),
            'purchase_invoice' => LandedCostReceipt::query()
                ->where('source_type', LandedCostSourceType::PurchaseInvoice->value)
                ->where('source_id', $documentId)
                ->whereIn('landed_cost_id', $posted)
                ->exists()
                || LandedCostCharge::query()
                    ->where('source_type', LandedCostSourceType::PurchaseInvoiceLine->value)
                    ->whereIn('source_line_id', PurchaseInvoiceLine::query()->where('purchase_invoice_id', $documentId)->select('id'))
                    ->whereIn('landed_cost_id', $posted)
                    ->exists(),
            'expense_voucher' => LandedCostCharge::query()
                ->where('source_type', LandedCostSourceType::ExpenseVoucherLine->value)
                ->whereIn('source_line_id', ExpenseVoucherLine::query()->where('expense_voucher_id', $documentId)->select('id'))
                ->whereIn('landed_cost_id', $posted)
                ->exists(),
            default => false,
        };

        if ($used) {
            abort(422, 'A posted landed cost uses this document. Reverse the landed cost first.', ['X-Error-Code' => 'DOCUMENT_HAS_LANDED_COST']);
        }
    }

    /**
     * Response shape for the API and the drawer.
     *
     * @return array<string, mixed>
     */
    public function toArray(LandedCost $document): array
    {
        $input = $this->toInput($document);
        $stored = [];
        foreach ($document->charges as $charge) {
            foreach ($charge->allocations as $allocation) {
                $stored[$charge->id][$allocation->receipt_source_type.':'.$allocation->receipt_line_id] = $allocation;
            }
        }

        // Saved documents show the stored allocations (and, once posted, the stock / cost of sales split).
        $plan = $this->plan($input, (string) $document->id, strict: false);
        foreach ($plan['charges'] as $index => $row) {
            $charge = $document->charges[$index] ?? null;
            if ($charge === null || ! isset($stored[$charge->id])) {
                continue;
            }
            $plan['charges'][$index]['allocations'] = array_map(fn (LandedCostAllocation $saved): array => [
                'basis' => (string) $saved->basis,
                'amount' => Decimal::round((string) $saved->amount, 4),
                'inventory_amount' => Decimal::round((string) $saved->inventory_amount, 4),
                'cogs_amount' => Decimal::round((string) $saved->cogs_amount, 4),
                'variance_amount' => Decimal::round((string) $saved->variance_amount, 4),
            ], $stored[$charge->id]);
        }
        $presented = $this->present($plan);
        foreach ($presented['charges'] as $index => $row) {
            $presented['charges'][$index]['id'] = $document->charges[$index]->id ?? null;
        }

        return [
            'id' => (string) $document->id,
            'branch_id' => (int) $document->branch_id,
            'landed_cost_number' => $document->landed_cost_number,
            'cost_date' => $document->cost_date?->toDateString(),
            'reference' => $document->reference,
            'notes' => $document->notes,
            'status' => $document->status->value,
            'created_by' => $document->createdByUser?->name,
            'posted_by' => $document->postedByUser?->name,
            'posted_at' => $document->posted_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
        ] + $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public function toListRow(LandedCost $document): array
    {
        return [
            'id' => (string) $document->id,
            'landed_cost_number' => $document->landed_cost_number,
            'cost_date' => $document->cost_date?->toDateString(),
            'reference' => $document->reference,
            'status' => $document->status->value,
            'receipts_count' => (int) ($document->receipts_count ?? 0),
            'total_amount' => Decimal::round((string) ($document->total_amount ?? '0'), 4),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    /**
     * Validate the input and work out every allocation.
     *
     * @param  array<string, mixed>  $data
     * @return array{receipts: list<array<string, mixed>>, lines: list<array<string, mixed>>, charges: list<array<string, mixed>>}
     */
    /**
     * @param  bool  $lenientManual  preview only: manual shares that do not add up yet leave that charge unallocated
     */
    private function plan(array $data, ?string $landedCostId, bool $strict = true, bool $lenientManual = false): array
    {
        $receipts = [];
        $lines = [];
        $seen = [];
        foreach ((array) ($data['receipts'] ?? []) as $row) {
            $type = (string) ($row['source_type'] ?? '');
            $id = (string) ($row['source_id'] ?? '');
            if (! in_array($type, LandedCostSourceType::receiptValues(), true) || $id === '' || isset($seen[$type.':'.$id])) {
                continue;
            }
            $seen[$type.':'.$id] = true;
            $header = $this->sources->receiptHeader($type, $id);
            if ($header === null || ($strict && ! $header['posted'])) {
                abort(422, 'Only posted goods receipts and direct purchase invoices can take landed costs.', ['X-Error-Code' => 'LANDED_COST_RECEIPT_NOT_POSTED']);
            }
            $receipts[] = ['source_type' => $type, 'source_id' => $id] + $header;
            foreach ($this->sources->receiptLines($type, $id) as $line) {
                $line['key'] = $line['receipt_source_type'].':'.$line['receipt_line_id'];
                $line['receipt_number'] = $header['number'];
                $lines[] = $line;
            }
        }

        if ($strict && $receipts === []) {
            abort(422, 'Choose at least one receipt.', ['X-Error-Code' => 'LANDED_COST_NO_RECEIPTS']);
        }

        $charges = [];
        $seenCharges = [];
        foreach (array_values((array) ($data['charges'] ?? [])) as $index => $row) {
            $type = (string) ($row['source_type'] ?? '');
            $lineId = (int) ($row['source_line_id'] ?? 0);
            if (! in_array($type, LandedCostSourceType::chargeValues(), true) || $lineId <= 0) {
                continue;
            }
            if (isset($seenCharges[$type.':'.$lineId])) {
                abort(422, 'The same bill line is added twice.', ['X-Error-Code' => 'LANDED_COST_DUPLICATE_CHARGE']);
            }
            $seenCharges[$type.':'.$lineId] = true;

            $source = $this->sources->charge($type, $lineId, $landedCostId);
            if ($source === null || ($strict && ! $source['eligible'])) {
                abort(422, 'This bill line cannot be used as a landed cost.', ['X-Error-Code' => 'LANDED_COST_CHARGE_NOT_ELIGIBLE']);
            }
            $amount = isset($row['amount']) && $row['amount'] !== '' && $row['amount'] !== null
                ? Decimal::round($row['amount'], 4)
                : $source['open_amount'];
            if ($strict && ! Decimal::isPositive($amount)) {
                abort(422, 'Enter an amount greater than zero for each charge.', ['X-Error-Code' => 'LANDED_COST_CHARGE_AMOUNT_INVALID']);
            }
            if ($strict && Decimal::cmp($amount, $source['open_amount'], 4) > 0) {
                abort(422, sprintf('%s %s has only %s left to use.', $source['document_number'], $source['label'] ?? '', Decimal::round($source['open_amount'], 2)), [
                    'X-Error-Code' => 'LANDED_COST_CHARGE_EXCEEDS_OPEN',
                ]);
            }
            $method = LandedCostMethod::tryFrom((string) ($row['method'] ?? 'value')) ?? LandedCostMethod::Value;
            $manual = [];
            foreach ((array) ($row['manual'] ?? []) as $key => $value) {
                $manual[(string) $key] = (string) $value;
            }

            if ($lenientManual && $method === LandedCostMethod::Manual) {
                // Preview: show the shares as typed, even before they add up to the charge (saving still checks).
                $allocations = $this->typedManualShares($lines, $manual);
            } else {
                $allocations = $lines !== [] && Decimal::isPositive($amount) && ($strict || $method !== LandedCostMethod::Manual || $manual !== [])
                    ? $this->safeAllocate($amount, $method, $lines, $manual, $strict)
                    : [];
            }

            $charges[] = [
                'source_type' => $type,
                'source_line_id' => $lineId,
                'amount' => $amount,
                'method' => $method->value,
                'description' => $this->nullable($row['description'] ?? null) ?? $source['label'],
                'manual' => $manual,
                'source' => $source,
                'allocations' => $allocations,
                'sort_order' => $index + 1,
            ];
        }

        if ($strict && $charges === []) {
            abort(422, 'Add at least one charge.', ['X-Error-Code' => 'LANDED_COST_NO_CHARGES']);
        }

        return ['receipts' => $receipts, 'lines' => $lines, 'charges' => $charges];
    }

    /**
     * Manual shares exactly as typed (negatives ignored), for the preview.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, string>  $manual
     * @return array<string, array{basis: string, amount: string}>
     */
    private function typedManualShares(array $lines, array $manual): array
    {
        $out = [];
        foreach ($lines as $line) {
            $share = Decimal::round($manual[$line['key']] ?? '0', 4);
            if (Decimal::isPositive($share)) {
                $out[$line['key']] = ['basis' => $share, 'amount' => $share];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, string>  $manual
     * @return array<string, array{basis: string, amount: string}>
     */
    private function safeAllocate(string $amount, LandedCostMethod $method, array $lines, array $manual, bool $strict): array
    {
        if ($strict) {
            return $this->allocator->allocate($amount, $method, $lines, $manual);
        }

        try {
            return $this->allocator->allocate($amount, $method, $lines, $manual);
        } catch (HttpException) {
            return [];
        }
    }

    /**
     * @param  array{receipts: list<array<string, mixed>>, lines: list<array<string, mixed>>, charges: list<array<string, mixed>>}  $plan
     */
    private function store(LandedCost $document, array $plan): void
    {
        LandedCostReceipt::query()->where('landed_cost_id', $document->id)->delete();
        LandedCostCharge::query()->where('landed_cost_id', $document->id)->delete();

        foreach ($plan['receipts'] as $receipt) {
            LandedCostReceipt::query()->create([
                'landed_cost_id' => $document->id,
                'source_type' => $receipt['source_type'],
                'source_id' => $receipt['source_id'],
            ]);
        }

        $linesByKey = [];
        foreach ($plan['lines'] as $line) {
            $linesByKey[$line['key']] = $line;
        }

        foreach ($plan['charges'] as $row) {
            $charge = LandedCostCharge::query()->create([
                'landed_cost_id' => $document->id,
                'source_type' => $row['source_type'],
                'source_line_id' => $row['source_line_id'],
                'amount' => $row['amount'],
                'method' => $row['method'],
                'description' => $row['description'] !== null ? mb_substr((string) $row['description'], 0, 255) : null,
                'sort_order' => $row['sort_order'],
            ]);
            foreach ($row['allocations'] as $key => $alloc) {
                $line = $linesByKey[$key] ?? null;
                if ($line === null) {
                    continue;
                }
                LandedCostAllocation::query()->create([
                    'landed_cost_charge_id' => $charge->id,
                    'receipt_source_type' => $line['receipt_source_type'],
                    'receipt_line_id' => $line['receipt_line_id'],
                    'source_movement_id' => $line['source_movement_id'],
                    'item_id' => $line['item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'lot_id' => $line['lot_id'],
                    'received_quantity' => $line['received_quantity'],
                    'basis' => $alloc['basis'],
                    'amount' => $alloc['amount'],
                    'manual_amount' => $row['method'] === LandedCostMethod::Manual->value ? $alloc['amount'] : null,
                ]);
            }
        }
    }

    /**
     * Rebuild the save payload from a stored document (used before posting and for the response).
     *
     * @return array<string, mixed>
     */
    private function toInput(LandedCost $document): array
    {
        $document->loadMissing(['receipts', 'charges.allocations']);

        return [
            'cost_date' => $document->cost_date?->toDateString(),
            'reference' => $document->reference,
            'notes' => $document->notes,
            'receipts' => $document->receipts->map(fn (LandedCostReceipt $r): array => ['source_type' => $r->source_type, 'source_id' => $r->source_id])->all(),
            'charges' => $document->charges->map(fn (LandedCostCharge $c): array => [
                'source_type' => $c->source_type,
                'source_line_id' => $c->source_line_id,
                'amount' => (string) $c->amount,
                'method' => $c->method->value,
                'description' => $c->description,
                'manual' => $c->method === LandedCostMethod::Manual
                    ? $c->allocations->mapWithKeys(fn (LandedCostAllocation $a): array => [$a->receipt_source_type.':'.$a->receipt_line_id => (string) $a->amount])->all()
                    : [],
            ])->all(),
        ];
    }

    /**
     * Lines with their total added cost and landed unit cost, plus the charges with allocations.
     *
     * @param  array{receipts: list<array<string, mixed>>, lines: list<array<string, mixed>>, charges: list<array<string, mixed>>}  $plan
     * @return array<string, mixed>
     */
    private function present(array $plan): array
    {
        $added = [];
        foreach ($plan['charges'] as $charge) {
            foreach ($charge['allocations'] as $key => $alloc) {
                $added[$key] = Decimal::add($added[$key] ?? '0', $alloc['amount']);
            }
        }

        $lines = [];
        foreach ($plan['lines'] as $line) {
            $qty = $line['received_quantity'];
            $unit = Decimal::isPositive($qty) ? Decimal::round(Decimal::div($line['value'], $qty, 12), 6) : '0.000000';
            $plus = Decimal::round($added[$line['key']] ?? '0', 4);
            $perUnit = Decimal::isPositive($qty) ? Decimal::round(Decimal::div($plus, $qty, 12), 6) : '0.000000';
            $lines[] = [
                'key' => $line['key'],
                'receipt_source_type' => $line['receipt_source_type'],
                'receipt_line_id' => $line['receipt_line_id'],
                'receipt_number' => $line['receipt_number'],
                'item_id' => $line['item_id'],
                'item_code' => $line['item_code'],
                'item_name' => $line['item_name'],
                'warehouse_id' => $line['warehouse_id'],
                'lot_id' => $line['lot_id'],
                'received_quantity' => $qty,
                'value' => $line['value'],
                'unit_cost' => $unit,
                'added_amount' => $plus,
                'added_per_unit' => $perUnit,
                'landed_unit_cost' => Decimal::round(Decimal::add($unit, $perUnit), 6),
                'weight' => $line['weight'],
                'volume' => $line['volume'],
            ];
        }

        $total = array_reduce($plan['charges'], fn (string $sum, array $c): string => Decimal::add($sum, $c['amount']), '0');

        return [
            'receipts' => array_map(fn (array $r): array => [
                'source_type' => $r['source_type'],
                'source_id' => $r['source_id'],
                'number' => $r['number'],
                'date' => $r['date'],
                'supplier' => $r['supplier'],
            ], $plan['receipts']),
            'lines' => $lines,
            'charges' => array_map(fn (array $c): array => [
                'source_type' => $c['source_type'],
                'source_line_id' => $c['source_line_id'],
                'amount' => $c['amount'],
                'method' => $c['method'],
                'description' => $c['description'],
                'document_type' => $c['source']['document_type'],
                'document_id' => $c['source']['document_id'],
                'document_number' => $c['source']['document_number'],
                'party' => $c['source']['party'],
                'source_amount' => $c['source']['amount'],
                'open_amount' => $c['source']['open_amount'],
                'allocations' => $c['allocations'],
            ], $plan['charges']),
            'total_amount' => Decimal::round($total, 4),
        ];
    }

    private function lockChargeSources(LandedCost $document): void
    {
        $charges = LandedCostCharge::query()->where('landed_cost_id', $document->id)->get();
        $invoiceIds = PurchaseInvoiceLine::query()
            ->whereIn('id', $charges->where('source_type', LandedCostSourceType::PurchaseInvoiceLine->value)->pluck('source_line_id')->all())
            ->pluck('purchase_invoice_id')->unique()->all();
        $voucherIds = ExpenseVoucherLine::query()
            ->whereIn('id', $charges->where('source_type', LandedCostSourceType::ExpenseVoucherLine->value)->pluck('source_line_id')->all())
            ->pluck('expense_voucher_id')->unique()->all();
        if ($invoiceIds !== []) {
            PurchaseInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        if ($voucherIds !== []) {
            ExpenseVoucher::query()->whereIn('id', $voucherIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $receiptIds = LandedCostReceipt::query()->where('landed_cost_id', $document->id)->get();
        GoodsReceipt::query()->whereIn('id', $receiptIds->where('source_type', LandedCostSourceType::GoodsReceipt->value)->pluck('source_id')->all())->lockForUpdate()->get(['id']);
    }

    private function lockDraft(LandedCost $document): LandedCost
    {
        $locked = LandedCost::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== LandedCostStatus::Draft) {
            abort(422, 'Only a draft landed cost can be changed.', ['X-Error-Code' => 'LANDED_COST_NOT_DRAFT']);
        }

        return $locked;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
