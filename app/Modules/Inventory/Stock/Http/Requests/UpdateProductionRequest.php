<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductionRequest extends FormRequest
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
            'item_id' => ['sometimes', 'uuid', 'exists:items,id'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'production_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'lot_number' => ['nullable', 'string', 'max:64'],
            'expiry_date' => ['nullable', 'date'],
        ];
    }
}
