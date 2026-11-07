<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PeriodReportRequest extends FormRequest
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
            'group_by' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
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

    public function groupBy(): ?string
    {
        $value = $this->validated('group_by');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function branchId(): ?int
    {
        $value = $this->validated('branch_id');

        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
