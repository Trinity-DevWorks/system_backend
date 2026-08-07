<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'active' => ['required', 'boolean'],
            'branch_assignments' => ['required', 'array', 'min:1'],
            'branch_assignments.*.branch_id' => ['required', 'integer', 'exists:branches,id', 'distinct'],
            'branch_assignments.*.role_id' => ['required', 'integer', 'exists:roles,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assignments = $this->input('branch_assignments');
            if (! is_array($assignments) || $assignments === []) {
                return;
            }

            $branchIds = [];
            foreach ($assignments as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $branchId = (int) ($row['branch_id'] ?? 0);
                if ($branchId < 1) {
                    continue;
                }
                if (isset($branchIds[$branchId])) {
                    $validator->errors()->add(
                        "branch_assignments.{$index}.branch_id",
                        'Each branch may only appear once in branch_assignments.'
                    );
                }
                $branchIds[$branchId] = true;
            }
        });
    }
}
