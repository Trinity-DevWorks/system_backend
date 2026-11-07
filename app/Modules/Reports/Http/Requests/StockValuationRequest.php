<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockValuationRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'include_zero' => ['sometimes', 'boolean'],
            'format' => [$isExport ? 'required' : 'nullable', Rule::in(['xlsx', 'pdf'])],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
        ];
    }

    public function warehouseId(): ?int
    {
        $value = $this->validated('warehouse_id');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function categoryId(): ?int
    {
        $value = $this->validated('category_id');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function includeZero(): bool
    {
        return $this->boolean('include_zero');
    }
}
