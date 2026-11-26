<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\DTOs\ItemResponseData;
use App\Modules\Inventory\Item\DTOs\ItemUomResponseData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Support\InStoreBarcode;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleBarcodeService;
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
            ])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve a scanned barcode to an item UOM (item_barcodes). An exact
     * barcode always wins; otherwise the scale label formats of the branch are tried
     * (ScaleBarcodeService), which adds a `scale` block with the quantity / label price.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $barcode, ?int $branchId = null): ?array
    {
        $normalized = trim($barcode);
        if ($normalized === '') {
            return null;
        }

        return $this->exactLookup($normalized)
            ?? app(ScaleBarcodeService::class)->resolve($normalized, $branchId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exactLookup(string $normalized): ?array
    {

        $extra = ItemBarcode::query()
            ->where('barcode', $normalized)
            ->with([
                'item.itemType:id,code,name',
                'item.category:id,code,name',
                'item.brand:id,code,name',
                'item.baseUom:id,code,name,unit_group_id',
                'itemUom.uom:id,code,name,unit_group_id',
            ])
            ->first();

        if ($extra) {
            $item = $extra->item;
            $itemUom = $extra->itemUom;

            if (! $item || ! $item->is_active || $item->is_variant_parent) {
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

        return null;
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
     * Adds the next free in-store EAN-13 (200…) to the item. It is the unit's primary barcode
     * when the unit has none yet.
     */
    public function generate(Item $item, ?int $itemUomId): ItemBarcode
    {
        return DB::transaction(function () use ($item, $itemUomId): ItemBarcode {
            // One generator at a time, so two users never get the same number.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('in_store_barcode')]);

            $next = $this->lastInStoreSequence() + 1;
            $barcode = InStoreBarcode::forSequence($next);
            // Skip numbers somebody typed in by hand.
            while ($this->barcodeTaken($barcode)) {
                $barcode = InStoreBarcode::forSequence(++$next);
            }

            $hasBarcode = ItemBarcode::query()
                ->where('item_id', $item->id)
                ->where('item_uom_id', $itemUomId)
                ->exists();

            return $this->store($item, [
                'barcode' => $barcode,
                'item_uom_id' => $itemUomId,
                'is_primary' => ! $hasBarcode,
            ]);
        });
    }

    private function lastInStoreSequence(): int
    {
        $last = 0;
        $codes = ItemBarcode::query()->where('barcode', 'like', InStoreBarcode::PREFIX.'%')->pluck('barcode');
        foreach ($codes as $code) {
            $last = max($last, InStoreBarcode::sequenceOf((string) $code) ?? 0);
        }

        return $last;
    }

    private function barcodeTaken(string $barcode): bool
    {
        return ItemBarcode::query()->where('barcode', $barcode)->exists();
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
        if ((string) $barcode->item_id !== (string) $item->id) {
            abort(404);
        }
    }

    private function resolveItemUomForItem(Item $item): ?ItemUom
    {
        return ItemUom::query()
            ->where('item_id', $item->id)
            ->with(['uom:id,code,name,unit_group_id'])
            ->where('is_default_sale', true)
            ->first()
            ?? ItemUom::query()
                ->where('item_id', $item->id)
                ->with(['uom:id,code,name,unit_group_id'])
                ->where('is_base', true)
                ->first();
    }

    private function clearPrimaryForItem(string $itemId, ?int $exceptId = null): void
    {
        $query = ItemBarcode::query()->where('item_id', $itemId);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        $query->update(['is_primary' => false]);
    }
}
