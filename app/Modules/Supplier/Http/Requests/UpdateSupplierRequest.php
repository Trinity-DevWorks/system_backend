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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $supplier = $this->route('supplier');
        $supplierId = is_object($supplier) ? $supplier->id : $supplier;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('suppliers', 'email')->ignore($supplierId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'supplier_group_id' => ['sometimes', 'nullable', 'integer', 'exists:supplier_groups,id'],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string'],
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
        });
    }
}
