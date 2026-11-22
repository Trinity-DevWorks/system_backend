<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportBudgetRequest extends FormRequest
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
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240'],
        ];
    }
}
