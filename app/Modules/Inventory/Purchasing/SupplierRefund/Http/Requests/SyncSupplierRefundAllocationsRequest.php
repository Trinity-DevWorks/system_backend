<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncSupplierRefundAllocationsRequest extends FormRequest
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
            'allocations.*.purchase_debit_note_id' => ['required', 'uuid', 'distinct', 'exists:purchase_debit_notes,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
