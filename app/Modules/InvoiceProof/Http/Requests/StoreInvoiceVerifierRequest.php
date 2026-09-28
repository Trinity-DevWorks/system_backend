<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreInvoiceVerifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('wallet_address'))) {
            $this->merge(['wallet_address' => strtolower(trim($this->input('wallet_address')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', new Enum(InvoiceVerifierRole::class)],
            'wallet_address' => [
                'required',
                'string',
                'regex:'.WalletAddress::PATTERN,
                'not_in:'.WalletAddress::ZERO,
                'unique:invoice_verifiers,wallet_address',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
