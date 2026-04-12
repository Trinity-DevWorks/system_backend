<?php

namespace App\Modules\VatGroup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVatGroupRequest extends FormRequest
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
            'abrv' => ['required', 'string', 'max:50', 'unique:vat_groups,abrv'],
            'name' => ['required', 'string', 'max:255'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['required', 'boolean'],
        ];
    }
}
