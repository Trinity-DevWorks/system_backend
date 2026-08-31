<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanySetting\Support\CountryCatalog;
use Tests\TestCase;

class CountryCatalogTest extends TestCase
{
    public function test_catalog_contains_iso_alpha2_codes(): void
    {
        $codes = CountryCatalog::codes();

        $this->assertContains('LB', $codes);
        $this->assertContains('SA', $codes);
        $this->assertContains('US', $codes);
        $this->assertNotContains('XX', $codes);
        $this->assertCount(248, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
    }

    public function test_normalize_uppercases_and_trims(): void
    {
        $this->assertSame('LB', CountryCatalog::normalize(' lb '));
        $this->assertNull(CountryCatalog::normalize(''));
        $this->assertNull(CountryCatalog::normalize(null));
    }

    public function test_is_valid_accepts_catalog_codes_only(): void
    {
        $this->assertTrue(CountryCatalog::isValid('lb'));
        $this->assertTrue(CountryCatalog::isValid('LB'));
        $this->assertFalse(CountryCatalog::isValid('XX'));
        $this->assertFalse(CountryCatalog::isValid(''));
        $this->assertFalse(CountryCatalog::isValid(null));
    }

    public function test_localized_names_are_returned(): void
    {
        $this->assertSame('Lebanon', CountryCatalog::name('LB', 'en'));
        $this->assertSame('لبنان', CountryCatalog::name('LB', 'ar'));
        $this->assertNull(CountryCatalog::name('XX', 'en'));
    }

    public function test_list_for_locale_returns_sorted_code_and_name_pairs(): void
    {
        $english = CountryCatalog::listForLocale('en');
        $arabic = CountryCatalog::listForLocale('ar');

        $this->assertCount(248, $english);
        $this->assertCount(248, $arabic);
        $this->assertSame(['code', 'name'], array_keys($english[0]));

        $lebanon = collect($english)->firstWhere('code', 'LB');
        $this->assertSame(['code' => 'LB', 'name' => 'Lebanon'], $lebanon);

        $this->assertNotSame('AD', $english[0]['code']);
    }
}
