<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Item\Support\InStoreBarcode;
use PHPUnit\Framework\TestCase;

class InStoreBarcodeTest extends TestCase
{
    public function test_check_digit_matches_known_ean13(): void
    {
        // 4006381333931 is a standard EAN-13 example.
        $this->assertSame('1', InStoreBarcode::checkDigit('400638133393'));
        $this->assertTrue(InStoreBarcode::isValid('4006381333931'));
        $this->assertFalse(InStoreBarcode::isValid('4006381333932'));
    }

    public function test_builds_in_store_barcodes_from_a_sequence(): void
    {
        $first = InStoreBarcode::forSequence(1);
        $this->assertSame(13, strlen($first));
        $this->assertStringStartsWith('200000000001', $first);
        $this->assertTrue(InStoreBarcode::isValid($first));
        $this->assertSame(1, InStoreBarcode::sequenceOf($first));
        $this->assertSame(123456, InStoreBarcode::sequenceOf(InStoreBarcode::forSequence(123456)));
    }

    public function test_ignores_barcodes_that_are_not_ours(): void
    {
        $this->assertNull(InStoreBarcode::sequenceOf('4006381333931'));
        $this->assertNull(InStoreBarcode::sequenceOf('2000000000010')); // wrong check digit
        $this->assertNull(InStoreBarcode::sequenceOf('20000000001'));
    }

    public function test_rejects_out_of_range_sequences(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InStoreBarcode::forSequence(0);
    }
}
