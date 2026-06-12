<?php

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItemWarehouseReplenishmentRequest extends FormRequest
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
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'safety_stock_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999.999999'],
            'reorder_point_qty' => ['required', 'numeric', 'min:0', 'max:999999999.999999'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999.999999'],
            'max_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999.999999'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $safety = (float) ($this->input('safety_stock_qty') ?? 0);
            $reorder = (float) $this->input('reorder_point_qty');
            $max = $this->filled('max_qty') ? (float) $this->input('max_qty') : null;

            if ($safety > $reorder) {
                $validator->errors()->add(
                    'safety_stock_qty',
                    'Safety stock cannot be greater than the reorder point.',
                );
            }

            if ($max !== null && $max < $reorder) {
                $validator->errors()->add(
                    'max_qty',
                    'Max quantity cannot be less than the reorder point.',
                );
            }
        });
    }
}
