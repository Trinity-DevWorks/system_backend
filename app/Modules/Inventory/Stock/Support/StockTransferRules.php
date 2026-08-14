<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

final class StockTransferRules
{
    public static function assertDraft(StockTransfer $transfer): void
    {
        if ($transfer->status !== StockTransferStatus::Draft) {
            abort(422, 'Only draft transfers can be modified.', ['X-Error-Code' => 'STOCK_TRANSFER_NOT_DRAFT']);
        }
    }

    public static function assertDispatchable(StockTransfer $transfer): void
    {
        if ($transfer->status === StockTransferStatus::InTransit) {
            abort(422, 'Stock transfer is already dispatched.', ['X-Error-Code' => 'STOCK_TRANSFER_ALREADY_DISPATCHED']);
        }

        if ($transfer->status !== StockTransferStatus::Draft) {
            abort(422, 'Only draft transfers can be dispatched.', ['X-Error-Code' => 'STOCK_TRANSFER_DISPATCH_NOT_ALLOWED']);
        }
    }

    public static function assertReceivable(StockTransfer $transfer): void
    {
        if ($transfer->status === StockTransferStatus::Received) {
            abort(422, 'Stock transfer is already received.', ['X-Error-Code' => 'STOCK_TRANSFER_ALREADY_RECEIVED']);
        }

        if ($transfer->status !== StockTransferStatus::InTransit) {
            abort(422, 'Only in-transit transfers can be received.', ['X-Error-Code' => 'STOCK_TRANSFER_RECEIVE_NOT_ALLOWED']);
        }
    }

    public static function assertCancellable(StockTransfer $transfer): void
    {
        if ($transfer->status === StockTransferStatus::Cancelled) {
            abort(422, 'Stock transfer is already cancelled.', ['X-Error-Code' => 'STOCK_TRANSFER_ALREADY_CANCELLED']);
        }

        if ($transfer->status === StockTransferStatus::Received) {
            abort(422, 'Received transfers cannot be cancelled.', ['X-Error-Code' => 'STOCK_TRANSFER_CANCEL_NOT_ALLOWED']);
        }

        if (! in_array($transfer->status, [StockTransferStatus::Draft, StockTransferStatus::InTransit], true)) {
            abort(422, 'This stock transfer cannot be cancelled.', ['X-Error-Code' => 'STOCK_TRANSFER_CANCEL_NOT_ALLOWED']);
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

        app(WarehouseService::class)->assertTransferPair($from, $to);
    }

    public static function assertTransferVisible(StockTransfer $transfer): void
    {
        $warehouseService = app(WarehouseService::class);
        $from = $transfer->relationLoaded('fromWarehouse')
            ? $transfer->fromWarehouse
            : Warehouse::query()->find($transfer->from_warehouse_id);
        $to = $transfer->relationLoaded('toWarehouse')
            ? $transfer->toWarehouse
            : Warehouse::query()->find($transfer->to_warehouse_id);

        if ($from instanceof Warehouse) {
            $warehouseService->assertVisible($from);
        }
        if ($to instanceof Warehouse) {
            $warehouseService->assertVisible($to);
        }
    }
}
