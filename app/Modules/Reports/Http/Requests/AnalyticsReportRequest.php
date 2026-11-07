<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters shared by the analytics reports (sales analysis, profitability, cash flow, general ledger,
 * lot expiry, dead stock, item movement). Each report reads only the filters it needs.
 */
class AnalyticsReportRequest extends FormRequest
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
            'group_by' => ['nullable', Rule::in(['item', 'customer', 'salesman', 'branch', 'category'])],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'item_id' => ['nullable', 'uuid', 'exists:items,id'],
            'days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'include_expired' => ['nullable', 'boolean'],
            'account_from' => ['nullable', 'string', 'max:32'],
            'account_to' => ['nullable', 'string', 'max:32'],
            // Table controls (sales analysis): search, sort and server pagination.
            'search' => ['nullable', 'string', 'max:255'],
            // Each report keeps only the sort keys it supports (unknown keys fall back to its default).
            'sort' => ['nullable', 'string', 'max:32', 'regex:/^[a-z_]+$/'],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'format' => [$isExport ? 'required' : 'nullable', Rule::in(['xlsx', 'pdf'])],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
        ];
    }

    public function textFilter(string $key): ?string
    {
        $value = $this->validated($key);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function intFilter(string $key): ?int
    {
        $value = $this->validated($key);

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function boolFilter(string $key): bool
    {
        return filter_var($this->validated($key) ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
