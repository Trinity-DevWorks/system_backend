<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Foundation\Http\FormRequest;

class ResumeInvoiceProofPortalRequest extends FormRequest
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
            'session' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'address' => ['required', 'string', 'regex:'.WalletAddress::PATTERN],
        ];
    }
}
