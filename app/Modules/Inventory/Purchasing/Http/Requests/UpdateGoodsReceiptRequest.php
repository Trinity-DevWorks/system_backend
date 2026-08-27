<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGoodsReceiptRequest extends FormRequest
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
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
            'received_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
