<?php

namespace App\Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('warehouses', 'name')->ignore($this->route('warehouse')),
            ],
            'shortcut_name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('warehouses', 'shortcut_name')->ignore($this->route('warehouse')),
            ],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ];
    }
}
