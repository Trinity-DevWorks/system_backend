<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Models\User;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Enums\BundleExplosionStatus;
use App\Modules\Inventory\Stock\Models\BundleExplosion;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class BundleExplosionResponseData
{
    public static function fromModel(BundleExplosion $document, bool $includeLines = true): array
    {
        $document->loadMissing([
            'warehouse:id,name,shortcut_name,is_active',
            'item:id,item_code,name,is_active,track_inventory,track_lots',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $document->id,
            'bex_number' => $document->bex_number,
            'warehouse_id' => $document->warehouse_id,
            'item_id' => $document->item_id,
            'status' => $document->status->value,
            'explosion_date' => $document->explosion_date?->toDateString(),
            'quantity' => (string) $document->quantity,
            'notes' => $document->notes,
            'item' => self::itemBrief($document->item),
            'warehouse' => self::warehouseBrief($document->warehouse),
            'created_by' => self::userBrief($document->createdByUser),
            'posted_by' => self::userBrief($document->postedByUser),
            'posted_at' => $document->posted_at?->toIso8601String(),
            'is_posted' => $document->status === BundleExplosionStatus::Posted,
            'lines_count' => $document->lines_count ?? null,
            'created_at' => (string) $document->created_at,
            'updated_at' => (string) $document->updated_at,
        ];

        if ($includeLines) {
            $document->loadMissing([
                'lines.item',
                'lines.lot',
            ]);
            $payload['lines'] = BundleExplosionLineResponseData::collectionToArray($document->lines);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, BundleExplosion>  $documents
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $documents, bool $includeLines = false): array
    {
        return $documents
            ->map(fn (BundleExplosion $document): array => self::fromModel($document, $includeLines))
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
