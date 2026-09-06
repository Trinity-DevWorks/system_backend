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
