<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\Services;

use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Sales\PriceList\Models\PriceList;
use App\Modules\Sales\PriceList\Models\PriceListItem;
use App\Support\Decimal;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PriceListService
{
    /** Stored precision for list prices and quantity breaks (matches item unit prices). */
    public const SCALE = 6;

    /**
     * @return Collection<int, PriceList>
     */
    public function list(): Collection
    {
        return PriceList::query()->with('currency:id,code')->orderBy('name')->get();
    }

    /**
     * @return LengthAwarePaginator<int, PriceList>
     */
    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = PriceList::query()
            ->with('currency:id,code')
            ->withCount('items')
            ->orderByDesc('is_default')
            ->orderBy('name');
        ListPagination::applySearch($query, $search, ['code', 'name', 'name_ar']);

        return $query->paginate($perPage);
    }

    public function find(int $id): PriceList
    {
        return PriceList::query()
            ->with([
                'currency:id,code,name',
                'items' => fn ($q) => $q->orderBy('item_id')->orderBy('item_uom_id')->orderBy('min_quantity'),
                'items.item:id,item_code,name',
                'items.itemUom:id,item_id,uom_id,conversion_factor',
                'items.itemUom.uom:id,code,name',
            ])
            ->withCount('items')
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PriceList
    {
        return DB::transaction(function () use ($data): PriceList {
            $list = PriceList::query()->create($this->headerAttributes($data));
            $this->applyDefault($list);
            if (array_key_exists('items', $data)) {
                $this->syncItems($list, (array) $data['items']);
            }

            return $this->find((int) $list->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PriceList $list, array $data): PriceList
    {
        return DB::transaction(function () use ($list, $data): PriceList {
            $list->update($this->headerAttributes($data, $list));
            $this->applyDefault($list);
            if (array_key_exists('items', $data)) {
                $this->syncItems($list, (array) $data['items']);
            }

            return $this->find((int) $list->id);
        });
    }

    public function delete(PriceList $list): void
    {
        // Customers, groups and branches using it fall back to the next list (FK nullOnDelete).
        $list->delete();
    }

    /**
     * Replace all prices of a list.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncItems(PriceList $list, array $rows): void
    {
        $seen = [];
        $insert = [];
        $now = now();

        $uomIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['item_uom_id'], $rows)));
        $uoms = ItemUom::query()->whereIn('id', $uomIds)->get(['id', 'item_id'])->keyBy('id');

        foreach (array_values($rows) as $index => $row) {
            $itemId = (string) $row['item_id'];
            $uomId = (int) $row['item_uom_id'];
            $uom = $uoms->get($uomId);
            if ($uom === null || (string) $uom->item_id !== $itemId) {
                abort(422, 'Line '.($index + 1).': the unit does not belong to this item.', [
                    'X-Error-Code' => 'PRICE_LIST_ITEM_UOM_MISMATCH',
                ]);
            }

            $minQty = Decimal::round($row['min_quantity'] ?? 0, self::SCALE);
            $price = Decimal::round($row['price'], self::SCALE);
            if (Decimal::isNegative($minQty) || Decimal::isNegative($price)) {
                abort(422, 'Line '.($index + 1).': price and minimum quantity cannot be negative.', [
                    'X-Error-Code' => 'PRICE_LIST_ITEM_INVALID',
                ]);
            }

            $key = $itemId.'|'.$uomId.'|'.$minQty;
            if (isset($seen[$key])) {
                abort(422, 'Line '.($index + 1).': the same item, unit and minimum quantity appear twice.', [
                    'X-Error-Code' => 'PRICE_LIST_DUPLICATE_ITEM',
                ]);
            }
            $seen[$key] = true;

            $insert[] = [
                'price_list_id' => $list->id,
                'item_id' => $itemId,
                'item_uom_id' => $uomId,
                'min_quantity' => $minQty,
                'price' => $price,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        PriceListItem::query()->where('price_list_id', $list->id)->delete();
        foreach (array_chunk($insert, 500) as $chunk) {
            PriceListItem::query()->insert($chunk);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data, ?PriceList $existing = null): array
    {
        $out = [];
        foreach (['code', 'name', 'name_ar', 'currency_id', 'valid_from', 'valid_to', 'is_default', 'is_active'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        if (isset($out['name_ar']) && trim((string) $out['name_ar']) === '') {
            $out['name_ar'] = null;
        }

        $from = $out['valid_from'] ?? $existing?->valid_from?->toDateString();
        $to = $out['valid_to'] ?? $existing?->valid_to?->toDateString();
        if ($from !== null && $to !== null && $to < $from) {
            abort(422, 'The end date must be on or after the start date.', ['X-Error-Code' => 'PRICE_LIST_DATES_INVALID']);
        }

        return $out;
    }

    /** Only one list can be the company default. */
    private function applyDefault(PriceList $list): void
    {
        if ($list->is_default) {
            PriceList::query()->whereKeyNot($list->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
