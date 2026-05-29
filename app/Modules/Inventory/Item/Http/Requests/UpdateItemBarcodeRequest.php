<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\BarcodeUniqueness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateItemBarcodeRequest extends FormRequest
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
        $itemBarcode = $this->route('item_barcode');

        return [
            'barcode' => ['sometimes', 'string', 'max:128'],
            'item_uom_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('item_uoms', 'id')->where('item_id', $itemId),
            ],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('barcode')) {
                return;
            }

            $itemBarcode = $this->route('item_barcode');
            $ignoreId = is_object($itemBarcode) ? $itemBarcode->id : null;

            BarcodeUniqueness::validateUnique($validator, (string) $this->input('barcode'), $ignoreId);
        });
    }
}
