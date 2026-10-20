<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplySalesCreditNoteRequest extends FormRequest
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
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.sales_invoice_id' => ['required', 'uuid', 'distinct', 'exists:sales_invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
