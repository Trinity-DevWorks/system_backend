<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Support\Carbon;

final class DocumentTaxContext
{
    /**
     * Tax rate percent for a document line (0 when tax is off or the customer is exempt).
     */
    public static function ratePercentForItem(
        Item $item,
        ?Customer $customer,
        string $documentDate,
        ?CompanySetting $settings = null,
    ): string {
        $settings ??= CompanySetting::current();
        if (! $settings->tax_enabled) {
            return '0';
        }

        if (self::customerIsExemptOnDate($customer, $documentDate)) {
            return '0';
        }

        $item->loadMissing('vatGroup:id,percentage');
        $group = $item->vatGroup;
        if (! $group instanceof VatGroup) {
            return '0';
        }

        return number_format((float) $group->percentage, 4, '.', '');
    }

    public static function pricesIncludeTax(?CompanySetting $settings = null): bool
    {
        $settings ??= CompanySetting::current();

        return $settings->pricesIncludeTax();
    }

    public static function customerIsExemptOnDate(?Customer $customer, string $documentDate): bool
    {
        if ($customer === null || ! $customer->is_exempted) {
            return false;
        }

        $date = Carbon::parse($documentDate)->startOfDay();
        $from = $customer->exempted_from;
        $to = $customer->exempted_to;

        if ($from !== null && $date->lt(Carbon::parse($from)->startOfDay())) {
            return false;
        }
        if ($to !== null && $date->gt(Carbon::parse($to)->startOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Same rule as customers: a supplier flagged exempt within its date window is charged no input VAT.
     */
    public static function supplierIsExemptOnDate(?Supplier $supplier, string $documentDate): bool
    {
        if ($supplier === null || ! $supplier->is_exempted) {
            return false;
        }

        $date = Carbon::parse($documentDate)->startOfDay();
        if ($supplier->exempted_from !== null && $date->lt(Carbon::parse($supplier->exempted_from)->startOfDay())) {
            return false;
        }
        if ($supplier->exempted_to !== null && $date->gt(Carbon::parse($supplier->exempted_to)->startOfDay())) {
            return false;
        }

        return true;
    }
}
