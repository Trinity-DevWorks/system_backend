<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Warehouse\Models\Warehouse;

final class StockTransferRules
{
    public static function assertDraft(StockTransfer $transfer): void
    {
        if ($transfer->status !== StockTransferStatus::Draft) {
            abort(422, 'Only draft transfers can be modified.', ['X-Error-Code' => 'STOCK_TRANSFER_NOT_DRAFT']);
        }
    }

    public static function assertWarehouses(int $fromWarehouseId, int $toWarehouseId): void
    {
        if ($fromWarehouseId === $toWarehouseId) {
            abort(422, 'Source and destination warehouses must be different.', ['X-Error-Code' => 'STOCK_TRANSFER_SAME_WAREHOUSE']);
        }

        $from = Warehouse::query()->findOrFail($fromWarehouseId);
        $to = Warehouse::query()->findOrFail($toWarehouseId);

        if (! $from->is_active || ! $to->is_active) {
            abort(422, 'Both warehouses must be active.', ['X-Error-Code' => 'STOCK_TRANSFER_WAREHOUSE_INACTIVE']);
        }
    }
}
