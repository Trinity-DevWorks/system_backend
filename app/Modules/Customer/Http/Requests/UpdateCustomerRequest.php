<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->route('customer');
        if (! $customer instanceof Customer) {
            return;
        }

        $status = $this->has('status')
            ? $this->input('status')
            : ($customer->status instanceof CustomerStatus
                ? $customer->status->value
                : (string) $customer->status);
        if ($status !== CustomerStatus::Blacklisted->value) {
            $this->merge(['blacklist_reason' => null]);
        }

        $exempted = $this->has('is_exempted')
            ? $this->boolean('is_exempted')
            : (bool) $customer->is_exempted;
        if (! $exempted) {
            $this->merge([
                'exemption_reason' => null,
                'exempted_from' => null,
                'exempted_to' => null,
            ]);
        }
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
            'salesman_id' => ['sometimes', 'nullable', 'integer', 'exists:salesmen,id'],
            'payment_method_id' => ['sometimes', 'nullable', 'integer', 'exists:payment_methods,id'],
            'payment_terms_id' => ['sometimes', 'nullable', 'integer', 'exists:payment_terms,id'],
            'vat_group_id' => ['sometimes', 'nullable', 'integer', 'exists:vat_groups,id'],
            'type' => ['sometimes', 'string', Rule::in(['individual', 'business'])],
            'status' => ['sometimes', 'string', new Enum(CustomerStatus::class)],
            'blacklist_reason' => ['sometimes', 'nullable', 'string', 'required_if:status,'.CustomerStatus::Blacklisted->value],
            'is_exempted' => ['sometimes', 'boolean'],
            'exemption_reason' => ['sometimes', 'nullable', 'string', 'required_if:is_exempted,true'],
            'exempted_from' => ['sometimes', 'nullable', 'date', 'required_with:exempted_to'],
            'exempted_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:exempted_from'],
            'currency_balances' => ['sometimes', 'array'],
            'currency_balances.*.currency_id' => ['required', 'integer', 'exists:currencies,id', 'distinct'],
            'currency_balances.*.opening_balance' => ['sometimes', 'nullable', 'numeric'],
            'currency_balances.*.opening_date' => ['sometimes', 'nullable', 'date'],
            'currency_balances.*.credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'credit_limit' => ['prohibited'],
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

            $effectiveStatus = $this->has('status')
                ? $this->input('status')
                : ($customer->status instanceof CustomerStatus
                    ? $customer->status->value
                    : (string) $customer->status);
            if ($effectiveStatus === CustomerStatus::Blacklisted->value) {
                $reason = $this->has('blacklist_reason')
                    ? $this->input('blacklist_reason')
                    : $customer->blacklist_reason;
                if ($reason === null || $reason === '') {
                    $v->errors()->add('blacklist_reason', 'Blacklist reason is required when status is blacklisted.');
                }
            }

            $effectiveExempt = $this->has('is_exempted')
                ? $this->boolean('is_exempted')
                : (bool) $customer->is_exempted;
            if ($effectiveExempt) {
                $exReason = $this->has('exemption_reason')
                    ? $this->input('exemption_reason')
                    : $customer->exemption_reason;
                if ($exReason === null || $exReason === '') {
                    $v->errors()->add('exemption_reason', 'Exemption reason is required when exempted.');
                }
            }
        });
    }
}
