<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;

final class CommercialLineBuilder
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function rows(array $lines, Customer $customer, string $date, string $codePrefix): array
    {
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $out = [];

        foreach (array_values($lines) as $index => $row) {
            $item = Item::query()->findOrFail((string) $row['item_id']);
            if (! $item->is_active) {
                abort(422, 'Item must be active.', ['X-Error-Code' => $codePrefix.'_ITEM_INACTIVE']);
            }
            if (! $item->allow_sale) {
                abort(422, 'Item is not sellable.', ['X-Error-Code' => $codePrefix.'_ITEM_NOT_SELLABLE']);
            }

            $qty = CommercialLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                isset($row['item_uom_id']) && $row['item_uom_id'] !== null && $row['item_uom_id'] !== ''
                    ? (int) $row['item_uom_id']
                    : null,
                $codePrefix,
            );

            $unitPrice = PriceMath::normalize($row['unit_price'] ?? 0);
            $discountPercent = (string) ($row['discount_percent'] ?? 0);
            $taxRate = DocumentTaxContext::ratePercentForItem($item, $customer, $date);
            $math = DocumentTaxMath::line($qty['quantity'], $unitPrice, $discountPercent, $taxRate, $includeTax);

            $out[] = [
                'item_id' => $item->id,
                'item_uom_id' => $qty['item_uom_id'],
                'sort_order' => $index + 1,
                'quantity' => $qty['quantity'],
                'base_quantity' => $qty['base_quantity'],
                'conversion_factor' => $qty['conversion_factor'],
                'unit_price' => $unitPrice,
                'discount_percent' => PriceMath::normalize($discountPercent),
                'discount_amount' => $math['discount_amount'],
                'tax_rate' => $taxRate,
                'line_subtotal' => $math['line_subtotal'],
                'tax_amount' => $math['tax_amount'],
                'line_total' => $math['line_total'],
                'description' => self::nullableString($row['description'] ?? $item->description ?? $item->name),
                'notes' => self::nullableString($row['notes'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $rows
     * @return array{subtotal:string,discount_total:string,tax_total:string,grand_total:string}
     */
    public static function totals(iterable $rows): array
    {
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $linePayload = [];
        foreach ($rows as $line) {
            $quantity = is_array($line) ? (string) $line['quantity'] : (string) $line->quantity;
            $unitPrice = is_array($line) ? (string) $line['unit_price'] : (string) $line->unit_price;
            $discount = is_array($line) ? (string) $line['discount_amount'] : (string) $line->discount_amount;
            $tax = is_array($line) ? (string) $line['tax_amount'] : (string) $line->tax_amount;
            $linePayload[] = [
                'merchandise' => bcmul($quantity, $unitPrice, 8),
                'discount_amount' => $discount,
                'tax_amount' => $tax,
            ];
        }

        $totals = DocumentTaxMath::sumTotals($linePayload, 0, $includeTax);

        return [
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ];
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
