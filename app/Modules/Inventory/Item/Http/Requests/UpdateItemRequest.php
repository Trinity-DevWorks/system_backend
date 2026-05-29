<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Category\Support\CategoryTree;
use App\Modules\Inventory\Item\Support\ItemTypeDefaults;
use App\Modules\Inventory\ItemType\Models\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateItemRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('items', 'sku')->ignore($this->route('item')),
            ],
            'plu_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('items', 'plu_code')->ignore($this->route('item')),
            ],
            'item_type_id' => ['sometimes', 'integer', 'exists:item_types,id'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'base_uom_id' => ['sometimes', 'integer', 'exists:unit_of_measurements,id'],
            'vat_group_id' => ['sometimes', 'nullable', 'integer', 'exists:vat_groups,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'track_inventory' => ['sometimes', 'boolean'],
            'allow_sale' => ['sometimes', 'boolean'],
            'allow_purchase' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $item = $this->route('item');
            $typeId = $this->input('item_type_id', $item?->item_type_id);

            if (empty($typeId)) {
                return;
            }

            $type = ItemType::query()->where('is_active', true)->find((int) $typeId);
            if (! $type) {
                $validator->errors()->add('item_type_id', 'The selected item type is invalid or inactive.');

                return;
            }

            $trackInventory = $this->has('track_inventory')
                ? (bool) $this->boolean('track_inventory')
                : (bool) ($item?->track_inventory ?? ItemTypeDefaults::flagsForCode($type->code)['track_inventory']);

            $baseUomId = $this->input('base_uom_id', $item?->base_uom_id);

            if ($trackInventory && empty($baseUomId)) {
                $validator->errors()->add('base_uom_id', 'Base unit of measurement is required when inventory is tracked.');
            }

            if ($this->has('category_id')) {
                $categoryId = $this->input('category_id');
                if (! empty($categoryId) && ! CategoryTree::isLeaf((int) $categoryId)) {
                    $validator->errors()->add(
                        'category_id',
                        'The selected category must be a leaf category (one with no subcategories).'
                    );
                }
            }
        });
    }
}
