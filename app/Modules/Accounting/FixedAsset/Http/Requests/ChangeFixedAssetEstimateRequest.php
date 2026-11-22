<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeFixedAssetEstimateRequest extends FormRequest
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
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200', 'required_without:salvage_value'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'required_without:useful_life_months'],
        ];
    }
}
