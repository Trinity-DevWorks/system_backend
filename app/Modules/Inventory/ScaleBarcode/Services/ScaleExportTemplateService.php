<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Services;

use App\Modules\Inventory\ScaleBarcode\Models\ScaleExportTemplate;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;

class ScaleExportTemplateService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ScaleExportTemplate>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ScaleExportTemplate::query()->with(['priceList:id,name', 'branch:id,name']);
        ListPagination::applySearch($query, $filters['search'] ?? null, ['name']);
        if (($filters['active'] ?? null) !== null && $filters['active'] !== '') {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    public function find(int $id): ScaleExportTemplate
    {
        return ScaleExportTemplate::query()->with(['priceList:id,name', 'branch:id,name'])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ScaleExportTemplate
    {
        return $this->find((int) ScaleExportTemplate::query()->create($this->normalize($data))->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ScaleExportTemplate $template, array $data): ScaleExportTemplate
    {
        $template->update($this->normalize($data));

        return $this->find((int) $template->id);
    }

    public function delete(ScaleExportTemplate $template): void
    {
        $template->delete();
    }

    /**
     * An unsaved template built from the form (for the live preview).
     *
     * @param  array<string, mixed>  $data
     */
    public function make(array $data): ScaleExportTemplate
    {
        return new ScaleExportTemplate($this->normalize($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $columns = [];
        foreach ((array) ($data['columns'] ?? []) as $column) {
            $field = (string) ($column['field'] ?? '');
            $map = $field === 'price_type' && ! empty($column['map'])
                ? array_filter((array) $column['map'], fn ($v): bool => $v !== null && $v !== '')
                : [];
            $columns[] = array_filter([
                'field' => $field,
                'header' => trim((string) ($column['header'] ?? '')) ?: $field,
                'width' => isset($column['width']) && $column['width'] !== '' ? (int) $column['width'] : null,
                'pad_char' => isset($column['pad_char']) && $column['pad_char'] !== '' ? (string) $column['pad_char'] : null,
                'pad_side' => $column['pad_side'] ?? null,
                'constant' => $field === 'constant' ? (string) ($column['constant'] ?? '') : null,
                'map' => $map !== [] ? $map : null,
            ], fn ($v): bool => $v !== null);
        }
        $priceSource = (string) ($data['price_source'] ?? 'item_price');

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'columns' => $columns,
            'delimiter' => (string) ($data['delimiter'] ?? ','),
            'has_header' => (bool) ($data['has_header'] ?? true),
            'encoding' => (string) ($data['encoding'] ?? 'utf8'),
            'line_ending' => (string) ($data['line_ending'] ?? 'crlf'),
            'quote' => (string) ($data['quote'] ?? 'needed'),
            'file_extension' => (string) ($data['file_extension'] ?? 'csv'),
            'decimal_separator' => (string) ($data['decimal_separator'] ?? '.'),
            'price_decimals' => (int) ($data['price_decimals'] ?? 2),
            'price_multiplier' => (string) (($data['price_multiplier'] ?? null) ?: '1'),
            'weight_decimals' => (int) ($data['weight_decimals'] ?? 3),
            'price_source' => $priceSource,
            'price_list_id' => $priceSource === 'price_list' ? ($data['price_list_id'] ?? null) : null,
            'branch_id' => $data['branch_id'] ?? null,
            'category_ids' => array_values(array_map('intval', (array) ($data['category_ids'] ?? []))),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ScaleExportTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'name' => $template->name,
            'columns' => array_values((array) $template->columns),
            'delimiter' => $template->delimiter,
            'has_header' => (bool) $template->has_header,
            'encoding' => $template->encoding,
            'line_ending' => $template->line_ending,
            'quote' => $template->quote,
            'file_extension' => $template->file_extension,
            'decimal_separator' => $template->decimal_separator,
            'price_decimals' => (int) $template->price_decimals,
            'price_multiplier' => (string) $template->price_multiplier,
            'weight_decimals' => (int) $template->weight_decimals,
            'price_source' => $template->price_source,
            'price_list_id' => $template->price_list_id !== null ? (int) $template->price_list_id : null,
            'price_list' => $template->priceList ? ['id' => (int) $template->priceList->id, 'name' => $template->priceList->name] : null,
            'branch_id' => $template->branch_id !== null ? (int) $template->branch_id : null,
            'branch' => $template->branch ? ['id' => (int) $template->branch->id, 'name' => $template->branch->name] : null,
            'category_ids' => array_values(array_map('intval', (array) ($template->category_ids ?? []))),
            'is_active' => (bool) $template->is_active,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
