<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Services;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleLabelTemplate;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;

class ScaleLabelTemplateService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ScaleLabelTemplate>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ScaleLabelTemplate::query()->with('barcodeFormat:id,name,value_type,engine,symbology');
        ListPagination::applySearch($query, $filters['search'] ?? null, ['name']);
        if (($filters['active'] ?? null) !== null && $filters['active'] !== '') {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    public function find(int $id): ScaleLabelTemplate
    {
        return ScaleLabelTemplate::query()->with('barcodeFormat:id,name,value_type,engine,symbology')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ScaleLabelTemplate
    {
        return $this->find((int) ScaleLabelTemplate::query()->create($this->normalize($data))->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ScaleLabelTemplate $template, array $data): ScaleLabelTemplate
    {
        $template->update($this->normalize($data));

        return $this->find((int) $template->id);
    }

    public function delete(ScaleLabelTemplate $template): void
    {
        $template->delete();
    }

    /**
     * An unsaved template built from the form (for the live preview).
     *
     * @param  array<string, mixed>  $data
     */
    public function make(array $data): ScaleLabelTemplate
    {
        $template = new ScaleLabelTemplate($this->normalize($data));
        $template->load('barcodeFormat');

        return $template;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $fields = array_values(array_intersect(ScaleLabelTemplate::FIELDS, (array) ($data['fields'] ?? [])));
        $mode = (string) ($data['mode'] ?? 'single');

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'mode' => $mode,
            'width_mm' => (string) ($data['width_mm'] ?? '60'),
            'height_mm' => (string) ($data['height_mm'] ?? '40'),
            'sheet_columns' => (int) ($data['sheet_columns'] ?? 3),
            'sheet_rows' => (int) ($data['sheet_rows'] ?? 8),
            'margin_mm' => (string) ($data['margin_mm'] ?? '8'),
            'gap_mm' => (string) ($data['gap_mm'] ?? '2'),
            'fields' => $fields,
            'font_size' => (int) ($data['font_size'] ?? 9),
            'scale_barcode_format_id' => $data['scale_barcode_format_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ScaleLabelTemplate $template): array
    {
        $format = $template->barcodeFormat;

        return [
            'id' => (int) $template->id,
            'name' => $template->name,
            'mode' => $template->mode,
            'width_mm' => (string) $template->width_mm,
            'height_mm' => (string) $template->height_mm,
            'sheet_columns' => (int) $template->sheet_columns,
            'sheet_rows' => (int) $template->sheet_rows,
            'margin_mm' => (string) $template->margin_mm,
            'gap_mm' => (string) $template->gap_mm,
            'fields' => array_values((array) $template->fields),
            'font_size' => (int) $template->font_size,
            'scale_barcode_format_id' => $template->scale_barcode_format_id,
            'barcode_format' => $format ? [
                'id' => (int) $format->id,
                'name' => $format->name,
                'value_type' => $format->value_type,
                'engine' => $format->engine,
                'symbology' => $format->symbology,
            ] : null,
            'is_active' => (bool) $template->is_active,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
