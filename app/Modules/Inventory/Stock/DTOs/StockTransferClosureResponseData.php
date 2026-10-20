<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Stock\Models\StockTransferClosure;
use App\Modules\Inventory\Stock\Models\StockTransferClosureLine;
use Illuminate\Support\Collection;

readonly class StockTransferClosureResponseData
{
    public static function fromModel(StockTransferClosure $closure, bool $includeLines = true): array
    {
        $closure->loadMissing([
            'createdByUser:id,name,email',
        ]);

        $payload = [
            'id' => $closure->id,
            'closure_number' => $closure->closure_number,
            'stock_transfer_id' => $closure->stock_transfer_id,
            'warehouse_id' => $closure->warehouse_id,
            'notes' => $closure->notes,
            'created_by' => self::userBrief($closure->createdByUser),
            'closed_at' => $closure->closed_at?->toIso8601String(),
            'created_at' => (string) $closure->created_at,
        ];

        if ($includeLines) {
            $closure->loadMissing([
                'lines.item:id,item_code,name',
                'lines.reason:id,code,name',
                'lines.lot:id,lot_number',
            ]);
            $payload['lines'] = $closure->lines
                ->map(fn (StockTransferClosureLine $line): array => [
                    'id' => $line->id,
                    'stock_transfer_line_id' => $line->stock_transfer_line_id,
                    'item_id' => $line->item_id,
                    'item_name' => $line->item?->name,
                    'lot_id' => $line->lot_id,
                    'lot_number' => $line->lot?->lot_number,
                    'outcome' => $line->outcome->value,
                    'quantity' => (string) $line->quantity,
                    'base_quantity' => (string) $line->base_quantity,
                    'stock_adjustment_reason_id' => $line->stock_adjustment_reason_id,
                    'reason_name' => $line->reason?->name,
                    'notes' => $line->notes,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @param  Collection<int, StockTransferClosure>  $closures
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $closures, bool $includeLines = true): array
    {
        return $closures
            ->map(fn (StockTransferClosure $closure): array => self::fromModel($closure, $includeLines))
            ->values()
            ->all();
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
