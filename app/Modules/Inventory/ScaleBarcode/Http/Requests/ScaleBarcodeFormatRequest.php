<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Requests;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScaleBarcodeFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared by store, update and "test" (which also takes the barcode to try).
     *
     * @return array<string, mixed>
     */
    public static function formatRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'engine' => ['required', Rule::in(ScaleBarcodeFormat::ENGINES)],
            'symbology' => ['required', Rule::in(ScaleBarcodeFormat::SYMBOLOGIES)],
            'prefixes' => ['nullable', 'array', 'max:20'],
            'prefixes.*' => ['nullable', 'string', 'max:20'],
            'total_length' => ['nullable', 'integer', 'min:4', 'max:80'],
            'plu_start' => ['nullable', 'integer', 'min:1', 'max:80'],
            'plu_length' => ['nullable', 'integer', 'min:1', 'max:20'],
            'value_start' => ['nullable', 'integer', 'min:1', 'max:80'],
            'value_length' => ['nullable', 'integer', 'min:1', 'max:20'],
            'value_type' => ['required', Rule::in(ScaleBarcodeFormat::VALUE_TYPES)],
            'decimals' => ['nullable', 'integer', 'min:0', 'max:6'],
            'multiplier' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'uom_id' => ['nullable', 'integer', 'exists:unit_of_measurements,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'price_includes_tax' => ['nullable', 'boolean'],
            'verify_check_digit' => ['nullable', 'boolean'],
            'price_check_digit_position' => ['nullable', 'integer', 'min:1', 'max:80'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::formatRules();
    }
}
