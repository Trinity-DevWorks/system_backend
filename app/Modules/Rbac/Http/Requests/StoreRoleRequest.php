<?php

namespace App\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'description' => ['nullable', 'string'],
            'active' => ['required', 'boolean'],
            'permissions' => ['required', 'array'],
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
