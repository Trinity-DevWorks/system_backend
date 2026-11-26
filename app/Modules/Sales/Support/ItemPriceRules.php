<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Sales\PriceList\Services\PriceResolver;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;

/**
 * The item's own price rules, checked when a sales document is posted or confirmed:
 *  - open price: the price is typed at the sale, so it must be above zero;
 *  - price change not allowed: the line keeps the normal price (price list, else the unit's selling price);
 *  - discount not allowed: no line discount (bill discounts already skip these items).
 * Lines carried from an order or a delivery note keep the price agreed there and are not re-checked.
 */
final class ItemPriceRules
{
    /**
     * @param  iterable<int, Model>  $lines  document lines (item_id, item_uom_id, quantity, unit_price, discount_percent)
     */
    public static function assert(Model $document, iterable $lines, ?string $customerId, int $currencyId, mixed $rate, string $date, ?int $branchId, string $errorPrefix): void
    {
        $rows = [];
        foreach ($lines as $line) {
            if ($line->getAttribute('item_id') === null
                || $line->getAttribute('sales_order_line_id') !== null
                || $line->getAttribute('delivery_note_line_id') !== null) {
                continue;
            }
            $rows[] = $line;
        }
        if ($rows === []) {
            return;
        }

        $items = Item::query()
            ->whereIn('id', array_values(array_unique(array_map(fn (Model $l): string => (string) $l->getAttribute('item_id'), $rows))))
            ->get(['id', 'name', 'open_price', 'allow_price_change', 'allow_discount'])
            ->keyBy('id');

        $fixed = [];
        foreach ($rows as $line) {
            $item = $items->get((string) $line->getAttribute('item_id'));
            if (! $item instanceof Item) {
                continue;
            }
            $price = (string) $line->getAttribute('unit_price');
            if ($item->open_price) {
                if (! Decimal::isPositive($price)) {
                    abort(422, sprintf('"%s" has an open price: enter its price.', $item->name), ['X-Error-Code' => $errorPrefix.'_OPEN_PRICE_REQUIRED']);
                }
            } elseif (! $item->allow_price_change) {
                $fixed[] = [$line, $item];
            }
            if (! $item->allow_discount && Decimal::isPositive((string) ($line->getAttribute('discount_percent') ?? '0'))) {
                abort(422, sprintf('"%s" cannot be discounted.', $item->name), ['X-Error-Code' => $errorPrefix.'_DISCOUNT_NOT_ALLOWED']);
            }
        }
        if ($fixed === []) {
            return;
        }

        $resolved = app(PriceResolver::class)->resolve(
            $customerId,
            $currencyId,
            $rate,
            $date,
            $branchId,
            array_map(fn (array $pair): array => [
                'item_id' => (string) $pair[0]->getAttribute('item_id'),
                'item_uom_id' => $pair[0]->getAttribute('item_uom_id'),
                'quantity' => (string) $pair[0]->getAttribute('quantity'),
            ], $fixed),
            DocumentTaxMode::of($document),
        );
        foreach ($fixed as $i => [$line, $item]) {
            $expected = $resolved[$i]['unit_price'] ?? null;
            if ($expected === null) {
                continue;
            }
            if (Decimal::cmp(Decimal::abs(Decimal::sub((string) $line->getAttribute('unit_price'), $expected)), '0.0001') > 0) {
                abort(422, sprintf('The price of "%s" cannot be changed (normal price %s).', $item->name, Decimal::round($expected, 2)), [
                    'X-Error-Code' => $errorPrefix.'_PRICE_CHANGE_NOT_ALLOWED',
                ]);
            }
        }
    }
}
