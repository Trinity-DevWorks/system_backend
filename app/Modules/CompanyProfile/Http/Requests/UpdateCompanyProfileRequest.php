<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Http\Requests;

use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $typeFields = [
            'wallet_address' => 'wallet_type',
            'wallet_address_anvil' => 'wallet_type_anvil',
            'wallet_address_sepolia' => 'wallet_type_sepolia',
        ];
        foreach ($typeFields as $field => $typeField) {
            if (! $this->exists($field)) {
                continue;
            }

            $raw = $this->input($field);
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $this->merge([$field => null, $typeField => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address' => ['sometimes', 'nullable', 'string'],
            'wallet_address' => ['sometimes', ...WalletAddress::optionalRule()],
            'wallet_address_anvil' => ['sometimes', ...WalletAddress::optionalRule()],
            'wallet_address_sepolia' => ['sometimes', ...WalletAddress::optionalRule()],
            'wallet_type' => ['sometimes', 'nullable', 'required_with:wallet_address', new Enum(WalletType::class)],
            'wallet_type_anvil' => ['sometimes', 'nullable', 'required_with:wallet_address_anvil', new Enum(WalletType::class)],
            'wallet_type_sepolia' => ['sometimes', 'nullable', 'required_with:wallet_address_sepolia', new Enum(WalletType::class)],
        ];
    }
}
