<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Http\Requests;

use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StorePaymentMethodRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64', 'unique:payment_methods,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(PaymentMethodType::class)],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'requires_reference' => ['required', 'boolean'],
            'supports_change' => ['required', 'boolean'],
            // Cash boxes / bank accounts this method uses.
            'cash_bank_account_ids' => ['sometimes', 'array', 'max:100'],
            'cash_bank_account_ids.*' => ['integer', 'distinct', 'exists:cash_bank_accounts,id'],
            // Default method of the branch the user is working in.
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
