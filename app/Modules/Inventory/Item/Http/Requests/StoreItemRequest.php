<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Category\Support\CategoryTree;
use App\Modules\Inventory\Item\Support\ItemPosFieldValidator;
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
            'item_code' => ['nullable', 'string', 'max:100', 'unique:items,item_code'],
            'plu_code' => ['nullable', 'string', 'max:100', 'unique:items,plu_code'],
            'item_type_id' => ['required', 'integer', 'exists:item_types,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_group_id' => ['required', 'integer', 'exists:unit_groups,id'],
            'vat_group_id' => ['nullable', 'integer', 'exists:vat_groups,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'ticket_name' => ['nullable', 'string', 'max:120'],
            'kitchen_name' => ['nullable', 'string', 'max:120'],
            'send_to_kitchen' => ['nullable', 'boolean'],
            'qr_enabled' => ['nullable', 'boolean'],
            'qr_description' => ['nullable', 'string', 'max:1000'],
            'pos_name' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'track_inventory' => ['nullable', 'boolean'],
            'allow_sale' => ['nullable', 'boolean'],
            'allow_purchase' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        ItemPosFieldValidator::validateAfter($validator, $this);

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
