<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Modules\Inventory\Stock\Enums\StockTransferClosureOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseStockTransferOpenRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_transfer_line_id' => ['required', 'integer'],
            'lines.*.outcome' => ['required', 'string', Rule::in(StockTransferClosureOutcome::values())],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'lines.*.stock_adjustment_reason_id' => ['required', 'integer', 'exists:stock_adjustment_reasons,id'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
