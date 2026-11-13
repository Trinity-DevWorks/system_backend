<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepreciationRunRequest extends FormRequest
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
            'month' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
