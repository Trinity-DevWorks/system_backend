<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Http\Requests;

use App\Modules\Inventory\PriceChange\Enums\PriceField;
use App\Modules\Inventory\PriceChange\Support\PriceChangeMath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPriceChangeRequest extends FormRequest
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
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'item_ids' => ['nullable', 'array', 'max:5000'],
            'item_ids.*' => ['uuid', 'exists:items,id'],
            'field' => ['required', Rule::in(PriceField::values())],
            'rule' => ['required', Rule::in(PriceChangeMath::RULES)],
            'value' => ['required', 'numeric'],
            'rounding' => ['required', Rule::in(PriceChangeMath::ROUNDINGS)],
        ];
    }
}
