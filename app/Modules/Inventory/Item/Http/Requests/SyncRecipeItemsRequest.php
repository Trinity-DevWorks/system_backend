<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncRecipeItemsRequest extends FormRequest
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
        $producedItemId = is_object($item) ? (int) $item->id : 0;

        return [
            'ingredients' => ['present', 'array'],
            'ingredients.*.item_id' => [
                'required',
                'integer',
                'distinct',
                'exists:items,id',
                Rule::notIn([$producedItemId]),
            ],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'ingredients.*.uom_id' => ['required', 'integer', 'exists:unit_of_measurements,id'],
        ];
    }
}
