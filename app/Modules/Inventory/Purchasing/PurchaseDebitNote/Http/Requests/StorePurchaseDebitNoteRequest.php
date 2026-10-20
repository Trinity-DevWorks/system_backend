<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Http\Requests;

use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseDebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('debit_date')) {
            $this->merge(['debit_date' => now()->toDateString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_invoice_id' => ['required', 'uuid', 'exists:purchase_invoices,id'],
            'type' => ['required', Rule::enum(PurchaseDebitNoteType::class)],
            'debit_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'adjustment' => ['nullable', 'numeric'],
            'lines' => ['sometimes', 'array'],
            'lines.*.purchase_invoice_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
