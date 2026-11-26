<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Validation\Validator;

final class BarcodeUniqueness
{
    /**
     * A barcode belongs to one item and unit only (item_barcodes, also unique in the database).
     * `$ignoreBarcodeId`: the barcode row being edited. `$ignoreItemUomId`: the unit whose own barcode is being edited.
     */
    public static function validateUnique(
        Validator $validator,
        string $barcode,
        ?int $ignoreBarcodeId = null,
        ?int $ignoreItemUomId = null,
    ): void {
        $normalized = trim($barcode);
        if ($normalized === '') {
            return;
        }

        $query = ItemBarcode::query()->where('barcode', $normalized);
        if ($ignoreBarcodeId !== null) {
            $query->where('id', '!=', $ignoreBarcodeId);
        }
        if ($ignoreItemUomId !== null) {
            $own = ItemUom::query()->find($ignoreItemUomId)?->primaryBarcode?->id;
            if ($own !== null) {
                $query->where('id', '!=', $own);
            }
        }
        if ($query->exists()) {
            $validator->errors()->add('barcode', 'This barcode is already assigned to another item.');
        }
    }
}
