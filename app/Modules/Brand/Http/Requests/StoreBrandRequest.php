<?php

namespace App\Modules\Brand\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBrandRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::unique('brands', 'code'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'parent_brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
