<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Modules\Inventory\Stock\Enums\StockAdjustmentReasonDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentReasonRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', 'unique:stock_adjustment_reasons,code'],
            'name' => ['required', 'string', 'max:120'],
            'direction' => ['required', 'string', Rule::in(StockAdjustmentReasonDirection::values())],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
