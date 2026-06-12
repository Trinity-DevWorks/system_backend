<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Http\Requests;

use App\Modules\Salesman\Enums\CommissionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreSalesmanRequest extends FormRequest
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
            'salesman_code' => ['nullable', 'string', 'max:64', 'unique:salesmen,salesman_code'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:salesmen,email'],
            'address' => ['nullable', 'string'],
            'commission_type' => ['required', new Enum(CommissionType::class)],
            'commission_value' => [
                'nullable',
                'numeric',
                'min:0',
                Rule::requiredIf(fn (): bool => in_array(
                    (string) $this->input('commission_type'),
                    [CommissionType::Percent->value, CommissionType::Fixed->value],
                    true
                )),
                Rule::when(
                    (string) $this->input('commission_type') === CommissionType::Percent->value,
                    ['max:100']
                ),
            ],
            'target_amount' => ['nullable', 'numeric', 'min:0'],
            'hire_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id', 'unique:salesmen,user_id'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
