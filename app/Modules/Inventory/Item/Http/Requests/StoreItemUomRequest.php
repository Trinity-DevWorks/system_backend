<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Inventory\Item\Support\BarcodeUniqueness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItemUomRequest extends FormRequest
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
        return [
            'uom_id' => ['required', 'integer', 'exists:unit_of_measurements,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'conversion_factor' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'barcode' => ['nullable', 'string', 'max:128', 'unique:item_uoms,barcode'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'takeaway_price' => ['nullable', 'numeric', 'min:0'],
            'dine_in_price' => ['nullable', 'numeric', 'min:0'],
            'delivery_price' => ['nullable', 'numeric', 'min:0'],
            'is_base' => ['nullable', 'boolean'],
            'is_default_sale' => ['nullable', 'boolean'],
            'is_default_purchase' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('barcode')) {
                return;
            }

            BarcodeUniqueness::validateUnique($validator, (string) $this->input('barcode'));
        });
    }
}
