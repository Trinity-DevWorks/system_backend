<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachItemUomRequest extends FormRequest
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
            'unit_of_measurement_id' => ['required', 'integer', 'exists:unit_of_measurements,id'],
            'operation' => ['required', 'string', 'in:multiply,divide'],
            'conversion' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'price_1' => ['nullable', 'numeric', 'min:0'],
            'price_2' => ['nullable', 'numeric', 'min:0'],
            'price_3' => ['nullable', 'numeric', 'min:0'],
            'price_4' => ['nullable', 'numeric', 'min:0'],
            'price_5' => ['nullable', 'numeric', 'min:0'],
            'price_6' => ['nullable', 'numeric', 'min:0'],
            'gross_volume' => ['nullable', 'numeric', 'min:0'],
            'gross_weight' => ['nullable', 'numeric', 'min:0'],
            'net_volume' => ['nullable', 'numeric', 'min:0'],
            'net_weight' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
