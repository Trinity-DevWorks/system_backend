<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockCountRequest extends FormRequest
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
            'count_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'load_balances' => ['sometimes', 'boolean'],
            'lines' => ['sometimes', 'array'],
            ...self::lineRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lineRules(): array
    {
        return [
            'lines.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0', 'max:999999.999999'],
            'lines.*.theoretical_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:64'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
