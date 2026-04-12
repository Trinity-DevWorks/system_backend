<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemBarcode;
use Illuminate\Support\Facades\DB;

class ItemBarcodeService
{
    /**
     * @param  array{barcode:string,item_unit_of_measurement_id?:int|null,is_primary:bool}  $data
     */
    public function store(Item $item, array $data): ItemBarcode
    {
        return DB::transaction(function () use ($item, $data): ItemBarcode {
            if (! empty($data['is_primary'])) {
                ItemBarcode::query()->where('item_id', $item->id)->update(['is_primary' => false]);
            }

            return ItemBarcode::query()->create([
                'item_id' => $item->id,
                'item_unit_of_measurement_id' => $data['item_unit_of_measurement_id'] ?? null,
                'barcode' => $data['barcode'],
                'is_primary' => $data['is_primary'],
            ]);
        });
    }
}
