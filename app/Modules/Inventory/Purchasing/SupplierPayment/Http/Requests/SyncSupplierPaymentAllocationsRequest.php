<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSupplierPaymentAllocationsRequest extends FormRequest
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
            'allocations.*.purchase_invoice_id' => ['required', 'uuid', 'distinct', 'exists:purchase_invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
