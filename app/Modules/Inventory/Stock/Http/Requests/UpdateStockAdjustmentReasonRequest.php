<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Modules\Inventory\Stock\Enums\StockAdjustmentReasonDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockAdjustmentReasonRequest extends FormRequest
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
        $reasonId = $this->route('stock_adjustment_reason')?->id;

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                Rule::unique('stock_adjustment_reasons', 'code')->ignore($reasonId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'direction' => ['sometimes', 'required', 'string', Rule::in(StockAdjustmentReasonDirection::values())],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
