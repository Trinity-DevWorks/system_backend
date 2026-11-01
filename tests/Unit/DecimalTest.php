<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanySetting\Enums\PriceRoundingMode;
use App\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_floats_from_json_become_the_decimal_that_was_typed(): void
    {
        $this->assertSame('0.1', Decimal::of(0.1));
        $this->assertSame('1500000000.1234', Decimal::of(1500000000.1234));
        $this->assertSame('0.0000001', Decimal::of(1.0E-7));
        $this->assertSame('12', Decimal::of(12));
        $this->assertSame('0', Decimal::of(null));
        $this->assertSame('0', Decimal::of('abc'));
        $this->assertSame('0.5', Decimal::of('.5'));
        $this->assertSame('-0.5', Decimal::of('-.5'));
    }

    public function test_addition_is_exact(): void
    {
        $this->assertSame(0, Decimal::cmp(Decimal::add(0.1, 0.2), '0.3'));
        $this->assertSame('3000000000.2468', Decimal::round(Decimal::add('1500000000.1234', '1500000000.1234'), 4));
    }

    /**
     * @return array<string, array{string, int, PriceRoundingMode, string}>
     */
    public static function roundingCases(): array
    {
        return [
            'half up .5' => ['2.345', 2, PriceRoundingMode::HalfUp, '2.35'],
            'half up negative' => ['-2.345', 2, PriceRoundingMode::HalfUp, '-2.35'],
            'half up below half' => ['2.3449999', 2, PriceRoundingMode::HalfUp, '2.34'],
            'half even down' => ['2.345', 2, PriceRoundingMode::HalfEven, '2.34'],
            'half even up' => ['2.355', 2, PriceRoundingMode::HalfEven, '2.36'],
            'half even above half' => ['2.3451', 2, PriceRoundingMode::HalfEven, '2.35'],
            'up' => ['2.341', 2, PriceRoundingMode::Up, '2.35'],
            'up exact' => ['2.340000', 2, PriceRoundingMode::Up, '2.34'],
            'up negative' => ['-2.341', 2, PriceRoundingMode::Up, '-2.35'],
            'down' => ['2.349', 2, PriceRoundingMode::Down, '2.34'],
            'down negative' => ['-2.349', 2, PriceRoundingMode::Down, '-2.34'],
            'scale 0' => ['2.5', 0, PriceRoundingMode::HalfUp, '3'],
            'pads' => ['7', 4, PriceRoundingMode::HalfUp, '7.0000'],
            'no negative zero' => ['-0.001', 2, PriceRoundingMode::HalfUp, '0.00'],
            'large LBP' => ['1500000000.12345', 4, PriceRoundingMode::HalfUp, '1500000000.1235'],
            'gram cost' => ['0.0000125', 6, PriceRoundingMode::HalfUp, '0.000013'],
        ];
    }

    #[DataProvider('roundingCases')]
    public function test_rounding_modes(string $value, int $scale, PriceRoundingMode $mode, string $expected): void
    {
        $this->assertSame($expected, Decimal::round($value, $scale, $mode));
    }

    public function test_division_and_comparisons(): void
    {
        $this->assertSame('0.285714285714', Decimal::div('1', '3.5'));
        $this->assertSame('0.000000000000', Decimal::div('1', '0'));
        $this->assertTrue(Decimal::isZero('0.0000'));
        $this->assertTrue(Decimal::isNegative('-0.0001'));
        $this->assertSame('5', Decimal::abs('-5'));
        $this->assertSame('2', Decimal::min('2', '3'));
        $this->assertSame('3', Decimal::max('2', '3'));
    }
}
