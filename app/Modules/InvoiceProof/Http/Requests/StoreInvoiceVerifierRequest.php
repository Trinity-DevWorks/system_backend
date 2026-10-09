<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Http\Requests;

use App\Modules\InvoiceProof\Enums\InvoicePartySide;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            'party_side' => ['required', new Enum(InvoicePartySide::class)],
            'wallet_address' => [
                'required',
                'string',
                'regex:'.WalletAddress::PATTERN,
                'not_in:'.WalletAddress::ZERO,
                Rule::unique('invoice_verifiers', 'wallet_address')->where(
                    'party_side',
                    (string) $this->input('party_side'),
                ),
            ],
            'wallet_type' => ['required', new Enum(WalletType::class)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if ($this->input('party_side') === InvoicePartySide::Buyer->value
                && $this->input('role') === InvoiceVerifierRole::Financier->value) {
                $validator->errors()->add('role', 'A purchase verifier cannot be a financier.');
            }
        });
    }
}
