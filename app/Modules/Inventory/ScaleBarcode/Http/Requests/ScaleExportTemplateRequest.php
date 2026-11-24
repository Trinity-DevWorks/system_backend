<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Requests;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleExportTemplate;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScaleExportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared by store, update and the unsaved preview.
     *
     * @return array<string, mixed>
     */
    public static function templateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'columns' => ['required', 'array', 'min:1', 'max:40'],
            'columns.*.field' => ['required', Rule::in(ScaleExportService::FIELDS)],
            'columns.*.header' => ['nullable', 'string', 'max:60'],
            'columns.*.width' => ['nullable', 'integer', 'min:1', 'max:200'],
            'columns.*.pad_char' => ['nullable', 'string', 'max:1'],
            'columns.*.pad_side' => ['nullable', Rule::in(['left', 'right'])],
            'columns.*.constant' => ['nullable', 'string', 'max:60'],
            'columns.*.map' => ['nullable', 'array'],
            'columns.*.map.weight' => ['nullable', 'string', 'max:20'],
            'columns.*.map.piece' => ['nullable', 'string', 'max:20'],
            'delimiter' => ['required', Rule::in(ScaleExportTemplate::DELIMITERS)],
            'has_header' => ['nullable', 'boolean'],
            'encoding' => ['required', Rule::in(ScaleExportTemplate::ENCODINGS)],
            'line_ending' => ['required', Rule::in(['crlf', 'lf'])],
            'quote' => ['required', Rule::in(['always', 'needed', 'never'])],
            'file_extension' => ['required', Rule::in(ScaleExportTemplate::EXTENSIONS)],
            'decimal_separator' => ['required', Rule::in(['.', ','])],
            'price_decimals' => ['required', 'integer', 'min:0', 'max:6'],
            'price_multiplier' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'weight_decimals' => ['nullable', 'integer', 'min:0', 'max:6'],
            'price_source' => ['required', Rule::in(ScaleExportTemplate::PRICE_SOURCES)],
            'price_list_id' => ['nullable', 'required_if:price_source,price_list', 'integer', 'exists:price_lists,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
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
