<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRecipeItemRequest extends FormRequest
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
            'quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'uom_id' => ['required', 'integer', 'exists:unit_of_measurements,id'],
        ];
    }
}
