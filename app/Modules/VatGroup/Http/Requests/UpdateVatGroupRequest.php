<?php

namespace App\Modules\VatGroup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVatGroupRequest extends FormRequest
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
            'abrv' => [
                'required',
                'string',
                'max:50',
                Rule::unique('vat_groups', 'abrv')->ignore($this->route('vat_group')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['required', 'boolean'],
        ];
    }
}
