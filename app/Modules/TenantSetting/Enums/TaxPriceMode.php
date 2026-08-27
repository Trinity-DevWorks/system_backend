<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Enums;

/**
 * How catalog prices relate to VAT when tax is enabled.
 *
 * Exclusive: stored price is net; tax is added on the document.
 * Inclusive: stored price already includes tax; net is backed out on the document.
 *
 * Ignored while tax_enabled is false. Not applied to stock movements.
 */
enum TaxPriceMode: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function pricesIncludeTax(bool $taxEnabled): bool
    {
        return $taxEnabled && $this === self::Inclusive;
    }
}
