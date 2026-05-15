<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Requests;

use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $supplier = $this->route('supplier');
        if (! $supplier instanceof Supplier) {
            return;
        }

        $exempted = $this->has('is_exempted')
            ? $this->boolean('is_exempted')
            : (bool) $supplier->is_exempted;
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
        $supplier = $this->route('supplier');
        $supplierId = is_object($supplier) ? $supplier->id : $supplier;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('suppliers', 'email')->ignore($supplierId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'supplier_group_id' => ['sometimes', 'nullable', 'integer', 'exists:supplier_groups,id'],
            'payment_method_id' => ['sometimes', 'nullable', 'integer', 'exists:payment_methods,id'],
            'payment_terms_id' => ['sometimes', 'nullable', 'integer', 'exists:payment_terms,id'],
            'vat_group_id' => ['sometimes', 'nullable', 'integer', 'exists:vat_groups,id'],
            'currency_balances' => ['sometimes', 'array'],
            'currency_balances.*.currency_id' => ['required', 'integer', 'exists:currencies,id', 'distinct'],
            'currency_balances.*.opening_balance' => ['sometimes', 'nullable', 'numeric'],
            'currency_balances.*.opening_date' => ['sometimes', 'nullable', 'date'],
            'currency_balances.*.credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'is_exempted' => ['sometimes', 'boolean'],
            'exemption_reason' => ['sometimes', 'nullable', 'string', 'required_if:is_exempted,true'],
            'exempted_from' => ['sometimes', 'nullable', 'date', 'required_with:exempted_to'],
            'exempted_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:exempted_from'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'credit_limit' => ['prohibited'],
            'opening_balance' => ['prohibited'],
            'supplier_code' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $v): void {
            $supplier = $this->route('supplier');
            if (! $supplier instanceof Supplier) {
                return;
            }

            $registered = $this->has('is_vat_registered')
                ? $this->boolean('is_vat_registered')
                : (bool) $supplier->is_vat_registered;

            if ($registered) {
                $effectiveVat = $this->has('vat_number')
                    ? $this->input('vat_number')
                    : $supplier->vat_number;
                if ($effectiveVat === null || $effectiveVat === '') {
                    $v->errors()->add('vat_number', 'VAT number is required when VAT registered.');
                }
            }

            if (! $registered && $this->filled('vat_number')) {
                $v->errors()->add('vat_number', 'VAT number is not allowed when not VAT registered.');
            }

            $effectiveExempt = $this->has('is_exempted')
                ? $this->boolean('is_exempted')
                : (bool) $supplier->is_exempted;
            if ($effectiveExempt) {
                $exReason = $this->has('exemption_reason')
                    ? $this->input('exemption_reason')
                    : $supplier->exemption_reason;
                if ($exReason === null || $exReason === '') {
                    $v->errors()->add('exemption_reason', 'Exemption reason is required when exempted.');
                }
            }
        });
    }
}
