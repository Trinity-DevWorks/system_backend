<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use App\Modules\Accounting\Enums\AccountRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountMappingsRequest extends FormRequest
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
        $rules = ['mappings' => ['required', 'array']];
        foreach (AccountRole::cases() as $role) {
            $rules['mappings.'.$role->value] = ['required', 'integer', 'exists:accounts,id'];
        }

        return $rules;
    }

    /**
     * @return array<string, int>
     */
    public function mappings(): array
    {
        return array_map('intval', (array) $this->validated('mappings'));
    }
}
