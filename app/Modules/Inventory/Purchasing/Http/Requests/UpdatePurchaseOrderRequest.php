<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
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
            'supplier_id' => ['sometimes', 'uuid', 'exists:suppliers,id'],
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'order_date' => ['sometimes', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
