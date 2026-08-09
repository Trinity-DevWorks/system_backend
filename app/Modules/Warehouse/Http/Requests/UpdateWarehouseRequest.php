<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http\Requests;

use App\Modules\Warehouse\Enums\WarehouseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWarehouseRequest extends FormRequest
{
    use ValidatesWarehouseManagerForBranch;

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
            'type' => ['required', 'string', Rule::in(WarehouseType::values())],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'address' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'manager_id' => ['nullable', 'uuid', 'exists:users,id'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'is_default_sales' => ['required', 'boolean'],
            'is_default_production' => ['required', 'boolean'],
            'is_default_purchase' => ['required', 'boolean'],
            'is_default_storage' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = WarehouseType::tryFrom((string) $this->input('type', ''));
            $branchId = $this->input('branch_id');

            if ($type?->requiresBranch() && ($branchId === null || $branchId === '')) {
                $validator->errors()->add('branch_id', 'A branch is required for branch warehouses.');
            }

            if ($type !== null && ! $type->requiresBranch() && $branchId !== null && $branchId !== '') {
                $validator->errors()->add('branch_id', 'Central and distribution warehouses cannot be linked to a branch.');
            }

            $this->validateManagerForBranch($validator);
        });
    }
}
