<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockCountStatus;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Models\StockCountLine;
use App\Modules\Inventory\Stock\Support\StockAdjustmentQuantity;
use App\Modules\Inventory\Stock\Support\StockCountRules;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockCountService
{
    public function __construct(
        private readonly StockCountQueryService $stockCountQueryService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly InventoryLotService $inventoryLotService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, StockCount>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->stockCountQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): StockCount
    {
        $document = StockCount::query()
            ->with([
                'warehouse',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item.baseUom',
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
     *   count_date?:string,
     *   notes?:?string,
     *   load_balances?:bool,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): StockCount
    {
        $warehouseId = (int) $data['warehouse_id'];
        StockCountRules::assertWarehouse($warehouseId);

        return DB::transaction(function () use ($data, $warehouseId, $userId): StockCount {
            $document = StockCount::query()->create([
                'warehouse_id' => $warehouseId,
                'status' => StockCountStatus::Draft,
                'count_date' => $data['count_date'] ?? now()->toDateString(),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $document->update(['cnt_number' => $this->formatCntNumber()]);

            if (! empty($data['load_balances'])) {
                $this->replaceLines($document, $this->snapshotBalanceLines($warehouseId));
            } elseif (! empty($data['lines'])) {
                $this->replaceLines($document, $data['lines']);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  array{warehouse_id?:int, count_date?:string, notes?:?string}  $data
     */
    public function updateHeader(StockCount $document, array $data): StockCount
    {
        return DB::transaction(function () use ($document, $data): StockCount {
            $document = $this->lockDraft($document);
            $warehouseChanged = false;

            $updates = [
                'count_date' => array_key_exists('count_date', $data)
                    ? (string) $data['count_date']
                    : $document->count_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $document->notes,
            ];

            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                $warehouseId = (int) $data['warehouse_id'];
                StockCountRules::assertWarehouse($warehouseId);
                if ($warehouseId !== (int) $document->warehouse_id) {
                    $warehouseChanged = true;
                }
                $updates['warehouse_id'] = $warehouseId;
            }

            $document->update($updates);

            if ($warehouseChanged) {
                StockCountLine::query()->where('stock_count_id', $document->id)->delete();
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(StockCount $document, array $lines): Collection
    {
        return DB::transaction(function () use ($document, $lines): Collection {
            $document = $this->lockDraft($document);
            $this->replaceLines($document, $lines);

            return StockCountLine::query()
                ->where('stock_count_id', $document->id)
                ->with(['item.baseUom', 'lot'])
                ->orderBy('id')
                ->get();
        });
    }

    public function loadBalances(StockCount $document): StockCount
    {
        return DB::transaction(function () use ($document): StockCount {
            $document = $this->lockDraft($document);
            $preserved = $this->countedByItemLot($document);
            $rows = $this->snapshotBalanceLines((int) $document->warehouse_id, $preserved);
            $this->replaceLines($document, $rows);

            return $this->find($document->id);
        });
    }

    public function delete(StockCount $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = $this->lockDraft($document);
            $document->delete();
        });
    }

    public function post(StockCount $document, ?string $userId): StockCount
    {
        return DB::transaction(function () use ($document, $userId): StockCount {
            $locked = StockCount::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            StockCountRules::assertPostable($locked);
            StockCountRules::assertWarehouse((int) $locked->warehouse_id);

            $lines = StockCountLine::query()
                ->where('stock_count_id', $locked->id)
                ->with(['item'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $referenceNote = 'Stock count '.$locked->cnt_number;

            foreach ($lines as $line) {
                $item = $line->item ?? Item::query()->findOrFail($line->item_id);
                StockCountRules::assertStockableItem($item);

                if ($item->track_lots && ! $line->lot_id) {
                    abort(422, 'Select a lot when counting a lot-tracked item.', [
                        'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                    ]);
                }

                $variance = (string) $line->variance_quantity;
                if (bccomp($variance, '0', 6) === 0) {
                    continue;
                }

                $unitCost = bccomp($variance, '0', 6) > 0
                    ? StockAdjustmentQuantity::resolveBaseUnitCost($item, $line->unit_cost, null)
                    : null;
                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;

                $this->stockMovementService->post(StockMovementData::forCount(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->warehouse_id,
                    quantityDelta: $variance,
                    unitCost: $unitCost,
                    notes: $lineNote,
                    userId: $userId,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    stockCountId: (string) $locked->id,
                ));
            }

            $locked->update([
                'status' => StockCountStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(StockCount $document, array $lines): void
    {
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = isset($row['item_id']) ? (string) $row['item_id'] : '';
            if ($itemId === '') {
                abort(422, 'Select an item for each stock count line.', [
                    'X-Error-Code' => 'STOCK_COUNT_ITEM_REQUIRED',
                ]);
            }

            $item = Item::query()->findOrFail($itemId);
            StockCountRules::assertStockableItem($item);

            if (! array_key_exists('counted_quantity', $row) || $row['counted_quantity'] === null || $row['counted_quantity'] === '') {
                abort(422, 'Enter a counted quantity for each line.', [
                    'X-Error-Code' => 'STOCK_COUNT_COUNTED_REQUIRED',
                ]);
            }

            $counted = number_format((float) $row['counted_quantity'], 6, '.', '');
            if (bccomp($counted, '0', 6) < 0) {
                abort(422, 'Counted quantity cannot be negative.', [
                    'X-Error-Code' => 'STOCK_COUNT_LINE_INVALID_QUANTITY',
                ]);
            }

            $lotIdPreview = isset($row['lot_id']) && $row['lot_id'] !== '' && $row['lot_id'] !== null
                ? (int) $row['lot_id']
                : null;
            if ($lotIdPreview === 0) {
                $lotIdPreview = null;
            }

            $lotNumberInput = isset($row['lot_number']) ? (string) $row['lot_number'] : null;
            $hasNewLotNumber = $lotIdPreview === null
                && InventoryLotService::normalizeLotNumber($lotNumberInput) !== null;

            $theoretical = isset($row['theoretical_quantity']) && $row['theoretical_quantity'] !== null && $row['theoretical_quantity'] !== ''
                ? number_format((float) $row['theoretical_quantity'], 6, '.', '')
                : ($hasNewLotNumber
                    ? number_format(0.0, 6, '.', '')
                    : $this->onHandSnapshot($itemId, (int) $document->warehouse_id, $lotIdPreview));

            $variance = bcsub($counted, $theoretical, 6);
            $lotId = $this->resolveLineLotId($item, $row, bccomp($variance, '0', 6) > 0);
            $lineKey = $itemId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed.', [
                    'X-Error-Code' => 'STOCK_COUNT_DUPLICATE_LINE',
                ]);
            }

            $normalized[$lineKey] = [
                'item_id' => $itemId,
                'theoretical_quantity' => $theoretical,
                'counted_quantity' => $counted,
                'variance_quantity' => $variance,
                'unit_cost' => $this->normalizeUnitCost($row['unit_cost'] ?? null),
                'lot_id' => $lotId,
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        StockCountLine::query()->where('stock_count_id', $document->id)->delete();

        foreach ($normalized as $line) {
            StockCountLine::query()->create([
                'stock_count_id' => $document->id,
                ...$line,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $preservedCounted
     * @return list<array<string, mixed>>
     */
    private function snapshotBalanceLines(int $warehouseId, array $preservedCounted = []): array
    {
        $rows = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '!=', 0)
            ->with(['item:id,is_active,track_inventory'])
            ->orderBy('id')
            ->get();

        $lines = [];
        foreach ($rows as $balance) {
            $item = $balance->item;
            if (! $item || ! $item->is_active || ! $item->track_inventory) {
                continue;
            }

            $itemId = (string) $balance->item_id;
            $lotId = $balance->lot_id ? (int) $balance->lot_id : null;
            $key = $itemId.'|'.($lotId ?? '');
            $theoretical = number_format((float) $balance->quantity, 6, '.', '');

            $lines[] = [
                'item_id' => $itemId,
                'theoretical_quantity' => $theoretical,
                'counted_quantity' => $preservedCounted[$key] ?? $theoretical,
                'lot_id' => $lotId,
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function countedByItemLot(StockCount $document): array
    {
        $map = [];
        $rows = StockCountLine::query()
            ->where('stock_count_id', $document->id)
            ->get(['item_id', 'lot_id', 'counted_quantity']);

        foreach ($rows as $row) {
            $key = (string) $row->item_id.'|'.($row->lot_id ? (int) $row->lot_id : '');
            $map[$key] = number_format((float) $row->counted_quantity, 6, '.', '');
        }

        return $map;
    }

    private function onHandSnapshot(string $itemId, int $warehouseId, ?int $lotId): string
    {
        $query = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId);

        if ($lotId === null) {
            $query->whereNull('lot_id');
        } else {
            $query->where('lot_id', $lotId);
        }

        $qty = $query->sum('quantity');

        return number_format((float) $qty, 6, '.', '');
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

    private function lockDraft(StockCount $document): StockCount
    {
        $locked = StockCount::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        StockCountRules::assertDraft($locked);

        return $locked;
    }

    private function formatCntNumber(): string
    {
        $seq = StockCount::query()->count();

        return 'CNT-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
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
