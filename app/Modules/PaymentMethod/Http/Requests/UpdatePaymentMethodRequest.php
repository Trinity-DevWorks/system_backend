<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Http\Requests;

use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdatePaymentMethodRequest extends FormRequest
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
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->route('payment_method');

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('payment_methods', 'code')->ignore($paymentMethod->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(PaymentMethodType::class)],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'requires_reference' => ['required', 'boolean'],
            'supports_change' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
