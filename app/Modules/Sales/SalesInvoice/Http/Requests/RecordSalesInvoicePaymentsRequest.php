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
            'keep_extra_as_credit' => ['sometimes', 'boolean'],
            ...SyncSalesInvoicePaymentsRequest::paymentRowRules(),
        ];
    }
}
