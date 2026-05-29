<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ItemUomService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Item $item, array $data): ItemUom
    {
        return DB::transaction(function () use ($item, $data): ItemUom {
            $uom = UnitOfMeasurement::query()->findOrFail((int) $data['uom_id']);
            $currencyId = $this->resolveCurrencyId($data['currency_id'] ?? null);

            $this->assertSameUnitGroupAsBase($item, $uom);

            if (ItemUom::query()
                ->where('item_id', $item->id)
                ->where('uom_id', $uom->id)
                ->where('currency_id', $currencyId)
                ->exists()) {
                abort(422, 'This unit and currency combination already exists for the item.', ['X-Error-Code' => 'ITEM_UOM_ALREADY_EXISTS']);
            }

            $isBase = (bool) ($data['is_base'] ?? false);
            if ($isBase) {
                $this->assertBaseUomRow($item, $uom, (float) $data['conversion_factor']);
            }

            $row = ItemUom::query()->create([
                'item_id' => $item->id,
                'uom_id' => $uom->id,
                'currency_id' => $currencyId,
                'conversion_factor' => $isBase ? 1 : (float) $data['conversion_factor'],
                'barcode' => $this->normalizeBarcode($data['barcode'] ?? null),
                'selling_price' => $data['selling_price'] ?? null,
                'cost_price' => $data['cost_price'] ?? null,
                'is_base' => $isBase,
                'is_default_sale' => (bool) ($data['is_default_sale'] ?? false),
                'is_default_purchase' => (bool) ($data['is_default_purchase'] ?? false),
            ]);

            if ($row->is_base) {
                $this->clearOtherBaseFlags($item->id, $row->id);
            }
            if ($row->is_default_sale) {
                $this->clearOtherDefaultSaleFlags($item->id, $currencyId, $row->id);
            }
            if ($row->is_default_purchase) {
                $this->clearOtherDefaultPurchaseFlags($item->id, $currencyId, $row->id);
            }

            return $row->load(['uom', 'currency']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Item $item, ItemUom $itemUom, array $data): ItemUom
    {
        if ((int) $itemUom->item_id !== (int) $item->id) {
            abort(404);
        }

        return DB::transaction(function () use ($item, $itemUom, $data): ItemUom {
            $currencyId = array_key_exists('currency_id', $data)
                ? $this->resolveCurrencyId($data['currency_id'])
                : (int) $itemUom->currency_id;

            if ($currencyId !== (int) $itemUom->currency_id) {
                if (ItemUom::query()
                    ->where('item_id', $item->id)
                    ->where('uom_id', $itemUom->uom_id)
                    ->where('currency_id', $currencyId)
                    ->where('id', '!=', $itemUom->id)
                    ->exists()) {
                    abort(422, 'This unit and currency combination already exists for the item.', ['X-Error-Code' => 'ITEM_UOM_ALREADY_EXISTS']);
                }
            }

            $isBase = array_key_exists('is_base', $data) ? (bool) $data['is_base'] : (bool) $itemUom->is_base;
            $conversionFactor = array_key_exists('conversion_factor', $data)
                ? (float) $data['conversion_factor']
                : (float) $itemUom->conversion_factor;

            if ($isBase) {
                $uom = UnitOfMeasurement::query()->findOrFail((int) $itemUom->uom_id);
                $this->assertBaseUomRow($item, $uom, $conversionFactor);
                $conversionFactor = 1;
            }

            $itemUom->update([
                'currency_id' => $currencyId,
                'conversion_factor' => $conversionFactor,
                'barcode' => array_key_exists('barcode', $data)
                    ? $this->normalizeBarcode($data['barcode'])
                    : $itemUom->barcode,
                'selling_price' => array_key_exists('selling_price', $data)
                    ? $data['selling_price']
                    : $itemUom->selling_price,
                'cost_price' => array_key_exists('cost_price', $data)
                    ? $data['cost_price']
                    : $itemUom->cost_price,
                'is_base' => $isBase,
                'is_default_sale' => array_key_exists('is_default_sale', $data)
                    ? (bool) $data['is_default_sale']
                    : $itemUom->is_default_sale,
                'is_default_purchase' => array_key_exists('is_default_purchase', $data)
                    ? (bool) $data['is_default_purchase']
                    : $itemUom->is_default_purchase,
            ]);

            $itemUom->refresh();

            if ($itemUom->is_base) {
                $this->clearOtherBaseFlags($item->id, $itemUom->id);
            }
            if ($itemUom->is_default_sale) {
                $this->clearOtherDefaultSaleFlags($item->id, $currencyId, $itemUom->id);
            }
            if ($itemUom->is_default_purchase) {
                $this->clearOtherDefaultPurchaseFlags($item->id, $currencyId, $itemUom->id);
            }

            return $itemUom->load(['uom', 'currency']);
        });
    }

    public function delete(Item $item, ItemUom $itemUom): void
    {
        if ((int) $itemUom->item_id !== (int) $item->id) {
            abort(404);
        }

        if ($itemUom->is_base) {
            abort(422, 'Cannot delete the base unit of measurement row.', ['X-Error-Code' => 'ITEM_BASE_UOM_DELETE_FORBIDDEN']);
        }

        $itemUom->delete();
    }

    /**
     * @return Collection<int, ItemUom>
     */
    public function listForItem(Item $item): Collection
    {
        return ItemUom::query()
            ->where('item_id', $item->id)
            ->with(['uom:id,code,name,unit_group_id', 'currency:id,code,name,symbol,iso_code'])
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->get();
    }

    public function ensureBaseRow(Item $item, int $currencyId): ItemUom
    {
        return ItemUom::query()->updateOrCreate(
            [
                'item_id' => $item->id,
                'uom_id' => $item->base_uom_id,
                'currency_id' => $currencyId,
            ],
            [
                'conversion_factor' => 1,
                'is_base' => true,
                'is_default_sale' => true,
                'is_default_purchase' => true,
            ]
        );
    }

    private function resolveCurrencyId(mixed $currencyId): int
    {
        if ($currencyId !== null && $currencyId !== '') {
            return (int) $currencyId;
        }

        $primary = Currency::getPrimary();
        if (! $primary) {
            abort(422, 'Set a primary currency before adding item unit prices.', ['X-Error-Code' => 'PRIMARY_CURRENCY_REQUIRED']);
        }

        return (int) $primary->id;
    }

    private function assertBaseUomRow(Item $item, UnitOfMeasurement $uom, float $conversionFactor): void
    {
        if ((int) $uom->id !== (int) $item->base_uom_id) {
            abort(422, 'Base row must use the item base unit of measurement.', ['X-Error-Code' => 'ITEM_BASE_UOM_MISMATCH']);
        }

        if ($conversionFactor !== 1.0) {
            abort(422, 'Base unit conversion factor must be 1.', ['X-Error-Code' => 'ITEM_BASE_UOM_INVALID_CONVERSION']);
        }
    }

    private function assertSameUnitGroupAsBase(Item $item, UnitOfMeasurement $uom): void
    {
        $base = UnitOfMeasurement::query()->find($item->base_uom_id);
        if (! $base) {
            abort(422, 'Item base unit of measurement is missing.', ['X-Error-Code' => 'ITEM_BASE_UOM_MISSING']);
        }

        if ((int) $base->unit_group_id !== (int) $uom->unit_group_id) {
            abort(422, 'Unit of measurement must belong to the same unit group as the item base UOM.', ['X-Error-Code' => 'ITEM_UOM_UNIT_GROUP_MISMATCH']);
        }
    }

    private function clearOtherBaseFlags(int $itemId, int $exceptId): void
    {
        ItemUom::query()
            ->where('item_id', $itemId)
            ->where('id', '!=', $exceptId)
            ->update(['is_base' => false]);
    }

    private function clearOtherDefaultSaleFlags(int $itemId, int $currencyId, int $exceptId): void
    {
        ItemUom::query()
            ->where('item_id', $itemId)
            ->where('currency_id', $currencyId)
            ->where('id', '!=', $exceptId)
            ->update(['is_default_sale' => false]);
    }

    private function clearOtherDefaultPurchaseFlags(int $itemId, int $currencyId, int $exceptId): void
    {
        ItemUom::query()
            ->where('item_id', $itemId)
            ->where('currency_id', $currencyId)
            ->where('id', '!=', $exceptId)
            ->update(['is_default_purchase' => false]);
    }

    private function normalizeBarcode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
