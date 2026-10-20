<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VatReportRequest extends FormRequest
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
        $isExport = str_ends_with((string) $this->path(), '/export');

        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'format' => [$isExport ? 'required' : 'nullable', Rule::in(['xlsx', 'pdf'])],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
        ];
    }

    public function dateFrom(): ?string
    {
        $value = $this->validated('from');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function dateTo(): ?string
    {
        $value = $this->validated('to');

        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
