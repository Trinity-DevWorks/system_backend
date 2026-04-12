<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->route('item');
        $itemId = $item instanceof Item ? $item->id : 0;

        return [
            'barcode' => ['required', 'string', 'max:128', 'unique:item_barcodes,barcode'],
            'item_unit_of_measurement_id' => [
                'nullable',
                'integer',
                Rule::exists('item_unit_of_measurement', 'id')->where('item_id', $itemId),
            ],
            'is_primary' => ['required', 'boolean'],
        ];
    }
}
