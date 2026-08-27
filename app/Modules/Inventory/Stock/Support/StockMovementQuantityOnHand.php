<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Support;

use App\Modules\Inventory\Stock\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Running balance after each movement (base UOM) per item + warehouse + lot.
 */
final class StockMovementQuantityOnHand
{
    /**
     * @param  Collection<int, StockMovement>  $movements  Rows returned to the client (any order).
     * @return array<int, string> movement id => quantity on hand after that movement
     */
    public static function mapForMovements(Collection $movements): array
    {
        if ($movements->isEmpty()) {
            return [];
        }

        /** @var SupportCollection<int, array{item_id:string, warehouse_id:int, lot_id:?int}> $pairs */
        $pairs = $movements
            ->map(fn (StockMovement $m): array => [
                'item_id' => (string) $m->item_id,
                'warehouse_id' => (int) $m->warehouse_id,
                'lot_id' => $m->lot_id !== null ? (int) $m->lot_id : null,
            ])
            ->unique(fn (array $pair): string => $pair['item_id'].'_'.$pair['warehouse_id'].'_'.($pair['lot_id'] ?? 'n'))
            ->values();

        $ledgerRows = StockMovement::query()
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(function ($inner) use ($pair): void {
                        $inner
                            ->where('item_id', $pair['item_id'])
                            ->where('warehouse_id', $pair['warehouse_id']);
                        if ($pair['lot_id'] === null) {
                            $inner->whereNull('lot_id');
                        } else {
                            $inner->where('lot_id', $pair['lot_id']);
                        }
                    });
                }
            })
            ->orderBy('id')
            ->get(['id', 'item_id', 'warehouse_id', 'lot_id', 'quantity_delta']);

        /** @var array<int, string> $onHandByMovementId */
        $onHandByMovementId = [];
        /** @var array<string, string> $runningByPair */
        $runningByPair = [];

        foreach ($ledgerRows as $row) {
            $pairKey = $row->item_id.'_'.$row->warehouse_id.'_'.($row->lot_id ?? 'n');
            $runningByPair[$pairKey] = bcadd(
                $runningByPair[$pairKey] ?? '0',
                (string) $row->quantity_delta,
                6
            );
            $onHandByMovementId[(int) $row->id] = $runningByPair[$pairKey];
        }

        return $onHandByMovementId;
    }
}
