<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanySetting\Enums\DateFormat;
use App\Modules\CompanySetting\Enums\NumberFormat;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use Tests\TestCase;

class TenantDisplayFormatTest extends TestCase
{
    public function test_date_only_uses_company_pattern(): void
    {
        $settings = $this->settings(dateFormat: DateFormat::DmYSlash);

        $this->assertSame('31/08/2026', TenantDisplayFormat::date('2026-08-31', $settings));
    }

    public function test_number_uses_european_separators(): void
    {
        $settings = $this->settings(numberFormat: NumberFormat::DotComma, decimals: 2);

        $this->assertSame('1.234,50', TenantDisplayFormat::number(1234.5, 2, false, $settings));
    }

    public function test_quantity_trims_trailing_zeros(): void
    {
        $settings = $this->settings();

        $this->assertSame('10', TenantDisplayFormat::quantity('10.000000', $settings));
        $this->assertSame('10.5', TenantDisplayFormat::quantity('10.500000', $settings));
    }

    public function test_money_uses_price_decimal_places(): void
    {
        $settings = $this->settings(decimals: 3);

        $this->assertSame('10.500', TenantDisplayFormat::money(10.5, $settings));
    }

    public function test_notification_params_format_dates_and_qty(): void
    {
        $settings = $this->settings(dateFormat: DateFormat::DmYDash);

        $out = TenantDisplayFormat::notificationParams([
            'item_code' => 'SKU-1',
            'expiry_date' => '2026-08-31',
            'on_hand_qty' => '10.000000',
        ], $settings);

        $this->assertSame('SKU-1', $out['item_code']);
        $this->assertSame('31-08-2026', $out['expiry_date']);
        $this->assertSame('10', $out['on_hand_qty']);
    }

    private function settings(
        DateFormat $dateFormat = DateFormat::YmdDash,
        NumberFormat $numberFormat = NumberFormat::CommaDot,
        int $decimals = 2,
    ): CompanySetting {
        $settings = new CompanySetting;
        $settings->date_format = $dateFormat;
        $settings->number_format = $numberFormat;
        $settings->price_decimal_places = $decimals;
        $settings->timezone = 'UTC';

        return $settings;
    }
}
