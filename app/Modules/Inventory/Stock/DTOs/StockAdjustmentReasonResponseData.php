<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use Illuminate\Support\Collection;

readonly class StockAdjustmentReasonResponseData
{
    public static function fromModel(StockAdjustmentReason $reason): array
    {
        return [
            'id' => $reason->id,
            'code' => $reason->code,
            'name' => $reason->name,
            'direction' => $reason->direction->value,
            'is_active' => (bool) $reason->is_active,
            'is_system' => (bool) $reason->is_system,
            'notes' => $reason->notes,
            'created_at' => (string) $reason->created_at,
            'updated_at' => (string) $reason->updated_at,
        ];
    }

    /**
     * @param  Collection<int, StockAdjustmentReason>  $reasons
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $reasons): array
    {
        return $reasons
            ->map(fn (StockAdjustmentReason $reason): array => self::fromModel($reason))
            ->values()
            ->all();
    }
}
