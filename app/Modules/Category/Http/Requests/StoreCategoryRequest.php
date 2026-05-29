<?php

namespace App\Modules\Category\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::unique('categories', 'code'),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->where(function ($query): void {
                    $parentId = $this->input('parent_id');
                    if ($parentId === null || $parentId === '') {
                        $query->whereNull('parent_id');
                    } else {
                        $query->where('parent_id', (int) $parentId);
                    }
                }),
            ],
            'color' => ['required', 'string', 'max:32', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
