<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\BarcodeUniqueness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'barcode' => ['required', 'string', 'max:128'],
            'item_uom_id' => [
                'nullable',
                'integer',
                Rule::exists('item_uoms', 'id')->where('item_id', $itemId),
            ],
            'is_primary' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $barcode = (string) $this->input('barcode');
            BarcodeUniqueness::validateUnique($validator, $barcode);
        });
    }
}
