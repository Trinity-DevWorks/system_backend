<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\ScaleBarcode\Support\CheckDigit;
use App\Modules\Inventory\ScaleBarcode\Support\Gs1Reader;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleBarcodeParser;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleFormatConfig;
use PHPUnit\Framework\TestCase;

class ScaleBarcodeParserTest extends TestCase
{
    public function test_check_digits(): void
    {
        $this->assertTrue(CheckDigit::isValidMod10('4006381333931'));   // EAN-13
        $this->assertTrue(CheckDigit::isValidMod10('036000291452'));    // UPC-A
        $this->assertTrue(CheckDigit::isValidMod10('09501101530003'));  // GTIN-14
        $this->assertFalse(CheckDigit::isValidMod10('4006381333932'));
        // GS1 price check digit examples (4 and 5 digits).
        $this->assertSame('9', CheckDigit::price('2875'));
        $this->assertSame('6', CheckDigit::price('14685'));
        $this->assertNull(CheckDigit::price('123'));
    }

    public function test_ean13_weight_label(): void
    {
        // 21 | PLU 01234 | 01250 g | check  →  1.250 kg
        $code = self::ean('210123401250');
        $parsed = ScaleBarcodeParser::parse($code, $this->weightFormat());

        $this->assertNotNull($parsed);
        $this->assertSame('01234', $parsed->plu);
        $this->assertSame('weight', $parsed->valueType);
        $this->assertSame('1.250000', $parsed->value);
    }

    public function test_wrong_prefix_length_or_check_digit_is_not_this_format(): void
    {
        $format = $this->weightFormat();
        $this->assertNull(ScaleBarcodeParser::parse(self::ean('230123401250'), $format)); // prefix 23
        $this->assertNull(ScaleBarcodeParser::parse('21012340125', $format));             // 11 digits
        $good = self::ean('210123401250');
        $bad = substr($good, 0, 12).(((int) substr($good, -1) + 1) % 10);
        $this->assertNull(ScaleBarcodeParser::parse($bad, $format));
    }

    public function test_ean13_price_label_with_multiplier_and_price_check_digit(): void
    {
        // 22 | PLU 1234 | price check | price 14685 (thousands) | check  →  14,685,000
        $format = ScaleFormatConfig::fromArray([
            'engine' => 'fixed', 'symbology' => 'ean13', 'prefixes' => ['22'], 'total_length' => 13,
            'plu_start' => 3, 'plu_length' => 4, 'value_start' => 8, 'value_length' => 5,
            'value_type' => 'price', 'decimals' => 0, 'multiplier' => '1000',
            'price_check_digit_position' => 7,
        ]);
        $parsed = ScaleBarcodeParser::parse(self::ean('221234614685'), $format);

        $this->assertNotNull($parsed);
        $this->assertSame('1234', $parsed->plu);
        $this->assertSame('14685000.000000', $parsed->value);
        // A wrong price check digit is refused.
        $this->assertNull(ScaleBarcodeParser::parse(self::ean('221234514685'), $format));
    }

    public function test_upca_price_label(): void
    {
        // 2 | PLU 01234 | price check | 0599 cents | check  →  5.99
        $priceCheck = CheckDigit::price('0599');
        $format = ScaleFormatConfig::fromArray([
            'engine' => 'fixed', 'symbology' => 'upca', 'prefixes' => ['2'], 'total_length' => 12,
            'plu_start' => 2, 'plu_length' => 5, 'value_start' => 8, 'value_length' => 4,
            'value_type' => 'price', 'decimals' => 2, 'price_check_digit_position' => 7,
        ]);
        $parsed = ScaleBarcodeParser::parse(self::ean('201234'.$priceCheck.'0599'), $format);

        $this->assertNotNull($parsed);
        $this->assertSame('01234', $parsed->plu);
        $this->assertSame('5.990000', $parsed->value);
    }

    public function test_custom_code128_layout_with_a_long_price(): void
    {
        // 99 | PLU 001234 | price 012500000 | 0  — a large LBP price that EAN-13 can't hold.
        $format = ScaleFormatConfig::fromArray([
            'engine' => 'fixed', 'symbology' => 'code128', 'prefixes' => ['99'], 'total_length' => 18,
            'plu_start' => 3, 'plu_length' => 6, 'value_start' => 9, 'value_length' => 9,
            'value_type' => 'price', 'decimals' => 0,
        ]);
        $parsed = ScaleBarcodeParser::parse('990012340125000000', $format);

        $this->assertNotNull($parsed);
        $this->assertSame('001234', $parsed->plu);
        $this->assertSame('12500000.000000', $parsed->value);
    }

    public function test_gs1_reader_handles_brackets_fnc1_and_scanner_prefix(): void
    {
        $this->assertSame(
            ['01' => '09501101530003', '3103' => '001250'],
            Gs1Reader::read('(01)09501101530003(3103)001250'),
        );
        $this->assertSame(
            ['01' => '09501101530003', '10' => 'LOT7', '3922' => '1499'],
            Gs1Reader::read(']C10109501101530003'."10LOT7\x1D".'39221499'),
        );
        $this->assertNull(Gs1Reader::read('(01)0950110153000'));   // GTIN too short
        $this->assertNull(Gs1Reader::read('9999123'));              // unknown AI
    }

    public function test_gs1_weight_price_and_currency(): void
    {
        $weight = ScaleBarcodeParser::parse('(01)09501101530003(3103)001250', ScaleFormatConfig::fromArray([
            'engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'weight',
        ]));
        $this->assertNotNull($weight);
        $this->assertSame('09501101530003', $weight->gtin);
        $this->assertSame('1.250000', $weight->value);

        $price = ScaleBarcodeParser::parse('(01)09501101530003(3922)1499', ScaleFormatConfig::fromArray([
            'engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'price',
        ]));
        $this->assertNotNull($price);
        $this->assertSame('14.990000', $price->value);

        // 393n: ISO 4217 numeric currency (422 = LBP) then the amount.
        $lbp = ScaleBarcodeParser::parse('(01)09501101530003(3930)4221500000', ScaleFormatConfig::fromArray([
            'engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'price',
        ]));
        $this->assertNotNull($lbp);
        $this->assertSame('422', $lbp->currencyIsoNumeric);
        $this->assertSame('1500000.000000', $lbp->value);

        // PLU taken from inside the GTIN when the format says where it is.
        $plu = ScaleBarcodeParser::parse('(01)09501101530003(3103)001250', ScaleFormatConfig::fromArray([
            'engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'weight', 'plu_start' => 9, 'plu_length' => 5,
        ]));
        $this->assertSame('53000', $plu?->plu);
    }

    public function test_gs1_reads_unit_weight_price_lot_and_dates_together(): void
    {
        $parsed = ScaleBarcodeParser::parse(
            '(01)09501101530003(3202)000450(3922)1499(10)LOT7(17)261231(15)261200',
            ScaleFormatConfig::fromArray(['engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'weight']),
        );

        $this->assertNotNull($parsed);
        $this->assertSame('4.500000', $parsed->weight);
        $this->assertSame('lb', $parsed->weightUnit);
        $this->assertSame('14.990000', $parsed->price);
        $this->assertSame('LOT7', $parsed->lot);
        $this->assertSame('2026-12-31', $parsed->expiry);
        $this->assertSame('2026-12-31', $parsed->bestBefore); // day 00 = last day of the month
    }

    public function test_gs1_dates(): void
    {
        $this->assertSame('2027-02-28', ScaleBarcodeParser::gs1Date('270200', 2026));
        $this->assertSame('2028-02-29', ScaleBarcodeParser::gs1Date('280200', 2026));
        $this->assertSame('1999-05-01', ScaleBarcodeParser::gs1Date('990501', 2026)); // more than 50 years ahead → past century
        $this->assertNull(ScaleBarcodeParser::gs1Date('261301', 2026));
        $this->assertNull(ScaleBarcodeParser::gs1Date('260231', 2026));
    }

    public function test_gs1_without_the_requested_value_is_not_this_format(): void
    {
        $this->assertNull(ScaleBarcodeParser::parse('(01)09501101530003(3103)001250', ScaleFormatConfig::fromArray([
            'engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'price',
        ])));
    }

    private function weightFormat(): ScaleFormatConfig
    {
        return ScaleFormatConfig::fromArray([
            'engine' => 'fixed', 'symbology' => 'ean13', 'prefixes' => ['21'], 'total_length' => 13,
            'plu_start' => 3, 'plu_length' => 5, 'value_start' => 8, 'value_length' => 5,
            'value_type' => 'weight', 'decimals' => 3,
        ]);
    }

    private static function ean(string $body): string
    {
        return $body.CheckDigit::mod10($body);
    }
}
