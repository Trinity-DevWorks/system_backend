<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ItemVariant\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemVariant\Models\ItemAttribute;
use App\Modules\Inventory\ItemVariant\Models\ItemAttributeValue;
use App\Modules\Inventory\PriceChange\Enums\PriceChangeSource;
use App\Modules\Inventory\PriceChange\Services\PriceHistoryRecorder;
use App\Modules\Inventory\Stock\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Variants: a parent item is a template; each attribute-value combination is a real child item
 * with its own code, units, prices and stock.
 */
class ItemVariantService
{
    public const MAX_COMBINATIONS = 200;

    public function __construct(private readonly PriceHistoryRecorder $history) {}

    /**
     * @return array{attributes: list<array<string, mixed>>, variants: list<array<string, mixed>>}
     */
    public function show(Item $parent): array
    {
        $attributeIds = DB::table('item_variant_attributes')->where('item_id', $parent->id)->orderBy('sort_order')->pluck('item_attribute_id');
        $attributes = ItemAttribute::query()->whereIn('id', $attributeIds)->with('values')->get()->sortBy(fn ($a) => $attributeIds->search($a->id))->values();

        $children = Item::query()->where('parent_item_id', $parent->id)->orderBy('item_code')->get();
        $valueRows = DB::table('item_variant_values as vv')
            ->join('item_attribute_values as v', 'v.id', '=', 'vv.item_attribute_value_id')
            ->whereIn('vv.item_id', $children->pluck('id'))
            ->get(['vv.item_id', 'v.id', 'v.item_attribute_id', 'v.code', 'v.name'])
            ->groupBy('item_id');
        $onHand = DB::table('stock_balances')->whereIn('item_id', $children->pluck('id'))
            ->groupBy('item_id')->selectRaw('item_id, SUM(quantity) as qty')->pluck('qty', 'item_id');
        $prices = ItemUom::query()->whereIn('item_id', $children->pluck('id'))->where('is_base', true)->pluck('selling_price', 'item_id');

        $used = $valueRows->flatten(1)->pluck('id')->unique();

        return [
            'attributes' => $attributes->map(fn (ItemAttribute $a): array => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'value_ids' => $a->values->pluck('id')->filter(fn ($id) => $used->contains($id))->values()->all(),
            ])->all(),
            'variants' => $children->map(fn (Item $child): array => [
                'id' => $child->id,
                'item_code' => $child->item_code,
                'name' => $child->name,
                'is_active' => (bool) $child->is_active,
                'selling_price' => $prices[$child->id] ?? null,
                'on_hand' => (string) ($onHand[$child->id] ?? '0'),
                'values' => ($valueRows[$child->id] ?? collect())->map(fn ($v): array => [
                    'attribute_id' => (int) $v->item_attribute_id, 'value_id' => (int) $v->id, 'code' => $v->code, 'name' => $v->name,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Creates the missing combinations; existing children are kept untouched.
     *
     * @param  list<array{attribute_id: int, value_ids: list<int>}>  $selection
     * @return array{created: int, attributes: list<array<string, mixed>>, variants: list<array<string, mixed>>}
     */
    public function generate(Item $parent, array $selection): array
    {
        if ($parent->parent_item_id !== null) {
            abort(422, 'A variant cannot have its own variants.', ['X-Error-Code' => 'ITEM_VARIANT_NESTED']);
        }

        $created = DB::transaction(function () use ($parent, $selection): int {
            $parent = Item::query()->whereKey($parent->id)->lockForUpdate()->firstOrFail();
            $attributeIds = array_map(fn (array $s): int => (int) $s['attribute_id'], $selection);
            if (count($attributeIds) !== count(array_unique($attributeIds))) {
                abort(422, 'Each attribute can be chosen once.', ['X-Error-Code' => 'ITEM_VARIANT_DUPLICATE_ATTRIBUTE']);
            }

            $hasChildren = Item::withTrashed()->where('parent_item_id', $parent->id)->exists();
            if (! $parent->is_variant_parent) {
                if (StockMovement::query()->where('item_id', $parent->id)->exists()) {
                    abort(422, 'An item with stock history cannot become a variant template.', ['X-Error-Code' => 'ITEM_VARIANT_PARENT_HAS_STOCK']);
                }
            }

            $current = DB::table('item_variant_attributes')->where('item_id', $parent->id)->orderBy('sort_order')->pluck('item_attribute_id')->map(fn ($id) => (int) $id)->all();
            $sameAttributes = array_diff($current, $attributeIds) === [] && array_diff($attributeIds, $current) === [];
            if ($hasChildren && $current !== [] && ! $sameAttributes) {
                abort(422, 'The attributes are fixed once variants exist; you can only add values.', ['X-Error-Code' => 'ITEM_VARIANT_ATTRIBUTES_LOCKED']);
            }

            // Values per attribute, in attribute order.
            $axes = [];
            foreach ($selection as $index => $row) {
                $values = ItemAttributeValue::query()
                    ->where('item_attribute_id', (int) $row['attribute_id'])
                    ->whereIn('id', array_map('intval', $row['value_ids']))
                    ->orderBy('sort_order')->orderBy('id')
                    ->get();
                if ($values->count() !== count(array_unique($row['value_ids']))) {
                    abort(422, 'A chosen value does not belong to its attribute.', ['X-Error-Code' => 'ITEM_VARIANT_VALUE_MISMATCH']);
                }
                $axes[] = $values->all();
                DB::table('item_variant_attributes')->updateOrInsert(
                    ['item_id' => $parent->id, 'item_attribute_id' => (int) $row['attribute_id']],
                    ['sort_order' => $index, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            $combos = [[]];
            foreach ($axes as $values) {
                $next = [];
                foreach ($combos as $combo) {
                    foreach ($values as $value) {
                        $next[] = [...$combo, $value];
                    }
                }
                $combos = $next;
            }
            if (count($combos) > self::MAX_COMBINATIONS) {
                abort(422, 'Too many combinations (max '.self::MAX_COMBINATIONS.').', ['X-Error-Code' => 'ITEM_VARIANT_TOO_MANY']);
            }

            $existing = DB::table('item_variant_values as vv')
                ->join('items as i', 'i.id', '=', 'vv.item_id')
                ->where('i.parent_item_id', $parent->id)
                ->whereNull('i.deleted_at')
                ->get(['vv.item_id', 'vv.item_attribute_value_id'])
                ->groupBy('item_id')
                ->map(fn ($rows) => self::comboKey($rows->pluck('item_attribute_value_id')->all()))
                ->flip();

            $parent->forceFill(['is_variant_parent' => true])->save();
            $units = ItemUom::query()->where('item_id', $parent->id)->get();

            $count = 0;
            foreach ($combos as $combo) {
                $ids = array_map(fn (ItemAttributeValue $v): int => $v->id, $combo);
                if ($existing->has(self::comboKey($ids))) {
                    continue;
                }
                $this->createChild($parent, $combo, $units);
                $count++;
            }

            return $count;
        });

        return ['created' => $created] + $this->show($parent->refresh());
    }

    /**
     * @param  list<ItemAttributeValue>  $combo
     * @param  Collection<int, ItemUom>  $units
     */
    private function createChild(Item $parent, array $combo, $units): Item
    {
        $suffixName = implode(' / ', array_map(fn (ItemAttributeValue $v): string => $v->name, $combo));
        $suffixCode = implode('-', array_map(fn (ItemAttributeValue $v): string => $v->code, $combo));
        $base = strtoupper(($parent->item_code ?: Str::upper(Str::substr($parent->id, 0, 8))).'-'.$suffixCode);
        $base = Str::limit($base, 96, '');
        $code = $base;
        for ($n = 2; Item::withTrashed()->where('item_code', $code)->exists(); $n++) {
            $code = $base.'-'.$n;
        }

        $child = new Item;
        $child->fill([
            'name' => Str::limit($parent->name.' — '.$suffixName, 255, ''),
            'item_code' => $code,
            'item_type_id' => $parent->item_type_id,
            'category_id' => $parent->category_id,
            'brand_id' => $parent->brand_id,
            'unit_group_id' => $parent->unit_group_id,
            'base_uom_id' => $parent->base_uom_id,
            'vat_group_id' => $parent->vat_group_id,
            'description' => $parent->description,
            'ticket_name' => $parent->ticket_name,
            'kitchen_name' => $parent->kitchen_name,
            'send_to_kitchen' => $parent->send_to_kitchen,
            'pos_name' => $parent->pos_name !== null ? Str::limit($parent->pos_name.' '.$suffixName, 255, '') : null,
            'color' => $parent->color,
            'track_inventory' => $parent->track_inventory,
            'track_lots' => $parent->track_lots,
            'costing_method' => $parent->costing_method,
            'allow_sale' => $parent->allow_sale,
            'allow_purchase' => $parent->allow_purchase,
            'allow_return' => $parent->allow_return,
            'is_active' => $parent->is_active,
        ]);
        $child->forceFill(['parent_item_id' => $parent->id])->save();

        foreach ($units as $unit) {
            $copied = ItemUom::query()->create([
                'item_id' => $child->id,
                'uom_id' => $unit->uom_id,
                'conversion_factor' => $unit->conversion_factor,
                'selling_price' => $unit->selling_price,
                'cost_price' => $unit->cost_price,
                'takeaway_price' => $unit->takeaway_price,
                'dine_in_price' => $unit->dine_in_price,
                'delivery_price' => $unit->delivery_price,
                'is_base' => $unit->is_base,
                'is_default_sale' => $unit->is_default_sale,
                'is_default_purchase' => $unit->is_default_purchase,
            ]);
            $this->history->recordItemUom($copied, null, [
                'source' => PriceChangeSource::VariantGenerate->value,
                'source_id' => (string) $parent->id,
                'reason' => null,
                'effective_at' => null,
                'changed_by' => null,
            ]);
        }

        $now = now();
        DB::table('item_variant_values')->insert(array_map(fn (ItemAttributeValue $v): array => [
            'item_id' => $child->id, 'item_attribute_value_id' => $v->id, 'created_at' => $now, 'updated_at' => $now,
        ], $combo));

        return $child;
    }

    /**
     * @param  array<int, int|string>  $valueIds
     */
    private static function comboKey(array $valueIds): string
    {
        $ids = array_map('intval', $valueIds);
        sort($ids);

        return implode(',', $ids);
    }
}
