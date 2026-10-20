<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncCustomerRefundAllocationsRequest extends FormRequest
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
            'allocations.*.sales_credit_note_id' => ['required', 'uuid', 'distinct', 'exists:sales_credit_notes,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
