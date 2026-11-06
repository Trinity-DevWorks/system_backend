<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Support\Decimal;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use Illuminate\Database\Eloquent\Model;

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
                $row['quantity'],
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

    /**
     * Whole-bill discount from a request (or the stored one when the request does not touch it).
     *
     * @param  array<string, mixed>  $data
     * @return array{header_discount_type: ?string, header_discount_value: string}
     */
    public static function headerDiscount(array $data, ?Model $existing): array
    {
        $type = array_key_exists('header_discount_type', $data)
            ? $data['header_discount_type']
            : $existing?->getAttribute('header_discount_type');
        $value = array_key_exists('header_discount_value', $data)
            ? $data['header_discount_value']
            : $existing?->getAttribute('header_discount_value');

        $type = in_array($type, ['percent', 'amount'], true) ? $type : null;
        $value = $type === null ? '0' : Decimal::max('0', $value ?? '0');
        if ($type === 'percent' && Decimal::cmp($value, '100') > 0) {
            abort(422, 'A bill discount cannot be more than 100%.', ['X-Error-Code' => 'SALES_HEADER_DISCOUNT_TOO_LARGE']);
        }

        return [
            'header_discount_type' => $type,
            'header_discount_value' => Decimal::round($value, 4),
        ];
    }

    /**
     * Spread the document's whole-bill discount over its stored lines and save each line's new money.
     *
     * @param  iterable<int, Model>  $lines  line models with quantity, unit_price, discount_percent, tax_rate
     */
    public static function applyHeaderDiscount(iterable $lines, ?string $type, mixed $value): void
    {
        $models = [];
        $inputs = [];
        foreach ($lines as $line) {
            $models[] = $line;
            $inputs[] = [
                'quantity' => (string) $line->getAttribute('quantity'),
                'unit_price' => (string) $line->getAttribute('unit_price'),
                'discount_percent' => (string) $line->getAttribute('discount_percent'),
                'tax_rate' => (string) $line->getAttribute('tax_rate'),
            ];
        }
        if ($models === []) {
            return;
        }

        $maths = DocumentTaxMath::lines($inputs, $type, $value ?? '0', DocumentTaxContext::pricesIncludeTax());
        foreach ($models as $i => $line) {
            $line->forceFill([
                'discount_amount' => $maths[$i]['discount_amount'],
                'header_discount_amount' => $maths[$i]['header_discount_amount'],
                'line_subtotal' => $maths[$i]['line_subtotal'],
                'tax_amount' => $maths[$i]['tax_amount'],
                'line_total' => $maths[$i]['line_total'],
            ]);
            if ($line->isDirty()) {
                $line->save();
            }
        }
    }

    /**
     * The bill discount to carry onto a document made from part of another (order → invoice).
     * A percent carries as is; an amount becomes the same percent of the source's value, so a partial
     * invoice gets its fair share.
     *
     * @return array{header_discount_type: ?string, header_discount_value: string}
     */
    public static function carriedHeaderDiscount(?Model $source, string $lineRelation = 'lines'): array
    {
        $type = $source?->getAttribute('header_discount_type');
        if ($source === null || ! in_array($type, ['percent', 'amount'], true)) {
            return ['header_discount_type' => null, 'header_discount_value' => '0'];
        }
        if ($type === 'percent') {
            return ['header_discount_type' => 'percent', 'header_discount_value' => Decimal::round($source->getAttribute('header_discount_value'), 4)];
        }

        $base = '0';
        $header = '0';
        foreach ($source->getRelationValue($lineRelation) ?? [] as $line) {
            $lineDiscount = Decimal::sub((string) $line->discount_amount, (string) $line->header_discount_amount);
            $base = Decimal::add($base, Decimal::sub(Decimal::mul((string) $line->quantity, (string) $line->unit_price), $lineDiscount));
            $header = Decimal::add($header, (string) $line->header_discount_amount);
        }
        if (! Decimal::isPositive($base) || ! Decimal::isPositive($header)) {
            return ['header_discount_type' => null, 'header_discount_value' => '0'];
        }

        return ['header_discount_type' => 'percent', 'header_discount_value' => Decimal::round(Decimal::mul(Decimal::div($header, $base, 12), '100'), 4)];
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
