<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordInvoiceProofDisputeRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
            'tx_hash' => ['sometimes', 'nullable', 'string', 'regex:/^0x[0-9a-fA-F]{64}$/'],
        ];
    }
}
