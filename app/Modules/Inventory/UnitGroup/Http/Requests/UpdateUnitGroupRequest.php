<?php

namespace App\Modules\Inventory\UnitGroup\Http\Requests;

use App\Modules\Inventory\Shared\Enums\DimensionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitGroupRequest extends FormRequest
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
                'required',
                'string',
                'max:50',
                Rule::unique('unit_groups', 'code')->ignore($this->route('unit_group')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'dimension_type' => ['required', Rule::enum(DimensionType::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
