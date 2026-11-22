<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SpreadBudgetRequest extends FormRequest
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
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric'],
        ];
    }
}
