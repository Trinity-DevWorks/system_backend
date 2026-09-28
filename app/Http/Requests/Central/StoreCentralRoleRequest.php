<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;

class StoreCentralRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', 'unique:central_roles,name'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            ...CentralRolePermissionRules::rules('sometimes'),
        ];
    }
}
