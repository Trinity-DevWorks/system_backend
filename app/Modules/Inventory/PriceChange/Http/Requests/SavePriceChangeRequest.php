<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Http\Requests;

use App\Modules\Inventory\PriceChange\Enums\PriceField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePriceChangeRequest extends FormRequest
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
        $required = $updating ? 'sometimes' : 'required';

        return [
            'effective_date' => [$required, 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'max:5000'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'lines.*.item_uom_id' => ['required', 'integer'],
            'lines.*.price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'lines.*.min_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.field' => ['required', Rule::in(PriceField::values())],
            'lines.*.new_value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
