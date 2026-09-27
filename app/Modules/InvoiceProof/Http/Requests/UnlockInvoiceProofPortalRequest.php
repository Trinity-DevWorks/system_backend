<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Foundation\Http\FormRequest;

class UnlockInvoiceProofPortalRequest extends FormRequest
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
            'address' => ['required', 'string', 'regex:'.WalletAddress::PATTERN],
            'signature' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{130}$/'],
            'nonce' => ['sometimes', 'string', 'regex:/^[0-9a-f]{32}$/'],
        ];
    }
}
