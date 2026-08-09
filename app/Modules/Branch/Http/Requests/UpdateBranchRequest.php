<?php

declare(strict_types=1);

namespace App\Modules\Branch\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBranchRequest extends FormRequest
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
                'max:255',
                Rule::unique('branches', 'name')->ignore($this->route('branch')),
            ],
            'shortcut_name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'shortcut_name')->ignore($this->route('branch')),
            ],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'manager_id' => ['nullable', 'uuid', 'exists:users,id'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $opening = $this->input('opening_time');
            $closing = $this->input('closing_time');

            if (! is_string($opening) || ! is_string($closing) || $opening === '' || $closing === '') {
                return;
            }

            if ($closing === $opening) {
                $validator->errors()->add('closing_time', 'Closing time must be different from opening time.');
            }
        });
    }
}
