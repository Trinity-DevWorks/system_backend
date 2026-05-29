<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Inventory\Item\Support\BarcodeUniqueness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateItemUomRequest extends FormRequest
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
        $itemUom = $this->route('item_uom');

        return [
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'conversion_factor' => ['sometimes', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:128',
                Rule::unique('item_uoms', 'barcode')->ignore($itemUom),
            ],
            'selling_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_base' => ['sometimes', 'boolean'],
            'is_default_sale' => ['sometimes', 'boolean'],
            'is_default_purchase' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('barcode')) {
                return;
            }

            $itemUom = $this->route('item_uom');
            $ignoreId = is_object($itemUom) ? $itemUom->id : null;

            BarcodeUniqueness::validateUnique($validator, (string) $this->input('barcode'), null, $ignoreId);
        });
    }
}
