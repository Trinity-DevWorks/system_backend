<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Category\Support\CategoryTree;
use App\Modules\Inventory\Item\Support\ItemTypeDefaults;
use App\Modules\Inventory\ItemType\Models\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', 'unique:items,sku'],
            'plu_code' => ['nullable', 'string', 'max:100', 'unique:items,plu_code'],
            'item_type_id' => ['required', 'integer', 'exists:item_types,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'base_uom_id' => ['required', 'integer', 'exists:unit_of_measurements,id'],
            'vat_group_id' => ['nullable', 'integer', 'exists:vat_groups,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'track_inventory' => ['nullable', 'boolean'],
            'allow_sale' => ['nullable', 'boolean'],
            'allow_purchase' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $typeId = $this->input('item_type_id');
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
                : ItemTypeDefaults::flagsForCode($type->code)['track_inventory'];

            if ($trackInventory && empty($this->input('base_uom_id'))) {
                $validator->errors()->add('base_uom_id', 'Base unit of measurement is required when inventory is tracked.');
            }

            $categoryId = $this->input('category_id');
            if (! empty($categoryId) && ! CategoryTree::isLeaf((int) $categoryId)) {
                $validator->errors()->add(
                    'category_id',
                    'The selected category must be a leaf category (one with no subcategories).'
                );
            }
        });
    }
}
