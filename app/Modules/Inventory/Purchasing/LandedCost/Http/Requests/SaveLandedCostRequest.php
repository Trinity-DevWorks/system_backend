<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Http\Requests;

use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostMethod;
use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLandedCostRequest extends FormRequest
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
            'cost_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipts' => ['present', 'array', 'max:50'],
            'receipts.*.source_type' => ['required', Rule::in(LandedCostSourceType::receiptValues())],
            'receipts.*.source_id' => ['required', 'uuid'],
            'charges' => ['present', 'array', 'max:100'],
            'charges.*.source_type' => ['required', Rule::in(LandedCostSourceType::chargeValues())],
            'charges.*.source_line_id' => ['required', 'integer', 'min:1'],
            'charges.*.amount' => ['nullable', 'numeric', 'min:0'],
            'charges.*.method' => ['required', Rule::in(LandedCostMethod::values())],
            'charges.*.description' => ['nullable', 'string', 'max:255'],
            'charges.*.manual' => ['nullable', 'array'],
            'charges.*.manual.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
