<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportLedgerStatementRequest extends FormRequest
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
            'format' => ['required', Rule::in(['xlsx', 'pdf'])],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
        ];
    }
}
