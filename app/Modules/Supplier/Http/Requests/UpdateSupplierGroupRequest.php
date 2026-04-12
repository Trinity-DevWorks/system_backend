<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierGroupRequest extends FormRequest
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
        ];
    }
}
