<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('branch_id') === '' || $this->input('branch_id') === null) {
            $this->merge(['branch_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1900', 'max:2200'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
