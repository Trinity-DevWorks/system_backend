<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Services;

use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostMethod;
use App\Support\Decimal;

/**
 * Spreads one charge over receipt lines. Pure math: no database.
 * Shares are rounded to 4 decimals and the rounding remainder goes to the line with the largest
 * basis, so the shares always add up to the charge exactly.
 */
class LandedCostAllocator
{
    /**
     * @param  list<array{key: string, item_name: string, received_quantity: string, value: string, weight: string|null, volume: string|null}>  $lines
     * @param  array<string, string>  $manual  key => amount, used by the manual method
     * @return array<string, array{basis: string, amount: string}> keyed by line key
     */
    public function allocate(string $amount, LandedCostMethod $method, array $lines, array $manual = []): array
    {
        $amount = Decimal::round($amount, 4);
        if ($lines === []) {
            abort(422, 'Choose at least one receipt with stock lines.', ['X-Error-Code' => 'LANDED_COST_NO_RECEIPT_LINES']);
        }

        if ($method === LandedCostMethod::Manual) {
            return $this->manual($amount, $lines, $manual);
        }

        $missing = [];
        $bases = [];
        foreach ($lines as $line) {
            $qty = $line['received_quantity'];
            $basis = match ($method) {
                LandedCostMethod::Value => Decimal::of($line['value']),
                LandedCostMethod::Quantity => Decimal::of($qty),
                LandedCostMethod::Weight => $line['weight'] !== null && Decimal::isPositive($line['weight']) ? Decimal::mul($qty, $line['weight']) : null,
                LandedCostMethod::Volume => $line['volume'] !== null && Decimal::isPositive($line['volume']) ? Decimal::mul($qty, $line['volume']) : null,
            };
            if ($basis === null) {
                $missing[$line['item_name']] = true;

                continue;
            }
            $bases[$line['key']] = Decimal::isNegative($basis) ? '0' : $basis;
        }

        if ($missing !== []) {
            $what = $method === LandedCostMethod::Weight ? 'weight' : 'volume';
            abort(422, sprintf('Set the %s on: %s.', $what, implode(', ', array_keys($missing))), [
                'X-Error-Code' => $method === LandedCostMethod::Weight ? 'LANDED_COST_WEIGHT_MISSING' : 'LANDED_COST_VOLUME_MISSING',
            ]);
        }

        $total = array_reduce($bases, fn (string $sum, string $b): string => Decimal::add($sum, $b), '0');
        if (! Decimal::isPositive($total)) {
            abort(422, 'The receipt lines have nothing to spread this charge by (all zero).', ['X-Error-Code' => 'LANDED_COST_BASIS_ZERO']);
        }

        $out = [];
        $allocated = '0';
        $largestKey = null;
        foreach ($bases as $key => $basis) {
            $share = Decimal::round(Decimal::div(Decimal::mul($amount, $basis), $total, 12), 4);
            $out[$key] = ['basis' => Decimal::round($basis, 6), 'amount' => $share];
            $allocated = Decimal::add($allocated, $share);
            if ($largestKey === null || Decimal::cmp($basis, $bases[$largestKey]) > 0) {
                $largestKey = $key;
            }
        }

        $remainder = Decimal::sub($amount, $allocated);
        if ($largestKey !== null && ! Decimal::isZero($remainder)) {
            $out[$largestKey]['amount'] = Decimal::round(Decimal::add($out[$largestKey]['amount'], $remainder), 4);
        }

        return $out;
    }

    /**
     * @param  list<array{key: string, item_name: string, received_quantity: string, value: string, weight: string|null, volume: string|null}>  $lines
     * @param  array<string, string>  $manual
     * @return array<string, array{basis: string, amount: string}>
     */
    private function manual(string $amount, array $lines, array $manual): array
    {
        $out = [];
        $sum = '0';
        foreach ($lines as $line) {
            $share = Decimal::round($manual[$line['key']] ?? '0', 4);
            if (Decimal::isNegative($share)) {
                abort(422, 'Manual shares cannot be negative.', ['X-Error-Code' => 'LANDED_COST_MANUAL_MISMATCH']);
            }
            $out[$line['key']] = ['basis' => $share, 'amount' => $share];
            $sum = Decimal::add($sum, $share);
        }

        if (Decimal::cmp(Decimal::round($sum, 4), $amount) !== 0) {
            abort(422, sprintf('Manual shares add up to %s, but the charge is %s.', Decimal::round($sum, 2), Decimal::round($amount, 2)), [
                'X-Error-Code' => 'LANDED_COST_MANUAL_MISMATCH',
            ]);
        }

        return $out;
    }
}
