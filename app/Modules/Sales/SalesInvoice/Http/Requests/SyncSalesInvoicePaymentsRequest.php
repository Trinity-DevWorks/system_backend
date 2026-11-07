<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payments taken when the invoice is posted (split payment).
 */
class SyncSalesInvoicePaymentsRequest extends FormRequest
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
            'payments' => ['present', 'array', 'max:20'],
            // More paid than the total: the extra stays on the customer's account as credit.
            'keep_extra_as_credit' => ['sometimes', 'boolean'],
            ...self::paymentRowRules(),
        ];
    }

    /**
     * Rules for one payment row, shared with recording payments later.
     * kind=credit applies the customer's existing credit (amount in the invoice currency, no method).
     *
     * @return array<string, mixed>
     */
    public static function paymentRowRules(): array
    {
        return [
            'payments.*.kind' => ['nullable', 'in:payment,credit'],
            'payments.*.payment_method_id' => ['nullable', 'required_unless:payments.*.kind,credit', 'integer', 'exists:payment_methods,id'],
            'payments.*.cash_bank_account_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'payments.*.currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'payments.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:128'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'payments.*.change_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'payments.*.change_rate' => ['nullable', 'numeric', 'gt:0'],
            'payments.*.change_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999999'],
            'payments.*.change_cash_bank_account_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'payments.*.cheque_number' => ['nullable', 'string', 'max:64'],
            'payments.*.cheque_bank' => ['nullable', 'string', 'max:128'],
            'payments.*.cheque_due_date' => ['nullable', 'date'],
        ];
    }
}
