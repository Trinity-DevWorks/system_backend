<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting in one call: the invoice as the user sees it (header, lines, payments) is saved and posted together.
 * New invoice: the same fields as creating one. Existing draft: only the parts sent are saved (none = post as is).
 */
class PostSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->isNew() && ! $this->has('invoice_date')) {
            $this->merge(['invoice_date' => now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $document = $this->isNew()
            ? (new StoreSalesInvoiceRequest)->rules()
            : (new UpdateSalesInvoiceRequest)->rules();

        return [
            ...$document,
            'payments' => ['sometimes', 'array', 'max:20'],
            'keep_extra_as_credit' => ['sometimes', 'boolean'],
            'approval' => ['nullable', 'array'],
            'approval.approver_id' => ['nullable', 'uuid'],
            'approval.pin' => ['nullable', 'string', 'max:6'],
            ...SyncSalesInvoicePaymentsRequest::paymentRowRules(),
        ];
    }

    /** Sent by the client so a retry (double click, lost response) posts once. */
    public function requestId(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key', ''));

        return $key !== '' && strlen($key) <= 64 ? $key : null;
    }

    private function isNew(): bool
    {
        return $this->route('sales_invoice') === null;
    }
}
