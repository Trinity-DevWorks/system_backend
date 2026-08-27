<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\BundleItemRules;
use App\Modules\Inventory\Stock\Enums\BundleExplosionStatus;
use App\Modules\Inventory\Stock\Models\BundleExplosion;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;

final class BundleExplosionRules
{
    public static function assertDraft(BundleExplosion $document): void
    {
        if ($document->status !== BundleExplosionStatus::Draft) {
            abort(422, 'Only draft bundle explosions can be modified.', [
                'X-Error-Code' => 'BUNDLE_EXPLOSION_NOT_DRAFT',
            ]);
        }
    }

    public static function assertPostable(BundleExplosion $document): void
    {
        self::assertDraft($document);

        if ($document->lines()->count() === 0) {
            abort(422, 'Cannot post a bundle explosion without component lines.', [
                'X-Error-Code' => 'BUNDLE_EXPLOSION_NO_LINES',
            ]);
        }
    }

    public static function assertWarehouse(int $warehouseId): void
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            abort(422, 'Cannot move stock in an inactive warehouse.', [
                'X-Error-Code' => 'STOCK_WAREHOUSE_INACTIVE',
            ]);
        }

        app(WarehouseService::class)->assertVisible($warehouse);
    }

    /**
     * @return Collection<int, BundleItem>
     */
    public static function assertExplodableItem(Item $item): Collection
    {
        BundleItemRules::assertIsBundleItem($item);

        $components = BundleItem::query()
            ->where('bundle_item_id', $item->id)
            ->with(['childItem.itemType'])
            ->orderBy('id')
            ->get();

        $stockable = $components
            ->filter(fn (BundleItem $row): bool => (bool) $row->childItem?->track_inventory)
            ->values();

        if ($stockable->isEmpty()) {
            abort(422, 'Add stock-tracked components to the bundle before exploding it.', [
                'X-Error-Code' => 'BUNDLE_EXPLOSION_NO_COMPONENTS',
            ]);
        }

        return $stockable;
    }

    public static function assertStockableItem(Item $item): void
    {
        if (! $item->is_active) {
            abort(422, 'Cannot move stock for an inactive item.', [
                'X-Error-Code' => 'STOCK_ITEM_INACTIVE',
            ]);
        }

        if (! $item->track_inventory) {
            abort(422, 'This item does not track inventory.', [
                'X-Error-Code' => 'STOCK_ITEM_NOT_TRACKED',
            ]);
        }
    }
}
