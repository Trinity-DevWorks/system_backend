<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('password') && $this->input('password') === '') {
            $this->merge(['password' => null]);
        }
        if ($this->has('current_password') && $this->input('current_password') === '') {
            $this->merge(['current_password' => null]);
        }
        if ($this->has('phone') && $this->input('phone') === '') {
            $this->merge(['phone' => null]);
        }
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[\p{L}\p{M}][\p{L}\p{M} .\'\x{2019}-]*$/u'],
            'phone' => ['nullable', 'string', 'max:32'],
            'current_password' => ['required_with:password', 'nullable', 'string'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'preferred_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name must contain letters and cannot be numeric.',
            'current_password.required_with' => 'Enter your current password to set a new one.',
        ];
    }
}
