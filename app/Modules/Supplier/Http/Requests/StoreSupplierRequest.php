<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:suppliers,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'supplier_group_id' => ['nullable', 'integer', 'exists:supplier_groups,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
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
