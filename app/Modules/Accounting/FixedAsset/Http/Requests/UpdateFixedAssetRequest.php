<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Requests;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFixedAssetRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', 'exists:fixed_asset_categories,id'],
            'item_id' => ['nullable', 'uuid', 'exists:items,id'],
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
            'serial_number' => ['nullable', 'string', 'max:128'],
            'location' => ['nullable', 'string', 'max:255'],
            'custodian' => ['nullable', 'string', 'max:255'],
            'acquisition_date' => ['sometimes', 'required', 'date'],
            'in_service_date' => ['sometimes', 'required', 'date'],
            'cost' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', Rule::enum(DepreciationMethod::class)],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'declining_rate' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'opening_accumulated' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
