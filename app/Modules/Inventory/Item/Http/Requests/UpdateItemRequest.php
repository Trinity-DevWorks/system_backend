<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Category\Support\CategoryTree;
use App\Modules\Inventory\Item\Support\ItemPosFieldValidator;
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
            'item_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('items', 'item_code')->ignore($this->route('item')),
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
            'unit_group_id' => ['sometimes', 'integer', 'exists:unit_groups,id'],
            'vat_group_id' => ['sometimes', 'nullable', 'integer', 'exists:vat_groups,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ticket_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'kitchen_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'send_to_kitchen' => ['sometimes', 'boolean'],
            'qr_enabled' => ['sometimes', 'boolean'],
            'qr_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'pos_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'track_inventory' => ['sometimes', 'boolean'],
            'allow_sale' => ['sometimes', 'boolean'],
            'allow_purchase' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        ItemPosFieldValidator::validateAfter($validator, $this);

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
