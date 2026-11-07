<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgingReportRequest extends FormRequest
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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
            'format' => [$isExport ? 'required' : 'nullable', Rule::in(['xlsx', 'pdf'])],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
        ];
    }

    public function asOf(): ?string
    {
        $value = $this->validated('as_of');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function branchId(): ?int
    {
        $value = $this->validated('branch_id');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function customerId(): ?string
    {
        $value = $this->validated('customer_id');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function supplierId(): ?string
    {
        $value = $this->validated('supplier_id');

        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
