<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Enums\StockRevaluationStatus;
use App\Modules\Inventory\Stock\Models\StockRevaluation;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

final class StockRevaluationRules
{
    public static function assertDraft(StockRevaluation $document): void
    {
        if ($document->status !== StockRevaluationStatus::Draft) {
            abort(422, 'Only draft stock revaluations can be modified.', [
                'X-Error-Code' => 'STOCK_REVALUATION_NOT_DRAFT',
            ]);
        }
    }

    public static function assertPostable(StockRevaluation $document): void
    {
        self::assertDraft($document);

        if ($document->lines()->count() === 0) {
            abort(422, 'Cannot post a stock revaluation without lines.', [
                'X-Error-Code' => 'STOCK_REVALUATION_NO_LINES',
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
