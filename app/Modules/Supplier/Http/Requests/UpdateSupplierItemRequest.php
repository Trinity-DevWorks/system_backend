<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierItemRequest extends FormRequest
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
            'supplier_sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'is_preferred' => ['sometimes', 'boolean'],
        ];
    }
}
