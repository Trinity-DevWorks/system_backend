<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Sales\PriceList\Services\PriceResolver;
use App\Support\Decimal;
use App\Support\DocumentTaxContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Each sales document has one tax mode (do its prices include VAT?), fixed when it is created: from its main
 * price list (customer → customer group → branch → company default), else the company setting. A document
 * made from another one (order from quotation, invoice from order / delivery note, credit note from invoice)
 * copies its mode. Later price-list edits do not change existing documents.
 */
final class DocumentTaxMode
{
    public static function forNewDocument(?string $customerId, ?int $branchId, string $date, int $currencyId): bool
    {
        $list = app(PriceResolver::class)->mainList($customerId, $branchId, $date, $currencyId);

        return $list !== null ? (bool) $list->prices_include_tax : DocumentTaxContext::pricesIncludeTax();
    }

    /**
     * Sets the new document's mode: `$fromSource` when it is made from another document, else from its main price list.
     */
    public static function stamp(Model $document, ?bool $fromSource, ?string $customerId, ?int $branchId, string $date, int $currencyId): void
    {
        $mode = $fromSource ?? self::forNewDocument($customerId, $branchId, $date, $currencyId);
        $document->forceFill(['prices_include_tax' => $mode])->save();
    }

    /** The document's mode (documents made before it was stored use the company setting). */
    public static function of(Model $document): bool
    {
        $mode = $document->getAttribute('prices_include_tax');

        return $mode === null ? DocumentTaxContext::pricesIncludeTax() : (bool) $mode;
    }

    /** `convert` with the item's VAT rate (none when tax is off). */
    public static function convertForItem(string $price, string $itemId, bool $fromIncludesTax, bool $toIncludesTax): string
    {
        if ($fromIncludesTax === $toIncludesTax || ! CompanySetting::current()->tax_enabled) {
            return $price;
        }
        $vat = (string) (Item::query()->whereKey($itemId)->with('vatGroup:id,percentage')->first()?->vatGroup?->percentage ?? '0');

        return self::convert($price, $vat, $fromIncludesTax, $toIncludesTax);
    }

    /**
     * A price written in one mode, in the other: incl → excl = price ÷ (1 + VAT), excl → incl = price × (1 + VAT).
     */
    public static function convert(string $price, string $vatPercent, bool $fromIncludesTax, bool $toIncludesTax): string
    {
        if ($fromIncludesTax === $toIncludesTax || ! Decimal::isPositive($vatPercent)) {
            return $price;
        }
        $factor = Decimal::add('1', Decimal::div($vatPercent, '100', 12));

        return $fromIncludesTax ? Decimal::div($price, $factor, 12) : Decimal::mul($price, $factor);
    }
}
