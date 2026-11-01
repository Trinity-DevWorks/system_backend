<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerGroup;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Sales\PriceList\Models\PriceList;
use App\Modules\Sales\PriceList\Models\PriceListItem;
use App\Support\Decimal;

/**
 * What: Default selling price for document lines.
 * Order: customer's list -> customer group's list -> branch's list -> company default list
 *        -> item unit selling_price (primary currency, converted with the document rate).
 * Inside a list: the validity window must contain the date, and the highest min_quantity
 * that is <= the line quantity wins. A list priced in the document currency is used as-is;
 * a list in the primary currency is converted with the rate (1 primary = rate x document
 * currency); a list in any other currency is skipped.
 */
class PriceResolver
{
    /**
     * @param  list<array{item_id: string, item_uom_id?: int|string|null, quantity?: mixed}>  $lines
     * @return list<array{item_id: string, item_uom_id: ?int, unit_price: ?string, price_source: string, price_list_id: ?int}>
     */
    public function resolve(?string $customerId, int $currencyId, mixed $exchangeRate, string $date, ?int $branchId, array $lines): array
    {
        $primaryId = (int) (Currency::getPrimary()?->id ?? 0);
        $rate = Decimal::isPositive($exchangeRate) ? Decimal::of($exchangeRate) : '1';
        $lists = $this->candidateLists($customerId, $branchId, $date, $currencyId, $primaryId);

        $out = [];
        foreach ($lines as $line) {
            $itemId = (string) $line['item_id'];
            $uomId = $this->resolveUomId($itemId, $line['item_uom_id'] ?? null);
            $qty = Decimal::abs($line['quantity'] ?? '1');

            $price = null;
            $source = 'none';
            $listId = null;

            if ($uomId !== null) {
                foreach ($lists as $list) {
                    $row = PriceListItem::query()
                        ->where('price_list_id', $list->id)
                        ->where('item_id', $itemId)
                        ->where('item_uom_id', $uomId)
                        ->where('min_quantity', '<=', $qty)
                        ->orderByDesc('min_quantity')
                        ->first();
                    if ($row === null) {
                        continue;
                    }
                    $price = (int) $list->currency_id === $currencyId
                        ? Decimal::of($row->price)
                        : Decimal::mul($row->price, $rate);
                    $source = $list->code;
                    $listId = (int) $list->id;

                    break;
                }

                if ($price === null) {
                    $catalog = ItemUom::query()->whereKey($uomId)->value('selling_price');
                    if ($catalog !== null) {
                        $price = $currencyId === $primaryId ? Decimal::of($catalog) : Decimal::mul($catalog, $rate);
                        $source = 'catalog';
                    }
                }
            }

            $out[] = [
                'item_id' => $itemId,
                'item_uom_id' => $uomId,
                'unit_price' => $price !== null ? PriceMath::normalize($price) : null,
                'price_source' => $source,
                'price_list_id' => $listId,
            ];
        }

        return $out;
    }

    /**
     * @return list<PriceList>
     */
    private function candidateLists(?string $customerId, ?int $branchId, string $date, int $currencyId, int $primaryId): array
    {
        $ids = [];
        if ($customerId !== null && $customerId !== '') {
            $customer = Customer::query()->find($customerId, ['id', 'price_list_id', 'customer_group_id']);
            if ($customer !== null) {
                $ids[] = $customer->price_list_id;
                if ($customer->customer_group_id !== null) {
                    $ids[] = CustomerGroup::query()->whereKey($customer->customer_group_id)->value('price_list_id');
                }
            }
        }
        if ($branchId !== null) {
            $ids[] = Branch::query()->whereKey($branchId)->value('price_list_id');
        }
        $ids[] = PriceList::query()->where('is_default', true)->value('id');

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): ?int => $id !== null ? (int) $id : null,
            $ids,
        ))));
        if ($ids === []) {
            return [];
        }

        $usable = PriceList::query()
            ->whereIn('id', $ids)
            ->usableOn($date)
            ->whereIn('currency_id', array_unique([$currencyId, $primaryId]))
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            if ($usable->has($id)) {
                $ordered[] = $usable->get($id);
            }
        }

        return $ordered;
    }

    private function resolveUomId(string $itemId, mixed $uomId): ?int
    {
        if ($uomId !== null && $uomId !== '') {
            return (int) $uomId;
        }

        $row = ItemUom::query()
            ->where('item_id', $itemId)
            ->orderByDesc('is_default_sale')
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->first(['id']);

        return $row !== null ? (int) $row->id : null;
    }
}
