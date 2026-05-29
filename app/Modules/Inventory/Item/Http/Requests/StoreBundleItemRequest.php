<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBundleItemRequest extends FormRequest
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
        $bundle = $this->route('item');
        $bundleId = is_object($bundle) ? $bundle->id : 0;

        return [
            'child_item_id' => [
                'required',
                'integer',
                'exists:items,id',
                Rule::unique('bundle_items', 'child_item_id')->where('bundle_item_id', $bundleId),
                Rule::notIn([$bundleId]),
            ],
            'quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];
    }
}
