<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncomeStatementRequest extends FormRequest
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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'compare' => ['nullable', Rule::in(['none', 'previous_period', 'previous_year'])],
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

    public function branchId(): ?int
    {
        $value = $this->validated('branch_id');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function level(): ?int
    {
        $value = $this->validated('level');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function compare(): string
    {
        $value = $this->validated('compare');

        return $value !== null && $value !== '' ? (string) $value : 'none';
    }
}
