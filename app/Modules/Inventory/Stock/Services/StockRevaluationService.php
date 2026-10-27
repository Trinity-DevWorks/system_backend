<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockRevaluationStatus;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockRevaluation;
use App\Modules\Inventory\Stock\Models\StockRevaluationLine;
use App\Modules\Inventory\Stock\Support\InventoryCostingMath;
use App\Modules\Inventory\Stock\Support\StockRevaluationRules;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockRevaluationService
{
    public function __construct(
        private readonly StockRevaluationQueryService $stockRevaluationQueryService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly InventoryLotService $inventoryLotService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, StockRevaluation>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->stockRevaluationQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): StockRevaluation
    {
        $document = StockRevaluation::query()
            ->with([
                'warehouse',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.lot',
            ])
            ->withCount('lines')
            ->findOrFail($id);

        $this->warehouseService->assertVisibleById((int) $document->warehouse_id);

        return $document;
    }

    /**
     * @return array{
     *   quantity: string,
     *   unit_cost: string,
     *   inventory_value: string
     * }
     */
    public function balanceHint(int $warehouseId, string $itemId, ?int $lotId): array
    {
        StockRevaluationRules::assertWarehouse($warehouseId);
        $item = Item::query()->findOrFail($itemId);
        StockRevaluationRules::assertStockableItem($item);

        if ($item->track_lots && $lotId === null) {
            abort(422, 'Enter a lot number when revaluing a lot-tracked item.', [
                'X-Error-Code' => 'STOCK_LOT_REQUIRED',
            ]);
        }

        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->when(
                $lotId === null,
                fn ($query) => $query->whereNull('lot_id'),
                fn ($query) => $query->where('lot_id', $lotId),
            )
            ->first();

        return [
            'quantity' => InventoryCostingMath::qty($balance?->quantity ?? 0),
            'unit_cost' => InventoryCostingMath::money($balance?->unit_cost ?? 0),
            'inventory_value' => InventoryCostingMath::money($balance?->inventory_value ?? 0),
        ];
    }

    /**
     * @param  array{
     *   warehouse_id:int,
     *   revaluation_date?:string,
     *   notes?:?string,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): StockRevaluation
    {
        $warehouseId = (int) $data['warehouse_id'];
        StockRevaluationRules::assertWarehouse($warehouseId);

        return DB::transaction(function () use ($data, $warehouseId, $userId): StockRevaluation {
            $document = StockRevaluation::query()->create([
                'warehouse_id' => $warehouseId,
                'status' => StockRevaluationStatus::Draft,
                'revaluation_date' => $data['revaluation_date'] ?? now()->toDateString(),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
                'srv_number' => SequentialCodeGenerator::next(
                    StockRevaluation::class,
                    'srv_number',
                    'SRV-'
                ),
            ]);

            if (! empty($data['lines'])) {
                $this->replaceLines($document, $data['lines']);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  array{warehouse_id?:int, revaluation_date?:string, notes?:?string}  $data
     */
    public function updateHeader(StockRevaluation $document, array $data): StockRevaluation
    {
        return DB::transaction(function () use ($document, $data): StockRevaluation {
            $document = $this->lockDraft($document);

            $updates = [
                'revaluation_date' => array_key_exists('revaluation_date', $data)
                    ? (string) $data['revaluation_date']
                    : $document->revaluation_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $document->notes,
            ];

            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                $warehouseId = (int) $data['warehouse_id'];
                StockRevaluationRules::assertWarehouse($warehouseId);
                $updates['warehouse_id'] = $warehouseId;
            }

            $document->update($updates);

            return $this->find($document->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(StockRevaluation $document, array $lines): Collection
    {
        return DB::transaction(function () use ($document, $lines): Collection {
            $document = $this->lockDraft($document);
            $this->replaceLines($document, $lines);

            return StockRevaluationLine::query()
                ->where('stock_revaluation_id', $document->id)
                ->with(['item', 'lot'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(StockRevaluation $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = $this->lockDraft($document);
            $document->delete();
        });
    }

    public function post(StockRevaluation $document, ?string $userId): StockRevaluation
    {
        return DB::transaction(function () use ($document, $userId): StockRevaluation {
            $locked = StockRevaluation::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            StockRevaluationRules::assertPostable($locked);
            StockRevaluationRules::assertWarehouse((int) $locked->warehouse_id);

            $lines = StockRevaluationLine::query()
                ->where('stock_revaluation_id', $locked->id)
                ->with(['item'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $referenceNote = 'Revaluation '.$locked->srv_number;

            foreach ($lines as $line) {
                $item = $line->item ?? Item::query()->findOrFail($line->item_id);
                StockRevaluationRules::assertStockableItem($item);

                if ($item->track_lots && ! $line->lot_id) {
                    abort(422, 'Enter a lot number when revaluing a lot-tracked item.', [
                        'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                    ]);
                }

                $lotId = $line->lot_id ? (int) $line->lot_id : null;
                $balance = StockBalance::lockRow(
                    (string) $line->item_id,
                    (int) $locked->warehouse_id,
                    $lotId,
                );

                $qty = InventoryCostingMath::qty($balance->quantity);
                if (bccomp($qty, '0', InventoryCostingMath::QTY_SCALE) <= 0) {
                    abort(422, 'Cannot revalue zero or negative on-hand quantity.', [
                        'X-Error-Code' => 'STOCK_REVALUATION_NO_QTY',
                    ]);
                }

                $oldUnitCost = InventoryCostingMath::money($balance->unit_cost);
                $newUnitCost = InventoryCostingMath::money($line->new_unit_cost);
                if (bccomp($newUnitCost, $oldUnitCost, InventoryCostingMath::MONEY_SCALE) === 0) {
                    abort(422, 'New unit cost must differ from the current balance cost.', [
                        'X-Error-Code' => 'STOCK_REVALUATION_NO_CHANGE',
                    ]);
                }

                $oldValue = InventoryCostingMath::money($balance->inventory_value);
                $newValue = InventoryCostingMath::value($qty, $newUnitCost);
                $valueDelta = bcsub($newValue, $oldValue, InventoryCostingMath::MONEY_SCALE);

                $line->update([
                    'quantity_on_hand' => $qty,
                    'base_quantity' => $qty,
                    'old_unit_cost' => $oldUnitCost,
                    'old_inventory_value' => $oldValue,
                    'new_unit_cost' => $newUnitCost,
                    'new_inventory_value' => $newValue,
                    'value_delta' => $valueDelta,
                ]);

                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;

                $this->stockMovementService->post(StockMovementData::forRevaluation(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->warehouse_id,
                    newUnitCost: $newUnitCost,
                    notes: $lineNote,
                    userId: $userId,
                    lotId: $lotId,
                    stockRevaluationId: (string) $locked->id,
                ));
            }

            $this->journalPostingService->stockRevaluation($locked, $userId);

            $locked->update([
                'status' => StockRevaluationStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function reverse(StockRevaluation $document, ?string $userId): StockRevaluation
    {
        return DB::transaction(function () use ($document, $userId): StockRevaluation {
            $locked = StockRevaluation::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StockRevaluationStatus::Posted) {
                abort(422, 'Only a posted stock revaluation can be reversed.', [
                    'X-Error-Code' => 'STOCK_REVALUATION_NOT_POSTED',
                ]);
            }
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
            $this->stockMovementService->reverseReference(StockRevaluation::REFERENCE_TYPE, (string) $locked->id, $userId);
            $this->journalPostingService->reverse(StockRevaluation::REFERENCE_TYPE, (string) $locked->id, $userId);
            $locked->update(['status' => StockRevaluationStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(StockRevaluation $document, array $lines): void
    {
        $normalized = [];
        $warehouseId = (int) $document->warehouse_id;

        foreach ($lines as $row) {
            $itemId = isset($row['item_id']) ? (string) $row['item_id'] : '';
            if ($itemId === '') {
                abort(422, 'Select an item for each revaluation line.', [
                    'X-Error-Code' => 'STOCK_REVALUATION_ITEM_REQUIRED',
                ]);
            }

            $item = Item::query()->findOrFail($itemId);
            StockRevaluationRules::assertStockableItem($item);

            $newUnitCost = $this->normalizeUnitCost($row['new_unit_cost'] ?? null);
            if ($newUnitCost === null) {
                abort(422, 'Enter a new unit cost for each revaluation line.', [
                    'X-Error-Code' => 'STOCK_UNIT_COST_REQUIRED',
                ]);
            }

            $lotId = $this->resolveLineLotId($item, $row);
            if ($item->track_lots && $lotId === null) {
                abort(422, 'Enter a lot number when revaluing a lot-tracked item.', [
                    'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                ]);
            }

            $lineKey = $itemId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed.', [
                    'X-Error-Code' => 'STOCK_REVALUATION_DUPLICATE_LINE',
                ]);
            }

            $hint = $this->snapshotBalance($warehouseId, $itemId, $lotId);

            $normalized[$lineKey] = [
                'item_id' => $itemId,
                'lot_id' => $lotId,
                'quantity_on_hand' => $hint['quantity'],
                'base_quantity' => $hint['quantity'],
                'old_unit_cost' => $hint['unit_cost'],
                'old_inventory_value' => $hint['inventory_value'],
                'new_unit_cost' => $newUnitCost,
                'new_inventory_value' => InventoryCostingMath::value($hint['quantity'], $newUnitCost),
                'value_delta' => bcsub(
                    InventoryCostingMath::value($hint['quantity'], $newUnitCost),
                    $hint['inventory_value'],
                    InventoryCostingMath::MONEY_SCALE,
                ),
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        StockRevaluationLine::query()->where('stock_revaluation_id', $document->id)->delete();

        foreach ($normalized as $line) {
            StockRevaluationLine::query()->create([
                'stock_revaluation_id' => $document->id,
                ...$line,
            ]);
        }
    }

    /**
     * @return array{quantity: string, unit_cost: string, inventory_value: string}
     */
    private function snapshotBalance(int $warehouseId, string $itemId, ?int $lotId): array
    {
        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->when(
                $lotId === null,
                fn ($query) => $query->whereNull('lot_id'),
                fn ($query) => $query->where('lot_id', $lotId),
            )
            ->first();

        return [
            'quantity' => InventoryCostingMath::qty($balance?->quantity ?? 0),
            'unit_cost' => InventoryCostingMath::money($balance?->unit_cost ?? 0),
            'inventory_value' => InventoryCostingMath::money($balance?->inventory_value ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLineLotId(Item $item, array $row): ?int
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
            inbound: false,
        );

        return $lot?->id;
    }

    private function lockDraft(StockRevaluation $document): StockRevaluation
    {
        $locked = StockRevaluation::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        StockRevaluationRules::assertDraft($locked);

        return $locked;
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

        $cost = PriceMath::normalize($value);
        if (bccomp($cost, '0', PriceMath::scale()) < 0) {
            abort(422, 'Unit cost cannot be negative.', ['X-Error-Code' => 'STOCK_UNIT_COST_INVALID']);
        }

        return InventoryCostingMath::money($cost);
    }
}
