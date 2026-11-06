<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Money received later against a posted invoice (each row becomes a receipt).
 */
class RecordSalesInvoicePaymentsRequest extends FormRequest
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
            'payment_date' => ['nullable', 'date'],
            'payments' => ['required', 'array', 'min:1', 'max:20'],
            'payments.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'payments.*.cash_bank_account_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'payments.*.currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'payments.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:128'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
        ];
    }
}
