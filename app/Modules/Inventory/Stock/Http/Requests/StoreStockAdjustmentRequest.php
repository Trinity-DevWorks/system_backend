<?php

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
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
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'item_uom_id' => [
                'nullable',
                'integer',
                Rule::exists('item_uoms', 'id')->where(function ($query): void {
                    $query->where('item_id', $this->input('item_id'));
                }),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
