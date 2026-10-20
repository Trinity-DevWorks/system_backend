<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Requests;

use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'type' => ['required', Rule::enum(SalesCreditNoteType::class)],
            'credit_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'adjustment' => ['nullable', 'numeric'],
            'lines' => ['sometimes', 'array'],
            'lines.*.sales_invoice_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.restock' => ['sometimes', 'boolean'],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
