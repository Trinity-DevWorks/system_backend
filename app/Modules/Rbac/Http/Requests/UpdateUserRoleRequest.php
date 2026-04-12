<?php

namespace App\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRoleRequest extends FormRequest
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
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ];
    }
}
