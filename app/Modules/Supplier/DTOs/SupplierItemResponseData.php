<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierItem;
use Illuminate\Support\Collection;

readonly class SupplierItemResponseData
{
    public static function fromModel(SupplierItem $row): array
    {
        $row->loadMissing([
            'supplier:id,supplier_code,name,is_active',
            'item:id,sku,name,allow_purchase,is_active',
            'currency:id,code,name,symbol,iso_code',
        ]);

        return [
            'id' => $row->id,
            'supplier_id' => $row->supplier_id,
            'item_id' => $row->item_id,
            'supplier_sku' => $row->supplier_sku,
            'last_purchase_price' => $row->last_purchase_price !== null ? (string) $row->last_purchase_price : null,
            'currency_id' => $row->currency_id,
            'lead_time_days' => $row->lead_time_days,
            'is_preferred' => (bool) $row->is_preferred,
            'supplier' => self::supplierBrief($row->supplier),
            'item' => self::itemBrief($row->item),
            'currency' => self::currencyBrief($row->currency),
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /**
     * @param  Collection<int, SupplierItem>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (SupplierItem $row): array => self::fromModel($row))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function supplierBrief(?Supplier $supplier): ?array
    {
        if (! $supplier) {
            return null;
        }

        return [
            'id' => $supplier->id,
            'supplier_code' => $supplier->supplier_code,
            'name' => $supplier->name,
            'is_active' => (bool) $supplier->is_active,
        ];
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
            'sku' => $item->sku,
            'name' => $item->name,
            'allow_purchase' => (bool) $item->allow_purchase,
            'is_active' => (bool) $item->is_active,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function currencyBrief(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'iso_code' => $currency->iso_code,
        ];
    }
}
