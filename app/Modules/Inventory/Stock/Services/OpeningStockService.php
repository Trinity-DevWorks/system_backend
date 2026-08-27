<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\OpeningStockStatus;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Inventory\Stock\Models\OpeningStockLine;
use App\Modules\Inventory\Stock\Support\OpeningStockRules;
use App\Modules\Inventory\Stock\Support\StockAdjustmentQuantity;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OpeningStockService
{
    public function __construct(
        private readonly OpeningStockQueryService $openingStockQueryService,
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
     * @return LengthAwarePaginator<int, OpeningStock>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->openingStockQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): OpeningStock
    {
        $document = OpeningStock::query()
            ->with([
                'warehouse',
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
     *   opening_date?:string,
     *   notes?:?string,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): OpeningStock
    {
        $warehouseId = (int) $data['warehouse_id'];
        OpeningStockRules::assertWarehouse($warehouseId);

        return DB::transaction(function () use ($data, $warehouseId, $userId): OpeningStock {
            $document = OpeningStock::query()->create([
                'warehouse_id' => $warehouseId,
                'status' => OpeningStockStatus::Draft,
                'opening_date' => $data['opening_date'] ?? now()->toDateString(),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $document->update(['os_number' => $this->formatOsNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($document, $data['lines']);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  array{warehouse_id?:int, opening_date?:string, notes?:?string}  $data
     */
    public function updateHeader(OpeningStock $document, array $data): OpeningStock
    {
        return DB::transaction(function () use ($document, $data): OpeningStock {
            $document = $this->lockDraft($document);

            $updates = [
                'opening_date' => array_key_exists('opening_date', $data)
                    ? (string) $data['opening_date']
                    : $document->opening_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $document->notes,
            ];

            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                $warehouseId = (int) $data['warehouse_id'];
                OpeningStockRules::assertWarehouse($warehouseId);
                $updates['warehouse_id'] = $warehouseId;
            }

            $document->update($updates);

            return $this->find($document->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(OpeningStock $document, array $lines): Collection
    {
        return DB::transaction(function () use ($document, $lines): Collection {
            $document = $this->lockDraft($document);
            $this->replaceLines($document, $lines);

            return OpeningStockLine::query()
                ->where('opening_stock_id', $document->id)
                ->with(['item', 'itemUom.uom', 'lot'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(OpeningStock $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = $this->lockDraft($document);
            $document->delete();
        });
    }

    public function post(OpeningStock $document, ?string $userId): OpeningStock
    {
        return DB::transaction(function () use ($document, $userId): OpeningStock {
            $locked = OpeningStock::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            OpeningStockRules::assertPostable($locked);
            OpeningStockRules::assertWarehouse((int) $locked->warehouse_id);

            $lines = OpeningStockLine::query()
                ->where('opening_stock_id', $locked->id)
                ->with(['item'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $referenceNote = 'Opening stock '.$locked->os_number;

            foreach ($lines as $line) {
                $item = $line->item ?? Item::query()->findOrFail($line->item_id);
                OpeningStockRules::assertStockableItem($item);

                $itemUomId = $line->item_uom_id ? (int) $line->item_uom_id : null;
                $unitCost = StockAdjustmentQuantity::resolveBaseUnitCost($item, $line->unit_cost, $itemUomId);
                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;

                if ($item->track_lots && ! $line->lot_id) {
                    abort(422, 'Enter a lot number when adding stock for a lot-tracked item.', [
                        'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                    ]);
                }

                $this->stockMovementService->post(StockMovementData::forOpening(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->warehouse_id,
                    quantityDelta: (string) $line->base_quantity,
                    unitCost: $unitCost,
                    itemUomId: $itemUomId,
                    notes: $lineNote,
                    userId: $userId,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    openingStockId: (string) $locked->id,
                ));
            }

            $locked->update([
                'status' => OpeningStockStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(OpeningStock $document, array $lines): void
    {
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = isset($row['item_id']) ? (string) $row['item_id'] : '';
            if ($itemId === '') {
                abort(422, 'Select an item for each opening stock line.', [
                    'X-Error-Code' => 'OPENING_STOCK_ITEM_REQUIRED',
                ]);
            }

            $item = Item::query()->findOrFail($itemId);
            OpeningStockRules::assertStockableItem($item);

            $quantity = (float) $row['quantity'];
            if ($quantity <= 0) {
                abort(422, 'Opening stock quantity must be greater than zero.', [
                    'X-Error-Code' => 'OPENING_STOCK_LINE_INVALID_QUANTITY',
                ]);
            }

            $itemUomId = isset($row['item_uom_id']) && $row['item_uom_id'] !== null && $row['item_uom_id'] !== ''
                ? (int) $row['item_uom_id']
                : null;

            $baseQuantity = StockAdjustmentQuantity::resolveBaseDelta($item, $quantity, $itemUomId);
            $lotId = $this->resolveLineLotId($item, $row);
            $lineKey = $itemId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed.', [
                    'X-Error-Code' => 'OPENING_STOCK_DUPLICATE_LINE',
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

        OpeningStockLine::query()->where('opening_stock_id', $document->id)->delete();

        foreach ($normalized as $line) {
            OpeningStockLine::query()->create([
                'opening_stock_id' => $document->id,
                ...$line,
            ]);
        }
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
            inbound: true,
        );

        return $lot?->id;
    }

    private function lockDraft(OpeningStock $document): OpeningStock
    {
        $locked = OpeningStock::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        OpeningStockRules::assertDraft($locked);

        return $locked;
    }

    private function formatOsNumber(): string
    {
        $seq = OpeningStock::query()->count();

        return 'OS-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
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
