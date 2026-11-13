<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExpenseVoucherRequest extends FormRequest
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
        $required = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'voucher_date' => [$required, 'date'],
            'cash_bank_account_id' => [$required, 'integer', Rule::exists('cash_bank_accounts', 'id')],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'payee' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'uuid', Rule::exists('suppliers', 'id')],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => [$required, 'array', 'max:200'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.vat_group_id' => ['nullable', 'integer', Rule::exists('vat_groups', 'id')],
            'lines.*.deferral_start_date' => ['nullable', 'date'],
            'lines.*.deferral_end_date' => ['nullable', 'date', 'after_or_equal:lines.*.deferral_start_date', 'required_with:lines.*.deferral_start_date'],
            'lines.*.deferral_recognition_account_id' => ['nullable', 'integer', 'required_with:lines.*.deferral_start_date'],
            'lines.*.deferral_method' => ['nullable', Rule::in(['straight_line_monthly', 'daily_prorata'])],
        ];
    }
}
