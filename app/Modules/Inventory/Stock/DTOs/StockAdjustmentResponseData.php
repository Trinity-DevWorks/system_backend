<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Stock\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class StockAdjustmentResponseData
{
    public static function fromModel(StockAdjustment $document, bool $includeLines = true): array
    {
        $document->loadMissing([
            'warehouse:id,name,shortcut_name,is_active',
            'reason:id,code,name,direction,is_active',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $document->id,
            'adj_number' => $document->adj_number,
            'warehouse_id' => $document->warehouse_id,
            'stock_adjustment_reason_id' => $document->stock_adjustment_reason_id,
            'status' => $document->status->value,
            'adjustment_date' => $document->adjustment_date?->toDateString(),
            'notes' => $document->notes,
            'warehouse' => self::warehouseBrief($document->warehouse),
            'reason' => self::reasonBrief($document->reason),
            'created_by' => self::userBrief($document->createdByUser),
            'posted_by' => self::userBrief($document->postedByUser),
            'posted_at' => $document->posted_at?->toIso8601String(),
            'is_posted' => $document->status === StockAdjustmentStatus::Posted,
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
            $payload['lines'] = StockAdjustmentLineResponseData::collectionToArray($document->lines);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, StockAdjustment>  $documents
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $documents, bool $includeLines = false): array
    {
        return $documents
            ->map(fn (StockAdjustment $document): array => self::fromModel($document, $includeLines))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,code:string,name:string,direction:string,is_active:bool}|null
     */
    private static function reasonBrief(?StockAdjustmentReason $reason): ?array
    {
        if (! $reason) {
            return null;
        }

        return [
            'id' => $reason->id,
            'code' => $reason->code,
            'name' => $reason->name,
            'direction' => $reason->direction->value,
            'is_active' => (bool) $reason->is_active,
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
