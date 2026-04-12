<?php

namespace App\Modules\SubCategory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubCategoryRequest extends FormRequest
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
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sub_categories', 'name')
                    ->where(fn ($query) => $query->where('category_id', (int) $this->input('category_id')))
                    ->ignore($this->route('sub_category')),
            ],
            'color' => ['required', 'string', 'max:32', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
        ];
    }
}
