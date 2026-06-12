<?php

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateItemWarehouseReplenishmentRequest extends FormRequest
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
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'safety_stock_qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999999'],
            'reorder_point_qty' => ['sometimes', 'numeric', 'min:0', 'max:999999999.999999'],
            'reorder_qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999999'],
            'max_qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999999'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $safety = $this->has('safety_stock_qty')
                ? (float) ($this->input('safety_stock_qty') ?? 0)
                : null;
            $reorder = $this->has('reorder_point_qty')
                ? (float) $this->input('reorder_point_qty')
                : null;

            if ($safety === null || $reorder === null) {
                return;
            }

            if ($safety > $reorder) {
                $validator->errors()->add(
                    'safety_stock_qty',
                    'Safety stock cannot be greater than the reorder point.',
                );
            }
        });
    }
}
