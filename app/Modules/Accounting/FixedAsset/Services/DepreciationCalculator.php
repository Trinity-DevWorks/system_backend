<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Services;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use Illuminate\Support\Carbon;

/**
 * Monthly depreciation.
 *
 * Straight line posts round((cost − salvage) / life) and the last month of that life takes the remainder,
 * so the book value ends on salvage. After a change of life or salvage the same rule restarts from the
 * remaining book value and the remaining months.
 *
 * Declining balance posts book value × (rate / 12). The rate defaults to 200% / life in years.
 * It switches to straight line when that is larger, and it never goes below salvage.
 *
 * Depreciation starts the month after the in-service date, unless the asset prorates the first month.
 */
final class DepreciationCalculator
{
    /**
     * @return array{amount: string, book_value_after: string}
     */
    public function monthly(DepreciationProfile $asset, string $month): array
    {
        $book = $this->bookValue($asset);
        $next = $this->nextMonth($asset);
        if ($next === null || $next->format('Y-m') !== $this->month($month)->format('Y-m')) {
            return ['amount' => $this->money('0', $asset), 'book_value_after' => $book];
        }

        $amount = $this->amountDue($asset);
        $after = $this->money($this->sub($book, $amount, $asset), $asset);

        return ['amount' => $amount, 'book_value_after' => $after];
    }

    /**
     * Every remaining month until the book value reaches salvage.
     *
     * @return list<array{month: string, amount: string, book_value_after: string}>
     */
    public function schedule(DepreciationProfile $asset): array
    {
        $rows = [];
        $cursor = $asset;
        $guard = max($asset->usefulLifeMonths, 1) * 3 + 24;

        for ($i = 0; $i < $guard; $i++) {
            $next = $this->nextMonth($cursor);
            if ($next === null) {
                break;
            }
            $step = $this->monthly($cursor, $next->toDateString());
            if ($this->cmp($step['amount'], '0', $asset) === 0) {
                break;
            }
            $rows[] = [
                'month' => $next->toDateString(),
                'amount' => $step['amount'],
                'book_value_after' => $step['book_value_after'],
            ];
            $cursor = $cursor->advance(
                $this->money($this->add($cursor->accumulatedDepreciation, $step['amount'], $asset), $asset),
                $next->copy()->endOfMonth()->toDateString(),
            );
            if ($this->cmp($step['book_value_after'], $asset->salvageValue, $asset) <= 0) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Depreciation still due from the month after `depreciated_through` through `$throughMonth`.
     */
    public function catchUp(DepreciationProfile $asset, string $throughMonth): string
    {
        $through = $this->month($throughMonth);
        $total = '0';
        $cursor = $asset;
        $guard = max($asset->usefulLifeMonths, 1) * 3 + 24;

        for ($i = 0; $i < $guard; $i++) {
            $next = $this->nextMonth($cursor);
            if ($next === null || $next->gt($through)) {
                break;
            }
            $step = $this->monthly($cursor, $next->toDateString());
            if ($this->cmp($step['amount'], '0', $asset) === 0) {
                break;
            }
            $total = $this->add($total, $step['amount'], $asset);
            $cursor = $cursor->advance(
                $this->money($this->add($cursor->accumulatedDepreciation, $step['amount'], $asset), $asset),
                $next->copy()->endOfMonth()->toDateString(),
            );
        }

        return $this->money($total, $asset);
    }

    public function bookValue(DepreciationProfile $asset): string
    {
        return $this->money($this->sub($asset->cost, $asset->accumulatedDepreciation, $asset), $asset);
    }

    /**
     * First month that takes depreciation.
     */
    public function firstMonth(DepreciationProfile $asset): Carbon
    {
        $inService = $this->month($asset->inServiceDate);

        return $asset->prorateFirstMonth ? $inService : $inService->copy()->addMonth();
    }

    private function amountDue(DepreciationProfile $asset): string
    {
        $book = $this->bookValue($asset);
        $room = $this->room($asset, $book);
        if ($this->cmp($room, '0', $asset) <= 0) {
            return $this->money('0', $asset);
        }

        $remaining = $asset->usefulLifeMonths - $this->elapsed($asset);
        if ($remaining <= 1) {
            return $room;
        }

        $amount = $asset->method === DepreciationMethod::DecliningBalance
            ? $this->declining($asset, $book, $room, $remaining)
            : $this->straightLine($asset, $room, $remaining);

        if ($this->cmp($amount, '0', $asset) === 0) {
            $amount = $this->min($room, $this->unit($asset), $asset);
        }

        return $this->min($amount, $room, $asset);
    }

    private function straightLine(DepreciationProfile $asset, string $room, int $remaining): string
    {
        $life = max($asset->usefulLifeMonths, 1);
        $standard = $this->round($this->div($this->sub($asset->cost, $asset->salvageValue, $asset), (string) $life, $asset), $asset);
        $beforePlug = $remaining - 1;
        $projected = $this->mul($standard, (string) $beforePlug, $asset);
        $plug = $this->sub($room, $projected, $asset);
        $tolerance = $this->mul($this->unit($asset), (string) $remaining, $asset);

        if ($this->cmp($standard, '0', $asset) > 0
            && $this->cmp($plug, '0', $asset) >= 0
            && $this->cmp($this->abs($this->sub($plug, $standard, $asset)), $tolerance, $asset) <= 0) {
            return $this->money($standard, $asset);
        }

        return $this->round($this->div($room, (string) $remaining, $asset), $asset);
    }

    private function declining(DepreciationProfile $asset, string $book, string $room, int $remaining): string
    {
        $rate = $asset->decliningRate;
        if ($rate === null || $rate === '' || $this->cmp($rate, '0', $asset) <= 0) {
            $life = max($asset->usefulLifeMonths, 1);
            $rate = $this->div($this->mul('200', '12', $asset), (string) $life, $asset);
        }
        $factor = $this->div($this->div($rate, '100', $asset), '12', $asset);
        $declining = $this->round($this->mul($book, $factor, $asset), $asset);
        $straight = $this->round($this->div($room, (string) $remaining, $asset), $asset);

        return $this->cmp($straight, $declining, $asset) > 0 ? $straight : $declining;
    }

    private function elapsed(DepreciationProfile $asset): int
    {
        if ($asset->depreciatedThrough === null || $asset->depreciatedThrough === '') {
            return 0;
        }

        $through = $this->month($asset->depreciatedThrough);
        $cursor = $this->firstMonth($asset);
        if ($through->lt($cursor)) {
            return 0;
        }

        $count = 0;
        while ($cursor->lte($through) && $count < 2400) {
            $count++;
            $cursor = $cursor->copy()->addMonth();
        }

        return $count;
    }

    private function nextMonth(DepreciationProfile $asset): ?Carbon
    {
        $first = $this->firstMonth($asset);
        if ($asset->depreciatedThrough === null || $asset->depreciatedThrough === '') {
            return $first;
        }

        $after = $this->month($asset->depreciatedThrough)->addMonth();

        return $after->greaterThan($first) ? $after : $first;
    }

    private function room(DepreciationProfile $asset, string $book): string
    {
        $room = $this->sub($book, $asset->salvageValue, $asset);

        return $this->cmp($room, '0', $asset) > 0 ? $this->money($room, $asset) : $this->money('0', $asset);
    }

    private function month(string $date): Carbon
    {
        return Carbon::parse($date)->startOfMonth();
    }

    private function money(string $value, DepreciationProfile $asset): string
    {
        return $this->round($value, $asset);
    }

    private function round(string $value, DepreciationProfile $asset): string
    {
        $scale = $asset->scale;
        $value = bcadd($value, '0', $scale + 6);
        $half = '0.'.str_repeat('0', $scale).'5';
        $shifted = bccomp($value, '0', $scale + 6) < 0
            ? bcsub($value, $half, $scale + 6)
            : bcadd($value, $half, $scale + 6);

        return bcadd($shifted, '0', $scale);
    }

    private function unit(DepreciationProfile $asset): string
    {
        return bcadd(bcpow('10', (string) (-1 * $asset->scale), $asset->scale), '0', $asset->scale);
    }

    private function add(string $left, string $right, DepreciationProfile $asset): string
    {
        return bcadd($left, $right, $asset->scale + 6);
    }

    private function sub(string $left, string $right, DepreciationProfile $asset): string
    {
        return bcsub($left, $right, $asset->scale + 6);
    }

    private function mul(string $left, string $right, DepreciationProfile $asset): string
    {
        return bcmul($left, $right, $asset->scale + 6);
    }

    private function div(string $left, string $right, DepreciationProfile $asset): string
    {
        return bcdiv($left, $right, $asset->scale + 6);
    }

    private function abs(string $value): string
    {
        return bccomp($value, '0', 8) < 0 ? bcmul($value, '-1', 8) : $value;
    }

    private function cmp(string $left, string $right, DepreciationProfile $asset): int
    {
        return bccomp($this->round($left, $asset), $this->round($right, $asset), $asset->scale);
    }

    private function min(string $left, string $right, DepreciationProfile $asset): string
    {
        return $this->cmp($left, $right, $asset) <= 0 ? $this->money($left, $asset) : $this->money($right, $asset);
    }
}
