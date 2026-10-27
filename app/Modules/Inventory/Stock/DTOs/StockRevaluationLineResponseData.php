<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockRevaluationLine;
use Illuminate\Support\Collection;

readonly class StockRevaluationLineResponseData
{
    public static function fromModel(StockRevaluationLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,is_active,track_inventory,track_lots',
            'lot:id,lot_number,expiry_date',
        ]);

        $lot = $line->lot;

        return [
            'id' => $line->id,
            'stock_revaluation_id' => $line->stock_revaluation_id,
            'item_id' => $line->item_id,
            'lot_id' => $line->lot_id,
            'lot' => $lot ? [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'is_expired' => $lot->isExpired(),
            ] : null,
            'quantity_on_hand' => $line->quantity_on_hand !== null ? (string) $line->quantity_on_hand : null,
            'base_quantity' => $line->base_quantity !== null ? (string) $line->base_quantity : null,
            'old_unit_cost' => $line->old_unit_cost !== null ? (string) $line->old_unit_cost : null,
            'old_inventory_value' => $line->old_inventory_value !== null ? (string) $line->old_inventory_value : null,
            'new_unit_cost' => (string) $line->new_unit_cost,
            'new_inventory_value' => $line->new_inventory_value !== null ? (string) $line->new_inventory_value : null,
            'value_delta' => $line->value_delta !== null ? (string) $line->value_delta : null,
            'notes' => $line->notes,
            'item' => self::itemBrief($line->item),
        ];
    }

    /**
     * @param  Collection<int, StockRevaluationLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines
            ->map(fn (StockRevaluationLine $line): array => self::fromModel($line))
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
}
