<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerAddressRequest extends FormRequest
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
            'address_line_1' => ['sometimes', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:120'],
            'state' => ['sometimes', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
