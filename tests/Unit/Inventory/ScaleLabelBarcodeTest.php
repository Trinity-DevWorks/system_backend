<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\ScaleBarcode\Support\CheckDigit;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleBarcodeBuilder;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleBarcodeParser;
use App\Modules\Inventory\ScaleBarcode\Support\ScaleFormatConfig;
use App\Support\Barcode\BarcodeSvg;
use App\Support\Export\DelimitedFileWriter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Printed scale labels: the bars (EAN-13 / UPC-A / Code 128), the code built for a format (must parse
 * back to the same PLU and value), and the export file writer.
 */
class ScaleLabelBarcodeTest extends TestCase
{
    public function test_code128_table_is_well_formed(): void
    {
        $this->assertCount(107, BarcodeSvg::CODE128);
        $this->assertCount(107, array_unique(BarcodeSvg::CODE128));
        foreach (BarcodeSvg::CODE128 as $value => $widths) {
            $sum = array_sum(array_map('intval', str_split($widths)));
            $this->assertSame($value === 106 ? 13 : 11, $sum, 'Code 128 value '.$value);
        }
    }

    public function test_code128_known_encodings(): void
    {
        // Start B, "Wikipedia", check 88, stop.
        $this->assertSame([104, 55, 73, 75, 73, 80, 69, 68, 73, 65, 88, 106], BarcodeSvg::code128Values('Wikipedia'));
        $modules = BarcodeSvg::code128Modules('Wikipedia');
        $this->assertStringStartsWith('11010010000', $modules);   // Start B
        $this->assertStringEndsWith('1100011101011', $modules);   // Stop
        // Digits use set C; a GS1 string starts with FNC1.
        $this->assertSame([105, 12, 34, 56, (105 + 12 + 34 * 2 + 56 * 3) % 103, 106], BarcodeSvg::code128Values('123456'));
        $gs1 = BarcodeSvg::code128Values("\x1D0100000000012348");
        $this->assertSame([105, 102, 1, 0, 0, 0, 0, 1, 23, 48], array_slice($gs1, 0, 10));
        // Odd digit run then letters: C pairs, then switch to B.
        $mixed = BarcodeSvg::code128Values('12345A');
        $this->assertSame([105, 12, 34, 100, 21, 33], array_slice($mixed, 0, 6));
    }

    public function test_code128_modules_decode_back(): void
    {
        $data = "\x1D0109501101530003310300125017261231\x1D39220123";
        $modules = BarcodeSvg::code128Modules($data);
        $values = $this->decodeCode128($modules);
        $this->assertSame(BarcodeSvg::code128Values($data), $values);
        $check = $values[0];
        foreach (array_slice($values, 1, -2) as $i => $v) {
            $check += $v * ($i + 1);
        }
        $this->assertSame($check % 103, $values[count($values) - 2]);
    }

    public function test_ean13_and_upca_bars(): void
    {
        $modules = BarcodeSvg::ean13Modules('4006381333931');
        $this->assertSame(95, strlen($modules));
        $this->assertStringStartsWith('101', $modules);
        $this->assertStringEndsWith('101', $modules);
        $this->assertSame('01010', substr($modules, 45, 5));
        // 12 digits get their check digit.
        $this->assertSame($modules, BarcodeSvg::ean13Modules('400638133393'));
        // UPC-A = EAN-13 with a leading 0 (first six digits all in set L).
        $this->assertSame(BarcodeSvg::ean13Modules('0036000291452'), BarcodeSvg::upcaModules('036000291452'));
        // Right-hand digit 1 (R set) = 1100110.
        $this->assertSame('1100110', substr($modules, 50 + 7 * 5, 7));
        $svg = BarcodeSvg::svg('ean13', '4006381333931');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
    }

    public function test_built_fixed_codes_parse_back(): void
    {
        $cases = [
            // weight in grams: 1.25 kg → 01250
            [['prefixes' => ['21'], 'plu_start' => 3, 'plu_length' => 5, 'value_start' => 8, 'value_length' => 5, 'value_type' => 'weight', 'decimals' => 3], '1.25', '1.250000'],
            // price with 2 decimals
            [['prefixes' => ['22'], 'plu_start' => 3, 'plu_length' => 5, 'value_start' => 8, 'value_length' => 5, 'value_type' => 'price', 'decimals' => 2], '12.5', '12.500000'],
            // price ×1000 (LBP-style), price check digit in position 8
            [['prefixes' => ['23'], 'plu_start' => 3, 'plu_length' => 4, 'price_check_digit_position' => 7, 'value_start' => 8, 'value_length' => 5, 'value_type' => 'price', 'decimals' => 0, 'multiplier' => '1000'], '45000', '45000.000000'],
            // count
            [['prefixes' => ['24'], 'plu_start' => 3, 'plu_length' => 5, 'value_start' => 8, 'value_length' => 5, 'value_type' => 'count', 'decimals' => 0], '6', '6.000000'],
            // UPC-A weight
            [['symbology' => 'upca', 'prefixes' => ['2'], 'plu_start' => 2, 'plu_length' => 5, 'value_start' => 7, 'value_length' => 5, 'value_type' => 'weight', 'decimals' => 3], '0.75', '0.750000'],
            // Code 128, no check digit, 14 long
            [['symbology' => 'code128', 'total_length' => 14, 'prefixes' => ['99'], 'plu_start' => 3, 'plu_length' => 6, 'value_start' => 9, 'value_length' => 6, 'value_type' => 'weight', 'decimals' => 3], '2.5', '2.500000'],
        ];
        foreach ($cases as [$data, $value, $expected]) {
            $format = ScaleFormatConfig::fromArray(['engine' => 'fixed', 'symbology' => 'ean13', ...$data]);
            $built = ScaleBarcodeBuilder::build($format, '1234', $value);
            $parsed = ScaleBarcodeParser::parse($built['data'], $format);
            $this->assertNotNull($parsed, 'Did not parse back: '.$built['data']);
            $this->assertSame(ltrim('1234', '0'), ltrim((string) $parsed->plu, '0'));
            $this->assertSame($expected, $parsed->value, $built['data']);
            BarcodeSvg::modules($built['symbology'], $built['data']);
        }
    }

    public function test_built_gs1_codes_parse_back(): void
    {
        $weight = ScaleFormatConfig::fromArray(['engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'weight', 'decimals' => 3]);
        $built = ScaleBarcodeBuilder::build($weight, '1234', '1.25', gtin: '09501101530003', weight: '1.25', price: '12.5', useBy: '2026-12-31');
        $this->assertSame('(01)09501101530003(3103)001250(17)261231(3922)1250', $built['text']);
        foreach ([$built['data'], $built['text']] as $code) {
            $parsed = ScaleBarcodeParser::parse($code, $weight);
            $this->assertNotNull($parsed);
            $this->assertSame('09501101530003', $parsed->gtin);
            $this->assertSame('1.250000', $parsed->value);
            $this->assertSame('12.500000', $parsed->price);
            $this->assertSame('2026-12-31', $parsed->expiry);
        }

        // The format's decimals don't limit GS1: 1.25 kg with decimals 0 still writes 3103 001250.
        $zero = ScaleFormatConfig::fromArray(['engine' => 'gs1', 'symbology' => 'gs1_128', 'value_type' => 'weight', 'decimals' => 0]);
        $built = ScaleBarcodeBuilder::build($zero, '1234', '1.25', gtin: '09501101530003', price: '2.875');
        $this->assertSame('(01)09501101530003(3103)001250(3922)288', $built['text']);
        $this->assertSame('1.250000', ScaleBarcodeParser::parse($built['data'], $zero)?->value);

        // No GTIN on the item: built from prefix + PLU inside the GTIN.
        $plu = ScaleFormatConfig::fromArray(['engine' => 'gs1', 'symbology' => 'gs1_128', 'prefixes' => ['02'], 'plu_start' => 3, 'plu_length' => 5, 'value_type' => 'count']);
        $built = ScaleBarcodeBuilder::build($plu, '01234', '6');
        $parsed = ScaleBarcodeParser::parse($built['data'], $plu);
        $this->assertNotNull($parsed);
        $this->assertSame('01234', $parsed->plu);
        $this->assertSame('6.000000', $parsed->value);
        $this->assertTrue(CheckDigit::isValidMod10((string) $parsed->gtin));
    }

    public function test_values_that_do_not_fit_are_refused(): void
    {
        $format = ScaleFormatConfig::fromArray(['engine' => 'fixed', 'symbology' => 'ean13', 'prefixes' => ['21'], 'plu_start' => 3, 'plu_length' => 5, 'value_start' => 8, 'value_length' => 5, 'value_type' => 'weight', 'decimals' => 3]);
        foreach ([['1234', '100'], ['1234567', '1']] as [$plu, $value]) {
            try {
                ScaleBarcodeBuilder::build($format, $plu, $value);
                $this->fail('Expected a refusal for '.$plu.' / '.$value);
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertContains($e->getHeaders()['X-Error-Code'], ['SCALE_LABEL_VALUE_TOO_BIG', 'SCALE_LABEL_PLU_TOO_LONG']);
            }
        }
    }

    public function test_delimited_writer(): void
    {
        $rows = [['PLU', 'Name', 'Price'], ['00001', 'Cheese; white', '10.50'], ['00002', 'Say "hi"', '3']];
        $base = ['quote' => 'needed', 'encoding' => 'utf8', 'line_ending' => 'lf'];

        $this->assertSame("PLU;Name;Price\n00001;\"Cheese; white\";10.50\n00002;\"Say \"\"hi\"\"\";3\n", DelimitedFileWriter::write($rows, [...$base, 'delimiter' => ';']));
        $this->assertSame("PLU\tName\tPrice\r\n", substr(DelimitedFileWriter::write($rows, [...$base, 'delimiter' => 'tab', 'line_ending' => 'crlf']), 0, 16));
        $this->assertSame("\"PLU\",\"Name\",\"Price\"\n", explode('|', str_replace("\n", "\n|", DelimitedFileWriter::write($rows, [...$base, 'delimiter' => ',', 'quote' => 'always'])))[0]);
        $this->assertStringContainsString('00001,Cheese; white,10.50', DelimitedFileWriter::write($rows, [...$base, 'delimiter' => ',', 'quote' => 'never']));

        $fixed = DelimitedFileWriter::write([['1', 'جبنة', '10.5']], [
            ...$base, 'delimiter' => 'fixed', 'widths' => [5, 6, 7], 'pad_chars' => ['0', ' ', ' '], 'pad_sides' => ['left', 'right', 'left'],
        ]);
        $this->assertSame("00001جبنة     10.5\n", $fixed);

        $this->assertStringStartsWith(DelimitedFileWriter::BOM, DelimitedFileWriter::write($rows, [...$base, 'delimiter' => ',', 'encoding' => 'utf8_bom']));

        $arabic = DelimitedFileWriter::write([['جبنة']], [...$base, 'delimiter' => ',', 'encoding' => 'windows1256']);
        $this->assertSame('جبنة'."\n", iconv('WINDOWS-1256', 'UTF-8', $arabic));
        $this->assertSame(5, strlen($arabic));

        try {
            DelimitedFileWriter::write([['Cheese 🧀']], [...$base, 'delimiter' => ',', 'encoding' => 'windows1256']);
            $this->fail('Expected a refusal');
        } catch (HttpException $e) {
            $this->assertSame('SCALE_EXPORT_ENCODING_UNSUPPORTED', $e->getHeaders()['X-Error-Code']);
        }
    }

    /**
     * Reads bars back to Code 128 values (independent of the encoder: only the width table).
     *
     * @return list<int>
     */
    private function decodeCode128(string $modules): array
    {
        $runs = [];
        preg_match_all('/1+|0+/', $modules, $m);
        foreach ($m[0] as $run) {
            $runs[] = strlen($run);
        }
        $lookup = array_flip(BarcodeSvg::CODE128);
        $values = [];
        for ($i = 0; $i + 6 <= count($runs); $i += 6) {
            $key = implode('', array_slice($runs, $i, 6));
            if (count($runs) - $i === 7) {
                $key .= $runs[$i + 6];
            }
            $this->assertArrayHasKey($key, $lookup, 'Unknown symbol '.$key);
            $values[] = $lookup[$key];
            if ($lookup[$key] === 106) {
                break;
            }
        }

        return $values;
    }
}
