<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class StockTransferResponseData
{
    public static function fromModel(StockTransfer $transfer, bool $includeLines = true): array
    {
        $transfer->loadMissing([
            'fromWarehouse:id,name,shortcut_name,is_active',
            'toWarehouse:id,name,shortcut_name,is_active',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
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
            'posted_by' => self::userBrief($transfer->postedByUser),
            'posted_at' => $transfer->posted_at?->toIso8601String(),
            'lines_count' => $transfer->lines_count ?? null,
            'created_at' => (string) $transfer->created_at,
            'updated_at' => (string) $transfer->updated_at,
        ];

        if ($includeLines) {
            $transfer->loadMissing([
                'lines.item',
                'lines.itemUom.uom',
            ]);
            $payload['lines'] = StockTransferLineResponseData::collectionToArray($transfer->lines);
        }

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
     * @return array{id:int,name:string,email:string}|null
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
