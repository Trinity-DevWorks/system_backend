<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockAdjustmentRequest extends FormRequest
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
            'stock_adjustment_reason_id' => ['sometimes', 'integer', 'exists:stock_adjustment_reasons,id'],
            'adjustment_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
