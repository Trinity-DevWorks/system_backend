<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT) a price list. On update every field is optional;
 * when `items` is sent it replaces all prices of the list.
 */
class SavePriceListRequest extends FormRequest
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
        $updating = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $listId = $this->route('price_list');
        $listId = is_object($listId) ? $listId->getKey() : $listId;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:50', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', Rule::unique('price_lists', 'code')->ignore($listId)],
            'name' => [$required, 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'currency_id' => [$required, 'integer', Rule::exists('currencies', 'id')],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array', 'max:5000'],
            'items.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')],
            'items.*.item_uom_id' => ['required', 'integer'],
            'items.*.min_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
