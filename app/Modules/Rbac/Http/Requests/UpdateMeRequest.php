<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        if ($this->has('phone') && $this->input('phone') === '') {
            $this->merge(['phone' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'preferred_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
