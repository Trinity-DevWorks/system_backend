<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DocumentTaxMath;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DocumentTaxMathTest extends TestCase
{
    public function test_exclusive_line_adds_tax_after_discount(): void
    {
        $line = DocumentTaxMath::line('2', '10', '10', '10', false, null, 2);

        $this->assertSame('20.00', $line['merchandise']);
        $this->assertSame('2.00', $line['discount_amount']);
        $this->assertSame('18.00', $line['line_subtotal']);
        $this->assertSame('1.80', $line['tax_amount']);
        $this->assertSame('19.80', $line['line_total']);
    }

    public function test_inclusive_line_backs_out_tax_after_discount(): void
    {
        $line = DocumentTaxMath::line('1', '11', '0', '10', true, null, 2);

        $this->assertSame('11.00', $line['merchandise']);
        $this->assertSame('0.00', $line['discount_amount']);
        $this->assertSame('10.00', $line['line_subtotal']);
        $this->assertSame('1.00', $line['tax_amount']);
        $this->assertSame('11.00', $line['line_total']);
    }

    public function test_bill_discount_percent_is_spread_over_lines_before_tax(): void
    {
        $lines = DocumentTaxMath::lines([
            ['quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'tax_rate' => '10'],
            ['quantity' => '3', 'unit_price' => '100', 'discount_percent' => '0', 'tax_rate' => '10'],
        ], 'percent', '10', false, null, 2);

        $this->assertSame(['10.00', '30.00'], array_column($lines, 'header_discount_amount'));
        $this->assertSame('90.00', $lines[0]['line_subtotal']);
        $this->assertSame('9.00', $lines[0]['tax_amount']);
        $this->assertSame('297.00', $lines[1]['line_total']);
        $this->assertSame('30.00', $lines[1]['discount_amount']);
    }

    public function test_bill_discount_amount_comes_after_line_discount_and_last_line_takes_the_remainder(): void
    {
        $lines = DocumentTaxMath::lines([
            ['quantity' => '1', 'unit_price' => '1', 'discount_percent' => '0', 'tax_rate' => '0'],
            ['quantity' => '1', 'unit_price' => '1', 'discount_percent' => '0', 'tax_rate' => '0'],
            ['quantity' => '1', 'unit_price' => '2', 'discount_percent' => '50', 'tax_rate' => '0'],
        ], 'amount', '1', false, null, 2);

        // Bases after line discounts: 1, 1, 1 → 0.33, 0.33 and the last line 0.34.
        $this->assertSame(['0.33', '0.33', '0.34'], array_column($lines, 'header_discount_amount'));
        $this->assertSame('1.34', $lines[2]['discount_amount']);
        $this->assertSame('0.66', $lines[2]['line_total']);
    }

    public function test_bill_discount_with_prices_including_tax(): void
    {
        $lines = DocumentTaxMath::lines([
            ['quantity' => '1', 'unit_price' => '110', 'discount_percent' => '0', 'tax_rate' => '10'],
        ], 'percent', '10', true, null, 2);

        $this->assertSame('99.00', $lines[0]['line_total']);
        $this->assertSame('90.00', $lines[0]['line_subtotal']);
        $this->assertSame('9.00', $lines[0]['tax_amount']);
    }

    public function test_bill_discount_larger_than_the_total_is_rejected(): void
    {
        $this->expectException(HttpException::class);

        DocumentTaxMath::lines([
            ['quantity' => '1', 'unit_price' => '10', 'discount_percent' => '0', 'tax_rate' => '0'],
        ], 'amount', '11', false, null, 2);
    }

    public function test_exclusive_header_adds_tax_and_adjustment(): void
    {
        $totals = DocumentTaxMath::sumTotals(
            [
                ['merchandise' => '20.00', 'discount_amount' => '2.00', 'tax_amount' => '1.80'],
            ],
            '0.20',
            false,
            null,
            2,
        );

        $this->assertSame('20.00', $totals['subtotal']);
        $this->assertSame('2.00', $totals['discount_total']);
        $this->assertSame('1.80', $totals['tax_total']);
        $this->assertSame('20.00', $totals['grand_total']);
        $this->assertSame('20.00', $totals['net_to_pay']);
    }

    public function test_inclusive_header_does_not_add_tax_again(): void
    {
        $totals = DocumentTaxMath::sumTotals(
            [
                ['merchandise' => '11.00', 'discount_amount' => '0.00', 'tax_amount' => '1.00'],
            ],
            '0',
            true,
            null,
            2,
        );

        $this->assertSame('11.00', $totals['grand_total']);
    }

    public function test_negative_grand_total_is_rejected(): void
    {
        $this->expectException(HttpException::class);

        DocumentTaxMath::sumTotals(
            [
                ['merchandise' => '10.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00'],
            ],
            '-20',
            false,
            null,
            2,
        );
    }
}
