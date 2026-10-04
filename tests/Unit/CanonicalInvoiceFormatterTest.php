<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceFormatter;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Checks that canonical formatting rules stay deterministic (decimals, dates, nulls, JSON).
 */
class CanonicalInvoiceFormatterTest extends TestCase
{
    public function test_money_uses_fixed_four_decimal_places(): void
    {
        $this->assertSame('10.5000', CanonicalInvoiceFormatter::money('10.5'));
        $this->assertSame('0.0000', CanonicalInvoiceFormatter::money(null));
        $this->assertSame('0.0000', CanonicalInvoiceFormatter::money(''));
    }

    public function test_quantity_uses_fixed_six_decimal_places(): void
    {
        $this->assertSame('2.000000', CanonicalInvoiceFormatter::quantity('2'));
        $this->assertSame('1.250000', CanonicalInvoiceFormatter::quantity(1.25));
    }

    public function test_rate_and_percent_use_fixed_scales(): void
    {
        $this->assertSame('1.000000000000', CanonicalInvoiceFormatter::rate('1'));
        $this->assertSame('0.000011123457', CanonicalInvoiceFormatter::rate('0.000011123457'));
        $this->assertSame('10.0000', CanonicalInvoiceFormatter::percent('10'));
    }

    public function test_date_formats_as_iso_date(): void
    {
        $this->assertSame('2026-09-06', CanonicalInvoiceFormatter::date('2026-09-06'));
        $this->assertSame('2026-09-06', CanonicalInvoiceFormatter::date(Carbon::parse('2026-09-06 15:04:05', 'Asia/Beirut')));
        $this->assertNull(CanonicalInvoiceFormatter::date(null));
        $this->assertNull(CanonicalInvoiceFormatter::date(''));
    }

    public function test_date_time_formats_as_utc(): void
    {
        $beirut = Carbon::parse('2026-09-06 15:04:05', 'Asia/Beirut');

        $this->assertSame('2026-09-06T12:04:05Z', CanonicalInvoiceFormatter::dateTimeUtc($beirut));
        $this->assertNull(CanonicalInvoiceFormatter::dateTimeUtc(null));
    }

    public function test_empty_strings_become_null(): void
    {
        $this->assertNull(CanonicalInvoiceFormatter::nullableString(''));
        $this->assertNull(CanonicalInvoiceFormatter::nullableString('   '));
        $this->assertNull(CanonicalInvoiceFormatter::nullableString(null));
        $this->assertSame('Acme', CanonicalInvoiceFormatter::nullableString(' Acme '));
    }

    public function test_encode_keeps_nulls_and_key_order(): void
    {
        $json = CanonicalInvoiceFormatter::encode([
            'schema_version' => CanonicalInvoiceSchema::VERSION,
            'notes' => null,
            'name' => 'Acme',
        ]);

        $this->assertSame('{"schema_version":3,"notes":null,"name":"Acme"}', $json);
    }
}
