<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRefundRequest extends FormRequest
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
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'refund_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
