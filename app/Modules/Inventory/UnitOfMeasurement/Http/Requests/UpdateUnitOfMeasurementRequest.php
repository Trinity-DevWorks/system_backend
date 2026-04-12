<?php

namespace App\Modules\Inventory\UnitOfMeasurement\Http\Requests;

use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class UpdateUnitOfMeasurementRequest extends FormRequest
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $groupId = (int) $this->input('unit_group_id');
            $uom = $this->route('unit_of_measurement');
            $ignoreId = $uom instanceof UnitOfMeasurement ? $uom->id : 0;

            if ($groupId <= 0 || $ignoreId <= 0) {
                return;
            }

            foreach (['code', 'name'] as $field) {
                $value = $this->input($field);
                if ($value === null || $value === '') {
                    continue;
                }

                $exists = DB::table('unit_of_measurements')
                    ->where('unit_group_id', $groupId)
                    ->where($field, $value)
                    ->where('id', '!=', $ignoreId)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add($field, "The {$field} has already been taken for this unit group.");
                }
            }
        });
    }
}
