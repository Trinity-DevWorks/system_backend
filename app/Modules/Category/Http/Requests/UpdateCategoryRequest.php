<?php

namespace App\Modules\Category\Http\Requests;

use App\Modules\Category\Models\Category;
use App\Modules\Category\Support\CategoryTree;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
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
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::unique('categories', 'code')->ignore($category),
            ],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where(function ($query) use ($category): void {
                        $parentId = $this->has('parent_id')
                            ? $this->input('parent_id')
                            : $category->parent_id;
                        if ($parentId === null || $parentId === '') {
                            $query->whereNull('parent_id');
                        } else {
                            $query->where('parent_id', (int) $parentId);
                        }
                    })
                    ->ignore($category),
            ],
            'color' => ['sometimes', 'string', 'max:32', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('parent_id')) {
                return;
            }

            /** @var Category $category */
            $category = $this->route('category');
            $parentId = $this->input('parent_id');

            if ($parentId === null || $parentId === '') {
                return;
            }

            $parentId = (int) $parentId;

            if ($parentId === $category->id) {
                $validator->errors()->add('parent_id', 'A category cannot be its own parent.');

                return;
            }

            if (CategoryTree::isDescendant($parentId, $category->id)) {
                $validator->errors()->add('parent_id', 'A category cannot be placed under its own descendant.');
            }
        });
    }
}
