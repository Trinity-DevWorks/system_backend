<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Requests;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use App\Modules\Accounting\FixedAsset\Models\FixedAssetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FixedAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('fixed_asset_category');
        $id = $category instanceof FixedAssetCategory ? $category->id : null;
        $required = $id === null ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:32', Rule::unique('fixed_asset_categories', 'code')->ignore($id)],
            'name' => [$required, 'string', 'max:255'],
            'asset_account_id' => [$required, 'integer', 'exists:accounts,id'],
            'accumulated_account_id' => [$required, 'integer', 'exists:accounts,id'],
            'expense_account_id' => [$required, 'integer', 'exists:accounts,id'],
            'disposal_gain_account_id' => [$required, 'integer', 'exists:accounts,id'],
            'disposal_loss_account_id' => [$required, 'integer', 'exists:accounts,id'],
            'method' => [$required, Rule::enum(DepreciationMethod::class)],
            'useful_life_months' => [$required, 'integer', 'min:1', 'max:1200'],
            'declining_rate' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'salvage_percent' => [$required, 'numeric', 'min:0', 'max:100'],
            'prorate_first_month' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
