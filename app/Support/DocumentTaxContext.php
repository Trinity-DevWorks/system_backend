<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Support\Carbon;

final class DocumentTaxContext
{
    public function __construct(
        private readonly CompanySetting $settings,
    ) {}

    public static function current(): self
    {
        return new self(CompanySetting::current());
    }

    public function pricesIncludeTax(): bool
    {
        return $this->settings->pricesIncludeTax();
    }

    public function taxEnabled(): bool
    {
        return (bool) $this->settings->tax_enabled;
    }

    /**
     * Effective VAT rate percent for a purchasable line on the given date.
     */
    public function purchaseLineTaxRatePercent(Supplier $supplier, Item $item, string $transactionDate): string
    {
        if (! $this->taxEnabled()) {
            return DocumentTaxMath::money(0);
        }

        if ($this->isSupplierExemptOnDate($supplier, $transactionDate)) {
            return DocumentTaxMath::money(0);
        }

        if ($item->vat_group_id === null) {
            return DocumentTaxMath::money(0);
        }

        $vatGroup = $item->relationLoaded('vatGroup')
            ? $item->vatGroup
            : VatGroup::query()->find($item->vat_group_id);

        if ($vatGroup === null || ! $vatGroup->is_active) {
            return DocumentTaxMath::money(0);
        }

        return DocumentTaxMath::money($vatGroup->percentage);
    }

    public function isSupplierExemptOnDate(Supplier $supplier, string $transactionDate): bool
    {
        if (! $supplier->is_exempted) {
            return false;
        }

        $date = Carbon::parse($transactionDate)->startOfDay();

        if ($supplier->exempted_from !== null && $date->lt(Carbon::parse($supplier->exempted_from)->startOfDay())) {
            return false;
        }

        if ($supplier->exempted_to !== null && $date->gt(Carbon::parse($supplier->exempted_to)->startOfDay())) {
            return false;
        }

        return true;
    }
}
