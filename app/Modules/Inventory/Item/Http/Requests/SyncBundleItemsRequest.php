<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncBundleItemsRequest extends FormRequest
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
        $bundleId = is_object($bundle) ? (int) $bundle->id : 0;

        return [
            'components' => ['present', 'array'],
            'components.*.child_item_id' => [
                'required',
                'integer',
                'distinct',
                'exists:items,id',
                Rule::notIn([$bundleId]),
            ],
            'components.*.quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];
    }
}
