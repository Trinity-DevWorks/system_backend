<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('refund_date')) {
            $this->merge(['refund_date' => now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            // Empty: the payment method's linked account for this currency and branch, filled at post.
            'cash_bank_account_id' => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'refund_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['sometimes', 'array'],
            'allocations.*.sales_credit_note_id' => ['required', 'uuid', 'distinct', 'exists:sales_credit_notes,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
