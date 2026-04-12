<?php

declare(strict_types=1);

namespace App\Modules\Currency\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\CurrencyPairRate;
use App\Modules\Currency\Models\CurrencyPairRateHistory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ExchangeRateService
{
    /**
     * Current rate: amount in "to" = amount in "from" × rate (as stored for from → to).
     *
     * @throws \InvalidArgumentException
     */
    public function getRate(string $fromCode, string $toCode): float
    {
        if ($fromCode === $toCode) {
            return 1.0;
        }

        $fromCurrency = Currency::query()->where('code', $fromCode)->first();
        $toCurrency = Currency::query()->where('code', $toCode)->first();

        if (! $fromCurrency || ! $toCurrency) {
            throw new \InvalidArgumentException("One or both currencies not found: {$fromCode} -> {$toCode}");
        }

        return $this->getRateById((int) $fromCurrency->id, (int) $toCurrency->id);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function getRateById(int $fromCurrencyId, int $toCurrencyId): float
    {
        if ($fromCurrencyId === $toCurrencyId) {
            return 1.0;
        }

        $pair = CurrencyPairRate::query()
            ->where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $toCurrencyId)
            ->first();

        if ($pair) {
            return (float) $pair->rate;
        }

        $pairReversed = CurrencyPairRate::query()
            ->where('from_currency_id', $toCurrencyId)
            ->where('to_currency_id', $fromCurrencyId)
            ->first();

        if ($pairReversed) {
            $r = (float) $pairReversed->rate;
            if ($r <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than 0');
            }

            return 1.0 / $r;
        }

        $from = Currency::query()->find($fromCurrencyId);
        $to = Currency::query()->find($toCurrencyId);
        $fromCode = $from ? $from->code : (string) $fromCurrencyId;
        $toCode = $to ? $to->code : (string) $toCurrencyId;

        throw new \InvalidArgumentException("No exchange rate defined for pair: {$fromCode} -> {$toCode}");
    }

    /**
     * Set or update a pair rate (1 from = rate × to). Archives the previous row to history when the rate changes.
     *
     * @throws \InvalidArgumentException
     */
    public function setPairRate(int $fromCurrencyId, int $toCurrencyId, float $rate, ?string $updatedBy = null): CurrencyPairRate
    {
        if ($fromCurrencyId === $toCurrencyId) {
            throw new \InvalidArgumentException('From and to currency must be different');
        }
        if ($rate <= 0) {
            throw new \InvalidArgumentException('Exchange rate must be greater than 0');
        }

        $existing = CurrencyPairRate::query()
            ->where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $toCurrencyId)
            ->first();

        $reverse = CurrencyPairRate::query()
            ->where('from_currency_id', $toCurrencyId)
            ->where('to_currency_id', $fromCurrencyId)
            ->first();

        if ($reverse && ! $existing) {
            $effectiveFrom = $reverse->effective_from ?? $reverse->created_at ?? now();
            CurrencyPairRateHistory::query()->create([
                'from_currency_id' => $reverse->from_currency_id,
                'to_currency_id' => $reverse->to_currency_id,
                'rate' => $reverse->rate,
                'effective_from' => $effectiveFrom,
                'effective_to' => now(),
                'updated_by' => $updatedBy,
            ]);
            $reverse->update([
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $toCurrencyId,
                'rate' => $rate,
                'effective_from' => now(),
            ]);

            return $reverse->fresh();
        }

        if ($existing) {
            $effectiveFrom = $existing->effective_from ?? $existing->created_at ?? now();
            CurrencyPairRateHistory::query()->create([
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $toCurrencyId,
                'rate' => $existing->rate,
                'effective_from' => $effectiveFrom,
                'effective_to' => now(),
                'updated_by' => $updatedBy,
            ]);
        }

        return CurrencyPairRate::query()->updateOrCreate(
            [
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $toCurrencyId,
            ],
            [
                'rate' => $rate,
                'effective_from' => now(),
            ]
        )->fresh();
    }

    /**
     * Rate as of a calendar date using history windows, else current pair table.
     *
     * @return array{rate: float, source: 'current'|'history', effective_from?: string, effective_to?: string|null}
     */
    public function getRateAsOfDate(string $fromCode, string $toCode, string $date): array
    {
        if ($fromCode === $toCode) {
            return ['rate' => 1.0, 'source' => 'current'];
        }

        $fromCurrency = Currency::query()->where('code', $fromCode)->first();
        $toCurrency = Currency::query()->where('code', $toCode)->first();
        if (! $fromCurrency || ! $toCurrency) {
            throw new \InvalidArgumentException("One or both currencies not found: {$fromCode} -> {$toCode}");
        }

        $fromId = (int) $fromCurrency->id;
        $toId = (int) $toCurrency->id;
        $dateStart = $date.' 00:00:00';
        $dateEnd = $date.' 23:59:59';

        $historyRow = CurrencyPairRateHistory::query()
            ->with(['fromCurrency', 'toCurrency'])
            ->where('from_currency_id', $fromId)
            ->where('to_currency_id', $toId)
            ->where('effective_from', '<=', $dateEnd)
            ->where(function ($q) use ($dateStart): void {
                $q->where('effective_to', '>=', $dateStart)
                    ->orWhereNull('effective_to');
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($historyRow) {
            return [
                'rate' => (float) $historyRow->rate,
                'source' => 'history',
                'effective_from' => $historyRow->effective_from?->toIso8601String(),
                'effective_to' => $historyRow->effective_to?->toIso8601String(),
            ];
        }

        $historyReversed = CurrencyPairRateHistory::query()
            ->with(['fromCurrency', 'toCurrency'])
            ->where('from_currency_id', $toId)
            ->where('to_currency_id', $fromId)
            ->where('effective_from', '<=', $dateEnd)
            ->where(function ($q) use ($dateStart): void {
                $q->where('effective_to', '>=', $dateStart)
                    ->orWhereNull('effective_to');
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($historyReversed) {
            $r = (float) $historyReversed->rate;
            if ($r <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than 0');
            }

            return [
                'rate' => 1.0 / $r,
                'source' => 'history',
                'effective_from' => $historyReversed->effective_from?->toIso8601String(),
                'effective_to' => $historyReversed->effective_to?->toIso8601String(),
            ];
        }

        $rate = $this->getRate($fromCode, $toCode);

        return ['rate' => $rate, 'source' => 'current'];
    }

    /**
     * All pairs where this currency is "from", with archived periods and current row.
     *
     * @return array{currency: Currency, pairs: array<int, array{to_currency: Currency, current_rate: float, history: Collection<int, array<string, mixed>>}>}
     */
    public function getPairRateHistory(int $currencyId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $currency = Currency::query()->findOrFail($currencyId);
        $from = $fromDate ? Carbon::parse($fromDate)->startOfDay() : null;
        $to = $toDate ? Carbon::parse($toDate)->endOfDay() : null;

        $pairs = CurrencyPairRate::query()
            ->where('from_currency_id', $currencyId)
            ->with('toCurrency')
            ->get();

        $result = [];
        foreach ($pairs as $pair) {
            $historyQuery = CurrencyPairRateHistory::query()
                ->select([
                    'id', 'from_currency_id', 'to_currency_id', 'rate',
                    'effective_from', 'effective_to', 'updated_by',
                ])
                ->where('from_currency_id', $pair->from_currency_id)
                ->where('to_currency_id', $pair->to_currency_id)
                ->orderBy('effective_from');

            if ($from) {
                $historyQuery->where('effective_to', '>=', $from);
            }
            if ($to) {
                $historyQuery->where('effective_from', '<=', $to);
            }

            /** @var Collection<int, array<string, mixed>> $history */
            $history = $historyQuery->get()->map(fn (CurrencyPairRateHistory $row): array => [
                'rate' => (float) $row->rate,
                'effective_from' => $row->effective_from->toIso8601String(),
                'effective_to' => $row->effective_to->toIso8601String(),
                'updated_by' => $row->updated_by,
            ]);

            $pairEffectiveFrom = $pair->effective_from ?? $pair->created_at;
            if ($pairEffectiveFrom) {
                $effectiveFrom = $pairEffectiveFrom instanceof Carbon
                    ? $pairEffectiveFrom
                    : Carbon::parse((string) $pairEffectiveFrom);
                $history->push([
                    'rate' => (float) $pair->rate,
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_to' => null,
                    'updated_by' => null,
                ]);
            }

            $toCurrency = $pair->toCurrency;
            if ($toCurrency === null) {
                continue;
            }

            $result[] = [
                'to_currency' => $toCurrency,
                'current_rate' => (float) $pair->rate,
                'history' => $history->values(),
            ];
        }

        return [
            'currency' => $currency,
            'pairs' => $result,
        ];
    }
}
