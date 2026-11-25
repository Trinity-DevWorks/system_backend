<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use Illuminate\Foundation\Http\FormRequest;

class MakeTenantOwnerRequest extends FormRequest
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
            // true: the current owner(s) lose the Owner role and become Admin.
            'demote_others' => ['required', 'boolean'],
        ];
    }
}
