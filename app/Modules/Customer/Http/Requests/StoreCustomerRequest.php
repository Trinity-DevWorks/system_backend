<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use App\Modules\Customer\Enums\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('is_vat_registered')) {
            $this->merge([
                'is_vat_registered' => false,
                'vat_number' => null,
            ]);
        }

        $status = $this->input('status', CustomerStatus::Active->value);
        if ($status !== CustomerStatus::Blacklisted->value) {
            $this->merge(['blacklist_reason' => null]);
        }

        if (! $this->boolean('is_exempted')) {
            $this->merge([
                'is_exempted' => false,
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'customer_group_id' => ['nullable', 'integer', 'exists:customer_groups,id'],
            'salesman_id' => ['nullable', 'uuid', 'exists:salesmen,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'payment_terms_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'vat_group_id' => ['nullable', 'integer', 'exists:vat_groups,id'],
            'type' => ['required', 'string', Rule::in(['individual', 'business'])],
            'status' => ['nullable', 'string', new Enum(CustomerStatus::class)],
            'blacklist_reason' => ['nullable', 'string', 'required_if:status,'.CustomerStatus::Blacklisted->value],
            'is_exempted' => ['nullable', 'boolean'],
            'exemption_reason' => ['nullable', 'string', 'required_if:is_exempted,true'],
            'exempted_from' => ['nullable', 'date', 'required_with:exempted_to'],
            'exempted_to' => ['nullable', 'date', 'after_or_equal:exempted_from'],
            'currency_balances' => ['nullable', 'array'],
            'currency_balances.*.currency_id' => ['required', 'integer', 'exists:currencies,id', 'distinct'],
            'currency_balances.*.opening_balance' => ['nullable', 'numeric'],
            'currency_balances.*.opening_date' => ['nullable', 'date'],
            'currency_balances.*.credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_vat_registered' => ['nullable', 'boolean'],
            'vat_number' => ['nullable', 'string', 'max:128', 'required_if:is_vat_registered,true'],
            'notes' => ['nullable', 'string'],

            'addresses' => ['nullable', 'array'],
            'addresses.*.address_line_1' => ['required_with:addresses', 'string', 'max:255'],
            'addresses.*.address_line_2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['required_with:addresses', 'string', 'max:120'],
            'addresses.*.state' => ['required_with:addresses', 'string', 'max:120'],
            'addresses.*.country' => ['required_with:addresses', 'string', 'max:100'],
            'addresses.*.is_default' => ['nullable', 'boolean'],

            'contacts' => ['nullable', 'array'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:32'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
        ];
    }
}
