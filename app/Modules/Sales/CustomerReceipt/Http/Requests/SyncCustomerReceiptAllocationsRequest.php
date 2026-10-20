<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerReceipt\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncCustomerReceiptAllocationsRequest extends FormRequest
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
            'allocations' => ['present', 'array'],
            'allocations.*.sales_invoice_id' => ['required', 'uuid', 'distinct', 'exists:sales_invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
