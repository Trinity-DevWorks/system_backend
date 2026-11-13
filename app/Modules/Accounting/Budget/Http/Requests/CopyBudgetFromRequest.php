<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CopyBudgetFromRequest extends FormRequest
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
            'source_budget_id' => ['required', 'uuid', 'exists:budgets,id'],
            'percent' => ['nullable', 'numeric', 'min:-1000', 'max:1000'],
        ];
    }
}
