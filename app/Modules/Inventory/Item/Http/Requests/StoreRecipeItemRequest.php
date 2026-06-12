<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecipeItemRequest extends FormRequest
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
        $producedItemId = is_object($item) ? (string) $item->id : '';

        return [
            'item_id' => [
                'required',
                'uuid',
                'exists:items,id',
                Rule::notIn([$producedItemId]),
            ],
            'quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'uom_id' => ['required', 'integer', 'exists:unit_of_measurements,id'],
        ];
    }
}
