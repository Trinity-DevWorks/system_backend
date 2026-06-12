<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Requests;

use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSupplierItemRequest extends FormRequest
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
        $supplier = $this->route('supplier');
        $supplierId = is_object($supplier) ? $supplier->id : 0;

        return [
            'item_id' => [
                'required',
                'uuid',
                'exists:items,id',
                Rule::unique('supplier_items', 'item_id')->where('supplier_id', $supplierId),
            ],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'last_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_preferred' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $item = Item::query()->find($this->input('item_id'));
            if (! $item) {
                return;
            }

            if (! $item->allow_purchase) {
                $validator->errors()->add('item_id', 'This item is not enabled for purchasing.');
            }

            if (! $item->is_active) {
                $validator->errors()->add('item_id', 'Cannot link an inactive item.');
            }
        });
    }
}
