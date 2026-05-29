<?php

namespace App\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
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
                Rule::unique('roles', 'name')->ignore($this->route('role')),
            ],
            'description' => ['nullable', 'string'],
            'active' => ['required', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*.permission_id' => ['required', 'integer', 'exists:permissions,id'],
            'permissions.*.can_view' => ['required', 'boolean'],
            'permissions.*.can_add' => ['required', 'boolean'],
            'permissions.*.can_edit' => ['required', 'boolean'],
            'permissions.*.can_delete' => ['required', 'boolean'],
            'permissions.*.can_import' => ['required', 'boolean'],
            'permissions.*.can_export' => ['required', 'boolean'],
        ];
    }
}
