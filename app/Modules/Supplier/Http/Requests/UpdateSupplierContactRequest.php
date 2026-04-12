<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierContactRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
