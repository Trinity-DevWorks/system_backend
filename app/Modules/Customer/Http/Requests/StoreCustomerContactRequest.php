<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerContactRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ];
    }
}
