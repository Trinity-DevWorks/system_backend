<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Models\StockAdjustmentLine;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use App\Modules\Inventory\Stock\Support\StockAdjustmentQuantity;
use App\Modules\Inventory\Stock\Support\StockAdjustmentRules;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    public function __construct(
        private readonly StockAdjustmentQueryService $stockAdjustmentQueryService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly InventoryLotService $inventoryLotService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   reason_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, StockAdjustment>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->stockAdjustmentQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): StockAdjustment
    {
        $document = StockAdjustment::query()
            ->with([
                'warehouse',
                'reason',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
                'lines.lot',
            ])
            ->withCount('lines')
            ->findOrFail($id);

        $this->warehouseService->assertVisibleById((int) $document->warehouse_id);

        return $document;
    }

    /**
     * @param  array{
     *   warehouse_id:int,
     *   stock_adjustment_reason_id:int,
     *   adjustment_date?:string,
     *   notes?:?string,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): StockAdjustment
    {
        $warehouseId = (int) $data['warehouse_id'];
        StockAdjustmentRules::assertWarehouse($warehouseId);
        $reason = StockAdjustmentReason::query()->findOrFail((int) $data['stock_adjustment_reason_id']);
        StockAdjustmentRules::assertReason($reason);

        return DB::transaction(function () use ($data, $warehouseId, $reason, $userId): StockAdjustment {
            $document = StockAdjustment::query()->create([
                'warehouse_id' => $warehouseId,
                'stock_adjustment_reason_id' => $reason->id,
                'status' => StockAdjustmentStatus::Draft,
                'adjustment_date' => $data['adjustment_date'] ?? now()->toDateString(),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $document->update(['adj_number' => $this->formatAdjNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($document, $data['lines']);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  array{warehouse_id?:int, stock_adjustment_reason_id?:int, adjustment_date?:string, notes?:?string}  $data
     */
    public function updateHeader(StockAdjustment $document, array $data): StockAdjustment
    {
        return DB::transaction(function () use ($document, $data): StockAdjustment {
            $document = $this->lockDraft($document);

            $updates = [
                'adjustment_date' => array_key_exists('adjustment_date', $data)
                    ? (string) $data['adjustment_date']
                    : $document->adjustment_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $document->notes,
            ];

            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                $warehouseId = (int) $data['warehouse_id'];
                StockAdjustmentRules::assertWarehouse($warehouseId);
                $updates['warehouse_id'] = $warehouseId;
            }

            if (array_key_exists('stock_adjustment_reason_id', $data) && $data['stock_adjustment_reason_id'] !== null) {
                $reason = StockAdjustmentReason::query()->findOrFail((int) $data['stock_adjustment_reason_id']);
                StockAdjustmentRules::assertReason($reason);
                $updates['stock_adjustment_reason_id'] = $reason->id;
                $document->loadMissing('lines');
                foreach ($document->lines as $line) {
                    StockAdjustmentRules::assertQuantityMatchesReason($reason, (float) $line->quantity);
                }
            }

            $document->update($updates);

            return $this->find($document->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(StockAdjustment $document, array $lines): Collection
    {
        return DB::transaction(function () use ($document, $lines): Collection {
            $document = $this->lockDraft($document);
            $this->replaceLines($document, $lines);

            return StockAdjustmentLine::query()
                ->where('stock_adjustment_id', $document->id)
                ->with(['item', 'itemUom.uom', 'lot'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(StockAdjustment $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = $this->lockDraft($document);
            $document->delete();
        });
    }

    public function post(StockAdjustment $document, ?string $userId): StockAdjustment
    {
        return DB::transaction(function () use ($document, $userId): StockAdjustment {
            $locked = StockAdjustment::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            StockAdjustmentRules::assertPostable($locked);
            StockAdjustmentRules::assertWarehouse((int) $locked->warehouse_id);

            $reason = $locked->reason ?? StockAdjustmentReason::query()->findOrFail($locked->stock_adjustment_reason_id);
            StockAdjustmentRules::assertReason($reason);

            $lines = StockAdjustmentLine::query()
                ->where('stock_adjustment_id', $locked->id)
                ->with(['item'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $referenceNote = 'Adjustment '.$locked->adj_number.' — '.$reason->code;

            foreach ($lines as $line) {
                $item = $line->item ?? Item::query()->findOrFail($line->item_id);
                StockAdjustmentRules::assertStockableItem($item);
                StockAdjustmentRules::assertQuantityMatchesReason($reason, (float) $line->quantity);

                $itemUomId = $line->item_uom_id ? (int) $line->item_uom_id : null;
                $unitCost = StockAdjustmentQuantity::resolveBaseUnitCost($item, $line->unit_cost, $itemUomId);
                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;

                if ($item->track_lots && ! $line->lot_id) {
                    abort(422, 'Enter a lot number when adjusting a lot-tracked item.', [
                        'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                    ]);
                }

                $this->stockMovementService->post(StockMovementData::forAdjustment(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->warehouse_id,
                    quantityDelta: (string) $line->base_quantity,
                    unitCost: $unitCost,
                    itemUomId: $itemUomId,
                    notes: $lineNote,
                    userId: $userId,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    stockAdjustmentId: (string) $locked->id,
                ));
            }

            $locked->update([
                'status' => StockAdjustmentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(StockAdjustment $document, array $lines): void
    {
        $reason = $document->reason ?? StockAdjustmentReason::query()->findOrFail($document->stock_adjustment_reason_id);
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = isset($row['item_id']) ? (string) $row['item_id'] : '';
            if ($itemId === '') {
                abort(422, 'Select an item for each adjustment line.', [
                    'X-Error-Code' => 'STOCK_ADJUSTMENT_ITEM_REQUIRED',
                ]);
            }

            $item = Item::query()->findOrFail($itemId);
            StockAdjustmentRules::assertStockableItem($item);

            $quantity = (float) $row['quantity'];
            StockAdjustmentRules::assertQuantityMatchesReason($reason, $quantity);

            $itemUomId = isset($row['item_uom_id']) && $row['item_uom_id'] !== null && $row['item_uom_id'] !== ''
                ? (int) $row['item_uom_id']
                : null;

            $baseQuantity = StockAdjustmentQuantity::resolveBaseDelta($item, $quantity, $itemUomId);
            $lotId = $this->resolveLineLotId($item, $row, $quantity > 0);
            $lineKey = $itemId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed.', [
                    'X-Error-Code' => 'STOCK_ADJUSTMENT_DUPLICATE_LINE',
                ]);
            }

            $normalized[$lineKey] = [
                'item_id' => $itemId,
                'quantity' => number_format($quantity, 6, '.', ''),
                'base_quantity' => $baseQuantity,
                'item_uom_id' => $itemUomId,
                'unit_cost' => $this->normalizeUnitCost($row['unit_cost'] ?? null),
                'lot_id' => $lotId,
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        StockAdjustmentLine::query()->where('stock_adjustment_id', $document->id)->delete();

        foreach ($normalized as $line) {
            StockAdjustmentLine::query()->create([
                'stock_adjustment_id' => $document->id,
                ...$line,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLineLotId(Item $item, array $row, bool $inbound): ?int
    {
        $lotIdInput = isset($row['lot_id']) && $row['lot_id'] !== '' && $row['lot_id'] !== null
            ? (int) $row['lot_id']
            : null;
        if ($lotIdInput === 0) {
            $lotIdInput = null;
        }
        $lotNumberInput = isset($row['lot_number']) ? (string) $row['lot_number'] : null;
        $hasLotInput = $lotIdInput !== null
            || InventoryLotService::normalizeLotNumber($lotNumberInput) !== null;

        if (! $hasLotInput) {
            return null;
        }

        $lot = $this->inventoryLotService->resolve(
            $item,
            $lotIdInput,
            $lotNumberInput,
            isset($row['expiry_date']) ? (string) $row['expiry_date'] : null,
            inbound: $inbound,
        );

        return $lot?->id;
    }

    private function lockDraft(StockAdjustment $document): StockAdjustment
    {
        $locked = StockAdjustment::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        StockAdjustmentRules::assertDraft($locked);

        return $locked;
    }

    private function formatAdjNumber(): string
    {
        $seq = StockAdjustment::query()->count();

        return 'ADJ-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeUnitCost(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cost = number_format((float) $value, 4, '.', '');
        if (bccomp($cost, '0', 4) < 0) {
            abort(422, 'Unit cost cannot be negative.', ['X-Error-Code' => 'STOCK_UNIT_COST_INVALID']);
        }

        return $cost;
    }
}
