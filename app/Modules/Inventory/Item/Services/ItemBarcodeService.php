<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\DTOs\ItemResponseData;
use App\Modules\Inventory\Item\DTOs\ItemUomResponseData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ItemBarcodeService
{
    /**
     * @return Collection<int, ItemBarcode>
     */
    public function listForItem(Item $item): Collection
    {
        return ItemBarcode::query()
            ->where('item_id', $item->id)
            ->with([
                'itemUom.uom:id,code,name',
                'itemUom.currency:id,code,symbol',
            ])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve a scanned barcode to an item UOM (item_barcodes row or item_uoms.barcode).
     *
     * @return array{source: string, item: array<string, mixed>, item_uom: array<string, mixed>}|null
     */
    public function lookup(string $barcode): ?array
    {
        $normalized = trim($barcode);
        if ($normalized === '') {
            return null;
        }

        $extra = ItemBarcode::query()
            ->where('barcode', $normalized)
            ->with([
                'item.itemType:id,code,name',
                'item.category:id,code,name',
                'item.brand:id,code,name',
                'item.baseUom:id,code,name,unit_group_id',
                'itemUom.uom:id,code,name,unit_group_id',
                'itemUom.currency:id,code,name,symbol,iso_code',
            ])
            ->first();

        if ($extra) {
            $item = $extra->item;
            $itemUom = $extra->itemUom;

            if (! $item || ! $item->is_active) {
                return null;
            }

            $itemUom ??= $this->resolveItemUomForItem($item);

            if ($itemUom) {
                return [
                    'source' => 'item_barcodes',
                    'barcode_record_id' => $extra->id,
                    'item' => ItemResponseData::fromModel($item)->toArray(),
                    'item_uom' => ItemUomResponseData::fromModel($itemUom),
                ];
            }
        }

        $itemUom = ItemUom::query()
            ->where('barcode', $normalized)
            ->with([
                'item.itemType:id,code,name',
                'item.category:id,code,name',
                'item.brand:id,code,name',
                'item.baseUom:id,code,name,unit_group_id',
                'uom:id,code,name,unit_group_id',
                'currency:id,code,name,symbol,iso_code',
            ])
            ->first();

        if (! $itemUom || ! $itemUom->item || ! $itemUom->item->is_active) {
            return null;
        }

        return [
            'source' => 'item_uoms',
            'barcode_record_id' => null,
            'item' => ItemResponseData::fromModel($itemUom->item)->toArray(),
            'item_uom' => ItemUomResponseData::fromModel($itemUom),
        ];
    }

    /**
     * @param  array{barcode:string,item_uom_id?:int|null,is_primary:bool}  $data
     */
    public function store(Item $item, array $data): ItemBarcode
    {
        return DB::transaction(function () use ($item, $data): ItemBarcode {
            if (! empty($data['is_primary'])) {
                $this->clearPrimaryForItem($item->id);
            }

            return ItemBarcode::query()->create([
                'item_id' => $item->id,
                'item_uom_id' => $data['item_uom_id'] ?? null,
                'barcode' => trim($data['barcode']),
                'is_primary' => $data['is_primary'],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Item $item, ItemBarcode $barcode, array $data): ItemBarcode
    {
        $this->assertBelongsToItem($item, $barcode);

        return DB::transaction(function () use ($item, $barcode, $data): ItemBarcode {
            if (! empty($data['is_primary'])) {
                $this->clearPrimaryForItem($item->id, $barcode->id);
            }

            $barcode->update([
                'barcode' => array_key_exists('barcode', $data) ? trim((string) $data['barcode']) : $barcode->barcode,
                'item_uom_id' => array_key_exists('item_uom_id', $data)
                    ? $data['item_uom_id']
                    : $barcode->item_uom_id,
                'is_primary' => array_key_exists('is_primary', $data)
                    ? (bool) $data['is_primary']
                    : $barcode->is_primary,
            ]);

            return $barcode->refresh();
        });
    }

    public function delete(Item $item, ItemBarcode $barcode): void
    {
        $this->assertBelongsToItem($item, $barcode);
        $barcode->delete();
    }

    private function assertBelongsToItem(Item $item, ItemBarcode $barcode): void
    {
        if ((int) $barcode->item_id !== (int) $item->id) {
            abort(404);
        }
    }

    private function resolveItemUomForItem(Item $item): ?ItemUom
    {
        return ItemUom::query()
            ->where('item_id', $item->id)
            ->with(['uom:id,code,name,unit_group_id', 'currency:id,code,name,symbol,iso_code'])
            ->where('is_default_sale', true)
            ->first()
            ?? ItemUom::query()
                ->where('item_id', $item->id)
                ->with(['uom:id,code,name,unit_group_id', 'currency:id,code,name,symbol,iso_code'])
                ->where('is_base', true)
                ->first();
    }

    private function clearPrimaryForItem(int $itemId, ?int $exceptId = null): void
    {
        $query = ItemBarcode::query()->where('item_id', $itemId);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        $query->update(['is_primary' => false]);
    }
}
