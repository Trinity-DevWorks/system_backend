<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePurchaseOrdersFromAlertsRequest extends FormRequest
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
            'replenishment_ids' => ['required', 'array', 'min:1'],
            'replenishment_ids.*' => ['required', 'integer', 'distinct', Rule::exists('item_warehouse_replenishments', 'id')],
            'supplier_overrides' => ['sometimes', 'array'],
            'supplier_overrides.*' => ['required', 'uuid', 'exists:suppliers,id'],
            'preview' => ['sometimes', 'boolean'],
        ];
    }
}
