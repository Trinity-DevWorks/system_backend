<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

final class StockAdjustmentRules
{
    public static function assertDraft(StockAdjustment $document): void
    {
        if ($document->status !== StockAdjustmentStatus::Draft) {
            abort(422, 'Only draft stock adjustments can be modified.', [
                'X-Error-Code' => 'STOCK_ADJUSTMENT_NOT_DRAFT',
            ]);
        }
    }

    public static function assertPostable(StockAdjustment $document): void
    {
        self::assertDraft($document);

        if ($document->lines()->count() === 0) {
            abort(422, 'Cannot post a stock adjustment without lines.', [
                'X-Error-Code' => 'STOCK_ADJUSTMENT_NO_LINES',
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

    public static function assertReason(StockAdjustmentReason $reason): void
    {
        if (! $reason->is_active) {
            abort(422, 'Select an active adjustment reason.', [
                'X-Error-Code' => 'STOCK_ADJUSTMENT_REASON_INACTIVE',
            ]);
        }
    }

    public static function assertQuantityMatchesReason(StockAdjustmentReason $reason, float $quantity): void
    {
        if ($quantity == 0.0) {
            abort(422, 'Adjustment quantity cannot be zero.', [
                'X-Error-Code' => 'STOCK_ADJUSTMENT_LINE_INVALID_QUANTITY',
            ]);
        }

        if (! $reason->direction->allows($quantity)) {
            abort(422, 'Line quantity does not match the selected reason direction.', [
                'X-Error-Code' => 'STOCK_ADJUSTMENT_REASON_DIRECTION',
            ]);
        }
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
