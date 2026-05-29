<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Support;

use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Validation\Validator;

final class BarcodeUniqueness
{
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

        $barcodeQuery = ItemBarcode::query()->where('barcode', $normalized);
        if ($ignoreBarcodeId !== null) {
            $barcodeQuery->where('id', '!=', $ignoreBarcodeId);
        }

        if ($barcodeQuery->exists()) {
            $validator->errors()->add('barcode', 'This barcode is already assigned to another item.');

            return;
        }

        $uomQuery = ItemUom::query()->where('barcode', $normalized);
        if ($ignoreItemUomId !== null) {
            $uomQuery->where('id', '!=', $ignoreItemUomId);
        }

        if ($uomQuery->exists()) {
            $validator->errors()->add('barcode', 'This barcode is already used on an item unit of measurement.');
        }
    }
}
