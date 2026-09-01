<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DocumentTaxMath;
use Tests\TestCase;

class DocumentTaxMathTest extends TestCase
{
    public function test_exclusive_tax_adds_vat_to_net(): void
    {
        $result = DocumentTaxMath::calculateLine('2', '100.0000', '15.00', false);

        $this->assertSame('200.0000', $result['line_subtotal']);
        $this->assertSame('30.0000', $result['tax_amount']);
        $this->assertSame('230.0000', $result['line_total']);
    }

    public function test_inclusive_tax_backs_out_net(): void
    {
        $result = DocumentTaxMath::calculateLine('1', '115.0000', '15.00', true);

        $this->assertSame('100.0000', $result['line_subtotal']);
        $this->assertSame('15.0000', $result['tax_amount']);
        $this->assertSame('115.0000', $result['line_total']);
    }

    public function test_zero_rate_leaves_price_unchanged(): void
    {
        $exclusive = DocumentTaxMath::calculateLine('3', '10.0000', '0', false);
        $this->assertSame('30.0000', $exclusive['line_subtotal']);
        $this->assertSame('0.0000', $exclusive['tax_amount']);
        $this->assertSame('30.0000', $exclusive['line_total']);

        $inclusive = DocumentTaxMath::calculateLine('3', '10.0000', '0', true);
        $this->assertSame('30.0000', $inclusive['line_subtotal']);
        $this->assertSame('0.0000', $inclusive['tax_amount']);
        $this->assertSame('30.0000', $inclusive['line_total']);
    }

    public function test_sum_totals_aggregates_lines(): void
    {
        $lines = [
            DocumentTaxMath::calculateLine('1', '100.0000', '15.00', false),
            DocumentTaxMath::calculateLine('2', '50.0000', '0', false),
        ];

        $totals = DocumentTaxMath::sumTotals($lines);

        $this->assertSame('200.0000', $totals['subtotal']);
        $this->assertSame('15.0000', $totals['tax_total']);
        $this->assertSame('215.0000', $totals['grand_total']);
    }
}
