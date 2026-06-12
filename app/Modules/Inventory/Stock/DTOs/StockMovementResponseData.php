<?php

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Support\StockMovementQuantityOnHand;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class StockMovementResponseData
{
    public static function fromModel(StockMovement $movement, ?string $quantityOnHand = null): array
    {
        $movement->loadMissing([
            'item:id,sku,name,base_uom_id',
            'item.baseUom:id,code,name',
            'warehouse:id,name,shortcut_name',
            'itemUom:id,uom_id',
            'itemUom.uom:id,code,name',
            'user:id,name,email',
        ]);

        return [
            'id' => $movement->id,
            'item_id' => $movement->item_id,
            'warehouse_id' => $movement->warehouse_id,
            'quantity_delta' => (string) $movement->quantity_delta,
            'quantity_on_hand' => $quantityOnHand,
            'type' => $movement->type->value,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'item_uom_id' => $movement->item_uom_id,
            'notes' => $movement->notes,
            'item' => self::itemBrief($movement->item),
            'warehouse' => self::warehouseBrief($movement->warehouse),
            'item_uom' => self::itemUomBrief($movement),
            'user' => self::userBrief($movement->user),
            'created_at' => (string) $movement->created_at,
            'updated_at' => (string) $movement->updated_at,
        ];
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $movements): array
    {
        $onHandByMovementId = StockMovementQuantityOnHand::mapForMovements($movements);

        return $movements
            ->map(function (StockMovement $movement) use ($onHandByMovementId): array {
                $onHand = $onHandByMovementId[$movement->id] ?? null;

                return self::fromModel($movement, $onHand);
            })
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,sku:string,name:string}|null
     */
    private static function itemBrief(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }

        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
        ];
    }

    /**
     * @return array{id:int,name:string,shortcut_name:string}|null
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
        ];
    }

    /**
     * Line UOM for display: item UOM when set, otherwise the item's base UOM.
     *
     * @return array{id:int|null,uom:array{id:int,code:string,name:string}|null}|null
     */
    private static function itemUomBrief(StockMovement $movement): ?array
    {
        if ($movement->itemUom) {
            return [
                'id' => $movement->itemUom->id,
                'uom' => $movement->itemUom->uom ? [
                    'id' => $movement->itemUom->uom->id,
                    'code' => $movement->itemUom->uom->code,
                    'name' => $movement->itemUom->uom->name,
                ] : null,
            ];
        }

        $baseUom = $movement->item?->baseUom;
        if (! $baseUom) {
            return null;
        }

        return [
            'id' => null,
            'uom' => [
                'id' => $baseUom->id,
                'code' => $baseUom->code,
                'name' => $baseUom->name,
            ],
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
