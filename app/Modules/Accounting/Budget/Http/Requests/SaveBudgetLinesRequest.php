<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBudgetLinesRequest extends FormRequest
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
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.period_month' => ['required', 'date'],
            'lines.*.amount' => ['required', 'numeric'],
        ];
    }
}
