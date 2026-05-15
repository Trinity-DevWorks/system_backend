<?php

declare(strict_types=1);

namespace App\Modules\PaymentTerm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentTermRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64', 'unique:payment_terms,code'],
            'name' => ['required', 'string', 'max:255'],
            'due_days' => ['required', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
