<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Requests;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleLabelTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScaleLabelTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared by store, update and the label preview.
     *
     * @return array<string, mixed>
     */
    public static function templateRules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:120'],
            $prefix.'mode' => ['required', Rule::in(ScaleLabelTemplate::MODES)],
            $prefix.'width_mm' => ['required', 'numeric', 'min:15', 'max:200'],
            $prefix.'height_mm' => ['required', 'numeric', 'min:10', 'max:300'],
            $prefix.'sheet_columns' => ['nullable', 'integer', 'min:1', 'max:10'],
            $prefix.'sheet_rows' => ['nullable', 'integer', 'min:1', 'max:30'],
            $prefix.'margin_mm' => ['nullable', 'numeric', 'min:0', 'max:50'],
            $prefix.'gap_mm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            $prefix.'fields' => ['required', 'array', 'min:1'],
            $prefix.'fields.*' => [Rule::in(ScaleLabelTemplate::FIELDS)],
            $prefix.'font_size' => ['nullable', 'integer', 'min:5', 'max:24'],
            $prefix.'scale_barcode_format_id' => ['nullable', 'integer', 'exists:scale_barcode_formats,id'],
            $prefix.'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::templateRules();
    }
}
