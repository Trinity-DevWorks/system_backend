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

        if (! in_array($transfer->status, StockTransferStatus::receivable(), true)) {
            abort(422, 'Only in-transit transfers can be received.', ['X-Error-Code' => 'STOCK_TRANSFER_RECEIVE_NOT_ALLOWED']);
        }

        if (! StockTransferLineQuantity::hasOpenQuantity($transfer)) {
            abort(422, 'This stock transfer has no remaining quantity to receive.', [
                'X-Error-Code' => 'STOCK_TRANSFER_NO_OPEN_QUANTITY',
            ]);
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

        if ($transfer->status === StockTransferStatus::PartiallyReceived
            || ($transfer->status === StockTransferStatus::InTransit
                && StockTransferLineQuantity::hasAllocatedQuantities($transfer))) {
            abort(422, 'Cannot cancel a transfer with received or closed quantities.', [
                'X-Error-Code' => 'STOCK_TRANSFER_HAS_RECEIPTS',
            ]);
        }

        if ($transfer->status !== StockTransferStatus::InTransit) {
            abort(422, 'This stock transfer cannot be cancelled.', ['X-Error-Code' => 'STOCK_TRANSFER_CANCEL_NOT_ALLOWED']);
        }
    }

    public static function assertCloseable(StockTransfer $transfer): void
    {
        if ($transfer->status !== StockTransferStatus::PartiallyReceived) {
            abort(422, 'Only partially received transfers can close remaining quantity.', [
                'X-Error-Code' => 'STOCK_TRANSFER_CLOSE_NOT_ALLOWED',
            ]);
        }

        if (! StockTransferLineQuantity::hasOpenQuantity($transfer)) {
            abort(422, 'This stock transfer has no remaining quantity to close.', [
                'X-Error-Code' => 'STOCK_TRANSFER_NO_OPEN_QUANTITY',
            ]);
        }
    }

    public static function assertReceiveSide(StockTransfer $transfer): void
    {
        if (! self::isWarehouseVisible((int) $transfer->to_warehouse_id)) {
            abort(403, 'Only the destination warehouse can receive this transfer.', [
                'X-Error-Code' => 'STOCK_TRANSFER_RECEIVE_SIDE_FORBIDDEN',
            ]);
        }

        if (! self::isDestinationWarehouseManager($transfer)) {
            abort(403, 'Only the destination warehouse manager can receive this transfer.', [
                'X-Error-Code' => 'STOCK_TRANSFER_RECEIVE_MANAGER_FORBIDDEN',
            ]);
        }
    }

    public static function assertCanCancelTransit(StockTransfer $transfer): void
    {
        if (! self::isWarehouseVisible((int) $transfer->from_warehouse_id)) {
            abort(403, 'Only the source warehouse can cancel or close remaining quantity.', [
                'X-Error-Code' => 'STOCK_TRANSFER_SOURCE_SIDE_FORBIDDEN',
            ]);
        }

        if (! self::canCancelTransitActor($transfer)) {
            abort(403, 'Only the dispatcher or the source warehouse manager can cancel this transfer.', [
                'X-Error-Code' => 'STOCK_TRANSFER_CANCEL_ACTOR_FORBIDDEN',
            ]);
        }
    }

    /**
     * Receive is limited to the user assigned as manager on the destination warehouse.
     */
    public static function isDestinationWarehouseManager(StockTransfer $transfer, mixed $userId = null): bool
    {
        return self::isCurrentUserWarehouseManager(
            self::relatedWarehouse($transfer, 'toWarehouse', 'to_warehouse_id'),
            $userId,
        );
    }

    /**
     * Cancel in-transit: the user who dispatched, or the source warehouse manager.
     */
    public static function canCancelTransitActor(StockTransfer $transfer, mixed $userId = null): bool
    {
        return self::isDispatcher($transfer, $userId)
            || self::isCurrentUserWarehouseManager(
                self::relatedWarehouse($transfer, 'fromWarehouse', 'from_warehouse_id'),
                $userId,
            );
    }

    public static function isDispatcher(StockTransfer $transfer, mixed $userId = null): bool
    {
        $userId ??= auth()->id();
        if ($userId === null || $userId === '') {
            return false;
        }

        $dispatchedBy = $transfer->dispatched_by;
        if ($dispatchedBy === null || $dispatchedBy === '') {
            return false;
        }

        return (string) $dispatchedBy === (string) $userId;
    }

    public static function isCurrentUserWarehouseManager(?Warehouse $warehouse, mixed $userId = null): bool
    {
        $userId ??= auth()->id();
        if ($userId === null || $userId === '' || $warehouse === null) {
            return false;
        }

        $managerId = $warehouse->manager_id;
        if ($managerId === null || $managerId === '') {
            return false;
        }

        return (string) $managerId === (string) $userId;
    }

    public static function assertSourceSide(StockTransfer $transfer): void
    {
        if (! self::isWarehouseVisible((int) $transfer->from_warehouse_id)) {
            abort(403, 'Only the source warehouse can cancel or close remaining quantity.', [
                'X-Error-Code' => 'STOCK_TRANSFER_SOURCE_SIDE_FORBIDDEN',
            ]);
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
        $fromVisible = self::isWarehouseVisible((int) $transfer->from_warehouse_id);
        $toVisible = self::isWarehouseVisible((int) $transfer->to_warehouse_id);

        if (! $fromVisible && ! $toVisible) {
            abort(403, 'This transfer is not available in the active branch.', [
                'X-Error-Code' => 'WAREHOUSE_BRANCH_FORBIDDEN',
            ]);
        }
    }

    public static function isWarehouseVisible(int $warehouseId): bool
    {
        $ids = app(WarehouseService::class)->visibleWarehouseIds();
        if ($ids === null) {
            return true;
        }

        return in_array($warehouseId, $ids, true);
    }

    private static function relatedWarehouse(StockTransfer $transfer, string $relation, string $idColumn): ?Warehouse
    {
        if ($transfer->relationLoaded($relation)) {
            $warehouse = $transfer->getRelation($relation);

            return $warehouse instanceof Warehouse ? $warehouse : null;
        }

        $warehouseId = (int) $transfer->getAttribute($idColumn);
        if ($warehouseId < 1) {
            return null;
        }

        $warehouse = Warehouse::query()->find($warehouseId);

        return $warehouse instanceof Warehouse ? $warehouse : null;
    }
}
