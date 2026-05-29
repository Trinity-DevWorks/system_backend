<?php

namespace App\Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarehouseRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:warehouses,name'],
            'shortcut_name' => ['required', 'string', 'max:50', 'unique:warehouses,shortcut_name'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'is_default_sales' => ['required', 'boolean'],
            'is_default_production' => ['required', 'boolean'],
            'is_default_purchase' => ['required', 'boolean'],
            'is_default_storage' => ['required', 'boolean'],
        ];
    }
}
