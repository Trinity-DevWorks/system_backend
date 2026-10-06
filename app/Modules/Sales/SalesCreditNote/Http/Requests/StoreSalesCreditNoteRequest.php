<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('credit_date')) {
            $this->merge(['credit_date' => now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sales_invoice_id' => ['required', 'uuid', 'exists:sales_invoices,id'],
            'credit_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array'],
            'lines.*.sales_invoice_line_id' => ['required', 'integer', 'distinct', 'exists:sales_invoice_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
