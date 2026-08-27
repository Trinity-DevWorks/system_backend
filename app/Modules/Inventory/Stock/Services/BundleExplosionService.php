<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\BundleItemRules;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\BundleExplosionStatus;
use App\Modules\Inventory\Stock\Models\BundleExplosion;
use App\Modules\Inventory\Stock\Models\BundleExplosionLine;
use App\Modules\Inventory\Stock\Support\BundleExplosionRules;
use App\Modules\Inventory\Stock\Support\BundleExplosionScale;
use App\Modules\Inventory\Stock\Support\StockAdjustmentQuantity;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BundleExplosionService
{
    public function __construct(
        private readonly BundleExplosionQueryService $bundleExplosionQueryService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly InventoryLotService $inventoryLotService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   warehouse_id?:int,
     *   item_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, BundleExplosion>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->bundleExplosionQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): BundleExplosion
    {
        $document = BundleExplosion::query()
            ->with([
                'warehouse',
                'item.itemType',
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
     * @param  array{
     *   warehouse_id:int,
     *   item_id:string,
     *   quantity:numeric,
     *   explosion_date?:string,
     *   notes?:?string,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): BundleExplosion
    {
        $warehouseId = (int) $data['warehouse_id'];
        BundleExplosionRules::assertWarehouse($warehouseId);

        $item = Item::query()->findOrFail((string) $data['item_id']);
        $components = BundleExplosionRules::assertExplodableItem($item);
        $headerQty = $this->normalizeKitQty($data['quantity'] ?? null);

        return DB::transaction(function () use ($data, $warehouseId, $item, $components, $headerQty, $userId): BundleExplosion {
            $document = BundleExplosion::query()->create([
                'warehouse_id' => $warehouseId,
                'item_id' => $item->id,
                'status' => BundleExplosionStatus::Draft,
                'explosion_date' => $data['explosion_date'] ?? now()->toDateString(),
                'quantity' => $headerQty,
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $document->update(['bex_number' => $this->formatBexNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($document, $components, $data['lines']);
            } else {
                $this->expandFromBundle($document, $components);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  array{
     *   warehouse_id?:int,
     *   item_id?:string,
     *   quantity?:numeric,
     *   explosion_date?:string,
     *   notes?:?string
     * }  $data
     */
    public function updateHeader(BundleExplosion $document, array $data): BundleExplosion
    {
        return DB::transaction(function () use ($document, $data): BundleExplosion {
            $document = $this->lockDraft($document);

            $item = $document->item ?? Item::query()->findOrFail($document->item_id);
            $rebuild = false;

            $updates = [
                'explosion_date' => array_key_exists('explosion_date', $data)
                    ? (string) $data['explosion_date']
                    : $document->explosion_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $document->notes,
            ];

            if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                $warehouseId = (int) $data['warehouse_id'];
                BundleExplosionRules::assertWarehouse($warehouseId);
                $updates['warehouse_id'] = $warehouseId;
            }

            $components = BundleExplosionRules::assertExplodableItem($item);

            if (array_key_exists('item_id', $data) && $data['item_id'] !== null && (string) $data['item_id'] !== (string) $document->item_id) {
                $item = Item::query()->findOrFail((string) $data['item_id']);
                $components = BundleExplosionRules::assertExplodableItem($item);
                $updates['item_id'] = $item->id;
                $rebuild = true;
            }

            $quantity = array_key_exists('quantity', $data) && $data['quantity'] !== null
                ? $this->normalizeKitQty($data['quantity'])
                : number_format((float) $document->quantity, 6, '.', '');

            if ($quantity !== number_format((float) $document->quantity, 6, '.', '')) {
                $rebuild = true;
            }

            $updates['quantity'] = $quantity;
            $document->update($updates);

            if ($rebuild) {
                $preservedLots = $this->lineLotsByItemId($document);
                $this->expandFromBundle($document->fresh() ?? $document, $components, $preservedLots);
            }

            return $this->find($document->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(BundleExplosion $document, array $lines): Collection
    {
        return DB::transaction(function () use ($document, $lines): Collection {
            $document = $this->lockDraft($document);
            $item = $document->item ?? Item::query()->findOrFail($document->item_id);
            $components = BundleExplosionRules::assertExplodableItem($item);
            $this->replaceLines($document, $components, $lines);

            return BundleExplosionLine::query()
                ->where('bundle_explosion_id', $document->id)
                ->with(['item', 'lot'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(BundleExplosion $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = $this->lockDraft($document);
            $document->delete();
        });
    }

    public function post(BundleExplosion $document, ?string $userId): BundleExplosion
    {
        return DB::transaction(function () use ($document, $userId): BundleExplosion {
            $locked = BundleExplosion::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            BundleExplosionRules::assertPostable($locked);
            BundleExplosionRules::assertWarehouse((int) $locked->warehouse_id);

            $item = $locked->item ?? Item::query()->findOrFail($locked->item_id);
            $components = BundleExplosionRules::assertExplodableItem($item);

            $lines = BundleExplosionLine::query()
                ->where('bundle_explosion_id', $locked->id)
                ->with(['item'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertComponentsPresent($components, $lines);

            $referenceNote = 'Bundle explosion '.$locked->bex_number;

            foreach ($lines as $line) {
                $child = $line->item ?? Item::query()->findOrFail($line->item_id);
                BundleExplosionRules::assertStockableItem($child);
                BundleItemRules::assertValidChild($item, $child);

                if ($child->track_lots && ! $line->lot_id) {
                    abort(422, 'Select a lot when issuing a lot-tracked component.', [
                        'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                    ]);
                }

                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;
                $outboundQty = bcmul((string) $line->base_quantity, '-1', 6);

                $this->stockMovementService->post(StockMovementData::forBundleSale(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->warehouse_id,
                    quantityDelta: $outboundQty,
                    notes: $lineNote,
                    userId: $userId,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                    bundleExplosionId: (string) $locked->id,
                ));
            }

            $locked->update([
                'status' => BundleExplosionStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  Collection<int, BundleItem>  $components
     * @param  array<string, int|null>  $preservedLots
     */
    private function expandFromBundle(BundleExplosion $document, Collection $components, array $preservedLots = []): void
    {
        $factor = BundleExplosionScale::factor((string) $document->quantity);

        $lines = [];
        foreach ($components as $component) {
            $itemId = (string) $component->child_item_id;
            $theoretical = BundleExplosionScale::apply((string) $component->quantity, $factor);
            $lines[] = [
                'item_id' => $itemId,
                'bundle_item_id' => $component->id,
                'quantity' => $theoretical,
                'theoretical_quantity' => $theoretical,
                'lot_id' => $preservedLots[$itemId] ?? null,
            ];
        }

        $this->replaceLines($document, $components, $lines);
    }

    /**
     * @param  Collection<int, BundleItem>  $components
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(BundleExplosion $document, Collection $components, array $lines): void
    {
        $byChild = $components->keyBy(fn (BundleItem $row): string => (string) $row->child_item_id);
        $factor = BundleExplosionScale::factor((string) $document->quantity);
        $seen = [];
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = isset($row['item_id']) ? (string) $row['item_id'] : '';
            if ($itemId === '') {
                abort(422, 'Select an item for each bundle explosion line.', [
                    'X-Error-Code' => 'BUNDLE_EXPLOSION_ITEM_REQUIRED',
                ]);
            }

            $component = $byChild->get($itemId);
            if (! $component) {
                abort(422, 'Component is not on this bundle.', [
                    'X-Error-Code' => 'BUNDLE_EXPLOSION_COMPONENT_UNKNOWN',
                ]);
            }

            $item = Item::query()->findOrFail($itemId);
            BundleExplosionRules::assertStockableItem($item);
            BundleItemRules::assertValidChild(
                $document->item ?? Item::query()->findOrFail($document->item_id),
                $item,
            );

            $quantity = (float) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                abort(422, 'Component quantity must be greater than zero.', [
                    'X-Error-Code' => 'BUNDLE_EXPLOSION_LINE_INVALID_QUANTITY',
                ]);
            }

            $baseQuantity = StockAdjustmentQuantity::resolveBaseDelta($item, $quantity, null);
            $lotId = $this->resolveLineLotId($item, $row);
            $lineKey = $itemId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed.', [
                    'X-Error-Code' => 'BUNDLE_EXPLOSION_DUPLICATE_LINE',
                ]);
            }

            $theoretical = isset($row['theoretical_quantity']) && $row['theoretical_quantity'] !== null && $row['theoretical_quantity'] !== ''
                ? number_format((float) $row['theoretical_quantity'], 6, '.', '')
                : BundleExplosionScale::apply((string) $component->quantity, $factor);

            $seen[$itemId] = true;
            $normalized[$lineKey] = [
                'item_id' => $itemId,
                'bundle_item_id' => $component->id,
                'quantity' => number_format($quantity, 6, '.', ''),
                'base_quantity' => $baseQuantity,
                'theoretical_quantity' => $theoretical,
                'lot_id' => $lotId,
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        foreach ($byChild->keys() as $childId) {
            if (! isset($seen[(string) $childId])) {
                abort(422, 'Every stock-tracked bundle component must appear on the explosion.', [
                    'X-Error-Code' => 'BUNDLE_EXPLOSION_COMPONENT_MISSING',
                ]);
            }
        }

        BundleExplosionLine::query()->where('bundle_explosion_id', $document->id)->delete();

        foreach ($normalized as $line) {
            BundleExplosionLine::query()->create([
                'bundle_explosion_id' => $document->id,
                ...$line,
            ]);
        }
    }

    /**
     * @param  Collection<int, BundleItem>  $components
     * @param  Collection<int, BundleExplosionLine>  $lines
     */
    private function assertComponentsPresent(Collection $components, Collection $lines): void
    {
        $present = $lines->pluck('item_id')->map(fn ($id): string => (string) $id)->unique();

        foreach ($components as $component) {
            if (! $present->contains((string) $component->child_item_id)) {
                abort(422, 'Every stock-tracked bundle component must appear on the explosion.', [
                    'X-Error-Code' => 'BUNDLE_EXPLOSION_COMPONENT_MISSING',
                ]);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function lineLotsByItemId(BundleExplosion $document): array
    {
        $lots = [];
        $rows = BundleExplosionLine::query()
            ->where('bundle_explosion_id', $document->id)
            ->whereNotNull('lot_id')
            ->get(['item_id', 'lot_id']);

        foreach ($rows as $row) {
            $lots[(string) $row->item_id] = (int) $row->lot_id;
        }

        return $lots;
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

    private function lockDraft(BundleExplosion $document): BundleExplosion
    {
        $locked = BundleExplosion::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        BundleExplosionRules::assertDraft($locked);

        return $locked;
    }

    private function formatBexNumber(): string
    {
        $seq = BundleExplosion::query()->count();

        return 'BEX-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeKitQty(mixed $value): string
    {
        $qty = number_format((float) $value, 6, '.', '');
        if (bccomp($qty, '0', 6) <= 0) {
            abort(422, 'Bundle quantity must be greater than zero.', [
                'X-Error-Code' => 'BUNDLE_EXPLOSION_QTY_INVALID',
            ]);
        }

        return $qty;
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
