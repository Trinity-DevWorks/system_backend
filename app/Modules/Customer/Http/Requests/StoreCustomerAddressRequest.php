<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerAddressRequest extends FormRequest
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
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
