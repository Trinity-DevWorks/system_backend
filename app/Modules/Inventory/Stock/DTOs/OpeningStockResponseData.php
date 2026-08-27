<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Stock\Enums\OpeningStockStatus;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class OpeningStockResponseData
{
    public static function fromModel(OpeningStock $document, bool $includeLines = true): array
    {
        $document->loadMissing([
            'warehouse:id,name,shortcut_name,is_active',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $document->id,
            'os_number' => $document->os_number,
            'warehouse_id' => $document->warehouse_id,
            'status' => $document->status->value,
            'opening_date' => $document->opening_date?->toDateString(),
            'notes' => $document->notes,
            'warehouse' => self::warehouseBrief($document->warehouse),
            'created_by' => self::userBrief($document->createdByUser),
            'posted_by' => self::userBrief($document->postedByUser),
            'posted_at' => $document->posted_at?->toIso8601String(),
            'is_posted' => $document->status === OpeningStockStatus::Posted,
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
            $payload['lines'] = OpeningStockLineResponseData::collectionToArray($document->lines);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, OpeningStock>  $documents
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $documents, bool $includeLines = false): array
    {
        return $documents
            ->map(fn (OpeningStock $document): array => self::fromModel($document, $includeLines))
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
