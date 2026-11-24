<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Print: a saved template + lines. Preview: a saved template, or an unsaved one ("template"),
 * and optionally lines (else a sample label from the first scale item).
 */
class PrintScaleLabelsRequest extends FormRequest
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
        $preview = $this->routeIs('*preview*') || str_ends_with($this->path(), 'preview');

        return [
            'scale_label_template_id' => [$preview ? 'nullable' : 'required', 'integer', 'exists:scale_label_templates,id'],
            'template' => ['nullable', 'array'],
            ...($preview && $this->has('template') ? ScaleLabelTemplateRequest::templateRules('template.') : []),
            'lines' => [$preview ? 'nullable' : 'required', 'array', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'lines.*.total' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.copies' => ['nullable', 'integer', 'min:1', 'max:500'],
            'lines.*.packed_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
