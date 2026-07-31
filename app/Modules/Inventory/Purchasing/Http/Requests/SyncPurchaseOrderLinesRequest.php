<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncPurchaseOrderLinesRequest extends FormRequest
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
            'lines' => ['present', 'array'],
            'lines.*.item_id' => ['required', 'uuid', 'distinct', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'lines.*.item_uom_id' => ['nullable', 'integer', Rule::exists('item_uoms', 'id')],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
