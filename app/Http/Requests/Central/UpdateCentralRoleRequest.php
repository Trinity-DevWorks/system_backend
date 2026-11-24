<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCentralRoleRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('central_roles', 'name')->ignore($this->route('role')),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            ...CentralRolePermissionRules::rules('sometimes'),
        ];
    }
}
