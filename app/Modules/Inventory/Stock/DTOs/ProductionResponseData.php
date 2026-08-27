<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Stock\Enums\ProductionStatus;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class ProductionResponseData
{
    public static function fromModel(Production $document, bool $includeLines = true): array
    {
        $document->loadMissing([
            'warehouse:id,name,shortcut_name,is_active',
            'item:id,item_code,name,is_active,track_inventory,track_lots',
            'recipe:id,item_id,yield_quantity,uom_id',
            'recipe.uom:id,code,name',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
            'lot:id,lot_number,expiry_date',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $lot = $document->lot;

        $payload = [
            'id' => $document->id,
            'prd_number' => $document->prd_number,
            'warehouse_id' => $document->warehouse_id,
            'item_id' => $document->item_id,
            'recipe_id' => $document->recipe_id,
            'status' => $document->status->value,
            'production_date' => $document->production_date?->toDateString(),
            'quantity' => (string) $document->quantity,
            'base_quantity' => (string) $document->base_quantity,
            'yield_quantity' => (string) $document->yield_quantity,
            'item_uom_id' => $document->item_uom_id,
            'lot_id' => $document->lot_id,
            'lot' => $lot ? [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'is_expired' => $lot->isExpired(),
            ] : null,
            'notes' => $document->notes,
            'item' => self::itemBrief($document->item),
            'recipe' => self::recipeBrief($document->recipe),
            'item_uom' => $document->itemUom ? [
                'id' => $document->itemUom->id,
                'conversion_factor' => (string) $document->itemUom->conversion_factor,
                'uom' => $document->itemUom->uom ? [
                    'id' => $document->itemUom->uom->id,
                    'code' => $document->itemUom->uom->code,
                    'name' => $document->itemUom->uom->name,
                ] : null,
            ] : null,
            'warehouse' => self::warehouseBrief($document->warehouse),
            'created_by' => self::userBrief($document->createdByUser),
            'posted_by' => self::userBrief($document->postedByUser),
            'posted_at' => $document->posted_at?->toIso8601String(),
            'is_posted' => $document->status === ProductionStatus::Posted,
            'lines_count' => $document->lines_count ?? null,
            'created_at' => (string) $document->created_at,
            'updated_at' => (string) $document->updated_at,
        ];

        if ($includeLines) {
            $document->loadMissing([
                'lines.item',
                'lines.itemUom.uom',
                'lines.lot',
            ]);
            $payload['lines'] = ProductionLineResponseData::collectionToArray($document->lines);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, Production>  $documents
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $documents, bool $includeLines = false): array
    {
        return $documents
            ->map(fn (Production $document): array => self::fromModel($document, $includeLines))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function itemBrief(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }

        return [
            'id' => $item->id,
            'item_code' => $item->item_code,
            'name' => $item->name,
            'is_active' => (bool) $item->is_active,
            'track_inventory' => (bool) $item->track_inventory,
            'track_lots' => (bool) $item->track_lots,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function recipeBrief(?Recipe $recipe): ?array
    {
        if (! $recipe) {
            return null;
        }

        return [
            'id' => $recipe->id,
            'yield_quantity' => (string) $recipe->yield_quantity,
            'uom_id' => $recipe->uom_id,
            'uom' => $recipe->uom ? [
                'id' => $recipe->uom->id,
                'code' => $recipe->uom->code,
                'name' => $recipe->uom->name,
            ] : null,
        ];
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
