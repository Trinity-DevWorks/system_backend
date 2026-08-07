<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
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
