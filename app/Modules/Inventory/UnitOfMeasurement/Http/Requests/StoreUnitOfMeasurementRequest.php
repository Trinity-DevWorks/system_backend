<?php

namespace App\Modules\Inventory\UnitOfMeasurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class StoreUnitOfMeasurementRequest extends FormRequest
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
            'unit_group_id' => ['required', 'integer', 'exists:unit_groups,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:32'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Configure the validator after rules are set (scoped uniqueness per group).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $groupId = (int) $this->input('unit_group_id');
            if ($groupId <= 0) {
                return;
            }

            $this->validateUniqueInGroup($validator, 'code', $groupId);
            $this->validateUniqueInGroup($validator, 'name', $groupId);
        });
    }

    private function validateUniqueInGroup(Validator $validator, string $field, int $groupId): void
    {
        $value = $this->input($field);
        if ($value === null || $value === '') {
            return;
        }

        $exists = DB::table('unit_of_measurements')
            ->where('unit_group_id', $groupId)
            ->where($field, $value)
            ->exists();

        if ($exists) {
            $validator->errors()->add($field, "The {$field} has already been taken for this unit group.");
        }
    }
}
