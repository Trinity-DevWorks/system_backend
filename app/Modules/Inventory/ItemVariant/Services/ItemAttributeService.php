<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ItemVariant\Services;

use App\Modules\Inventory\ItemVariant\Models\ItemAttribute;
use App\Modules\Inventory\ItemVariant\Models\ItemAttributeValue;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Attributes and their values are saved together: the request carries the full value list.
 */
class ItemAttributeService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return ItemAttribute::query()->with('values')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ItemAttribute $a): array => $this->toArray($a))
            ->values()
            ->all();
    }

    /**
     * Table page: search matches the attribute or any of its values.
     *
     * @return LengthAwarePaginator<int, ItemAttribute>
     */
    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = ItemAttribute::query()->with('values')->orderBy('sort_order')->orderBy('name');
        ListPagination::applySearch($query, $search, ['code', 'name'], ['values' => ['code', 'name']]);

        return $query->paginate($perPage);
    }

    /**
     * @param  array{code: string, name: string, sort_order?: int|null, values?: list<array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    public function save(?ItemAttribute $attribute, array $data): array
    {
        return DB::transaction(function () use ($attribute, $data): array {
            $attribute ??= new ItemAttribute;
            $attribute->fill([
                'code' => strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ])->save();

            $keep = [];
            foreach (array_values($data['values'] ?? []) as $index => $row) {
                $values = [
                    'code' => strtoupper(trim((string) $row['code'])),
                    'name' => trim((string) $row['name']),
                    'sort_order' => (int) ($row['sort_order'] ?? $index),
                ];
                $existing = isset($row['id'])
                    ? ItemAttributeValue::query()->where('item_attribute_id', $attribute->id)->whereKey((int) $row['id'])->first()
                    : null;
                if ($existing) {
                    $existing->update($values);
                    $keep[] = $existing->id;
                } else {
                    $keep[] = ItemAttributeValue::query()->create($values + ['item_attribute_id' => $attribute->id])->id;
                }
            }

            $removed = ItemAttributeValue::query()->where('item_attribute_id', $attribute->id)->whereNotIn('id', $keep)->pluck('id');
            if ($removed->isNotEmpty()) {
                if (DB::table('item_variant_values')->whereIn('item_attribute_value_id', $removed)->exists()) {
                    abort(409, 'A removed value is used by item variants.', ['X-Error-Code' => 'ITEM_ATTRIBUTE_VALUE_IN_USE']);
                }
                ItemAttributeValue::query()->whereIn('id', $removed)->delete();
            }

            return $this->toArray($attribute->refresh()->load('values'));
        });
    }

    public function delete(ItemAttribute $attribute): void
    {
        if (DB::table('item_variant_attributes')->where('item_attribute_id', $attribute->id)->exists()) {
            abort(409, 'This attribute is used by item variants.', ['X-Error-Code' => 'ITEM_ATTRIBUTE_IN_USE']);
        }
        $attribute->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(ItemAttribute $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'sort_order' => (int) $a->sort_order,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
            'values' => $a->values->map(fn (ItemAttributeValue $v): array => [
                'id' => $v->id,
                'code' => $v->code,
                'name' => $v->name,
                'sort_order' => (int) $v->sort_order,
            ])->values()->all(),
        ];
    }
}
