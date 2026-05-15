<?php

declare(strict_types=1);

namespace App\Modules\PaymentTerm\Http\Requests;

use App\Modules\PaymentTerm\Models\PaymentTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentTermRequest extends FormRequest
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
        /** @var PaymentTerm $paymentTerm */
        $paymentTerm = $this->route('payment_term');

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('payment_terms', 'code')->ignore($paymentTerm->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'due_days' => ['required', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
