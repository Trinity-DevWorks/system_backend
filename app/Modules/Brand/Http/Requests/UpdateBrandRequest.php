<?php

namespace App\Modules\Brand\Http\Requests;

use App\Modules\Brand\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBrandRequest extends FormRequest
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
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::unique('brands', 'code')->ignore($this->route('brand')),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('parent_brand_id')) {
                return;
            }

            /** @var Brand $brand */
            $brand = $this->route('brand');
            $parentId = $this->input('parent_brand_id');

            if ($parentId === null || $parentId === '') {
                return;
            }

            $parentId = (int) $parentId;

            if ($parentId === $brand->id) {
                $validator->errors()->add('parent_brand_id', 'A brand cannot be its own parent.');

                return;
            }

            if ($this->isDescendant($parentId, $brand->id)) {
                $validator->errors()->add('parent_brand_id', 'A brand cannot be a sub-brand of its own descendant.');
            }
        });
    }

    private function isDescendant(int $candidateParentId, int $brandId): bool
    {
        $currentId = $candidateParentId;

        while ($currentId !== null) {
            if ($currentId === $brandId) {
                return true;
            }

            $currentId = Brand::query()
                ->whereKey($currentId)
                ->value('parent_brand_id');
        }

        return false;
    }
}
