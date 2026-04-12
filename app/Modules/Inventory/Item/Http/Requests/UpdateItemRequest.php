<?php

namespace App\Modules\Inventory\Item\Http\Requests;

use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
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
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('items', 'code')->ignore($this->route('item')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:stockable,service,non_stock'],
            'base_uom_id' => ['nullable', 'integer', 'exists:unit_of_measurements,id'],
            'purchase_uom_id' => ['nullable', 'integer', 'exists:unit_of_measurements,id'],
            'sales_uom_id' => ['nullable', 'integer', 'exists:unit_of_measurements,id'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('type');
            $baseId = $this->input('base_uom_id');

            if ($type === 'stockable' && empty($baseId)) {
                $validator->errors()->add('base_uom_id', 'Base unit of measurement is required for stockable items.');
            }

            if ($type !== 'stockable' && $baseId) {
                $validator->errors()->add('base_uom_id', 'Base UOM should only be set for stockable items.');
            }

            $this->assertSameGroup($validator, $baseId, $this->input('purchase_uom_id'), 'purchase_uom_id');
            $this->assertSameGroup($validator, $baseId, $this->input('sales_uom_id'), 'sales_uom_id');
        });
    }

    private function assertSameGroup(Validator $validator, mixed $baseId, mixed $otherId, string $field): void
    {
        if (empty($baseId) || empty($otherId)) {
            return;
        }

        $base = UnitOfMeasurement::query()->find((int) $baseId);
        $other = UnitOfMeasurement::query()->find((int) $otherId);

        if ($base && $other && $base->unit_group_id !== $other->unit_group_id) {
            $validator->errors()->add($field, 'Must belong to the same unit group as the base UOM.');
        }
    }
}
