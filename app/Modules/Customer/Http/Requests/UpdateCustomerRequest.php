<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use App\Modules\Customer\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
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
        $customer = $this->route('customer');
        $customerId = is_object($customer) ? $customer->id : $customer;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customerId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'customer_group_id' => ['sometimes', 'nullable', 'integer', 'exists:customer_groups,id'],
            'type' => ['sometimes', 'string', Rule::in(['individual', 'business'])],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'opening_balance' => ['prohibited'],
            'customer_code' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $v): void {
            $customer = $this->route('customer');
            if (! $customer instanceof Customer) {
                return;
            }

            $registered = $this->has('is_vat_registered')
                ? $this->boolean('is_vat_registered')
                : (bool) $customer->is_vat_registered;

            if ($registered) {
                $effectiveVat = $this->has('vat_number')
                    ? $this->input('vat_number')
                    : $customer->vat_number;
                if ($effectiveVat === null || $effectiveVat === '') {
                    $v->errors()->add('vat_number', 'VAT number is required when VAT registered.');
                }
            }

            if (! $registered && $this->filled('vat_number')) {
                $v->errors()->add('vat_number', 'VAT number is not allowed when not VAT registered.');
            }
        });
    }
}
