<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BalanceSheetRequest extends FormRequest
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
            'as_of' => ['nullable', 'date'],
            'compare_as_of' => ['nullable', 'date'],
            'level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'format' => [$isExport ? 'required' : 'nullable', Rule::in(['xlsx', 'pdf'])],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
        ];
    }

    public function asOf(): ?string
    {
        $value = $this->validated('as_of');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function compareAsOf(): ?string
    {
        $value = $this->validated('compare_as_of');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function level(): ?int
    {
        $value = $this->validated('level');

        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
