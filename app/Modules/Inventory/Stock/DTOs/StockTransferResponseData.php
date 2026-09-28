<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Support\StockTransferLineQuantity;
use App\Modules\Inventory\Stock\Support\StockTransferRules;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class StockTransferResponseData
{
    public static function fromModel(StockTransfer $transfer, bool $includeLines = true): array
    {
        $transfer->loadMissing([
            'fromWarehouse:id,name,shortcut_name,is_active,manager_id',
            'toWarehouse:id,name,shortcut_name,is_active,manager_id',
            'createdByUser:id,name,email',
            'dispatchedByUser:id,name,email',
            'receivedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'from_warehouse_id' => $transfer->from_warehouse_id,
            'to_warehouse_id' => $transfer->to_warehouse_id,
            'status' => $transfer->status->value,
            'notes' => $transfer->notes,
            'from_warehouse' => self::warehouseBrief($transfer->fromWarehouse),
            'to_warehouse' => self::warehouseBrief($transfer->toWarehouse),
            'created_by' => self::userBrief($transfer->createdByUser),
            'dispatched_by' => self::userBrief($transfer->dispatchedByUser),
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'received_by' => self::userBrief($transfer->receivedByUser),
            'received_at' => $transfer->received_at?->toIso8601String(),
            'lines_count' => $transfer->lines_count ?? null,
            'created_at' => (string) $transfer->created_at,
            'updated_at' => (string) $transfer->updated_at,
        ];

        $hasOpen = false;
        $hasAllocated = false;
        if ($includeLines) {
            $transfer->loadMissing([
                'lines.item',
                'lines.itemUom.uom',
                'lines.lot',
                'receipts.lines.item',
                'receipts.lines.lot',
                'receipts.createdByUser',
                'receipts.postedByUser',
                'closures.lines.item',
                'closures.lines.lot',
                'closures.lines.reason',
                'closures.createdByUser',
            ]);
            $payload['lines'] = StockTransferLineResponseData::collectionToArray($transfer->lines);
            $payload['receipts'] = StockTransferReceiptResponseData::collectionToArray($transfer->receipts);
            $payload['closures'] = StockTransferClosureResponseData::collectionToArray($transfer->closures);
            $hasOpen = StockTransferLineQuantity::hasOpenQuantity($transfer);
            $hasAllocated = StockTransferLineQuantity::hasAllocatedQuantities($transfer);
        } else {
            $hasOpen = in_array($transfer->status, StockTransferStatus::receivable(), true);
            $hasAllocated = $transfer->status === StockTransferStatus::PartiallyReceived
                || $transfer->status === StockTransferStatus::Received;
        }

        $fromVisible = StockTransferRules::isWarehouseVisible((int) $transfer->from_warehouse_id);
        $toVisible = StockTransferRules::isWarehouseVisible((int) $transfer->to_warehouse_id);
        $receivable = in_array($transfer->status, StockTransferStatus::receivable(), true);

        $payload['can_receive'] = $toVisible
            && $receivable
            && $hasOpen
            && StockTransferRules::isDestinationWarehouseManager($transfer);
        $payload['can_cancel_transit'] = $fromVisible
            && $transfer->status === StockTransferStatus::InTransit
            && ! $hasAllocated
            && StockTransferRules::canCancelTransitActor($transfer);
        $payload['can_close_open'] = $fromVisible
            && $transfer->status === StockTransferStatus::PartiallyReceived
            && $hasOpen;

        return $payload;
    }

    /**
     * @param  Collection<int, StockTransfer>  $transfers
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $transfers, bool $includeLines = false): array
    {
        return $transfers
            ->map(fn (StockTransfer $transfer): array => self::fromModel($transfer, $includeLines))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,name:string,shortcut_name:string,is_active:bool}|null
     */
    private static function warehouseBrief(?Warehouse $warehouse): ?array
    {
        if (! $warehouse) {
            return null;
        }

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'shortcut_name' => $warehouse->shortcut_name,
            'is_active' => (bool) $warehouse->is_active,
        ];
    }

    /**
     * @return array{id:string,name:string,email:string|null}|null
     */
    private static function userBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
