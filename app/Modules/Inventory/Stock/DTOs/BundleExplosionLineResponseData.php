<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\BundleExplosionLine;
use Illuminate\Support\Collection;

readonly class BundleExplosionLineResponseData
{
    public static function fromModel(BundleExplosionLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,is_active,track_inventory,track_lots,base_uom_id',
            'item.baseUom:id,code,name',
            'lot:id,lot_number,expiry_date',
        ]);

        $lot = $line->lot;

        return [
            'id' => $line->id,
            'bundle_explosion_id' => $line->bundle_explosion_id,
            'item_id' => $line->item_id,
            'bundle_item_id' => $line->bundle_item_id,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'theoretical_quantity' => (string) $line->theoretical_quantity,
            'lot_id' => $line->lot_id,
            'lot' => $lot ? [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'is_expired' => $lot->isExpired(),
            ] : null,
            'notes' => $line->notes,
            'item' => self::itemBrief($line->item),
        ];
    }

    /**
     * @param  Collection<int, BundleExplosionLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (BundleExplosionLine $line): array => self::fromModel($line))
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
            'base_uom' => $item->baseUom ? [
                'id' => $item->baseUom->id,
                'code' => $item->baseUom->code,
                'name' => $item->baseUom->name,
            ] : null,
        ];
    }
}
