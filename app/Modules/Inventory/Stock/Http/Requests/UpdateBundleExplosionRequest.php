<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBundleExplosionRequest extends FormRequest
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
            'explosion_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
