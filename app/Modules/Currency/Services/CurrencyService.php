<?php

declare(strict_types=1);

namespace App\Modules\Currency\Services;

use App\Modules\Currency\DTOs\CurrencyData;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\CurrencyPairRate;
use App\Modules\Currency\Models\TenantSetting;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CurrencyService
{
    private const CACHE_LIST = 'currencies.list';

    public function __construct(
        private readonly ExchangeRateService $exchangeRateService
    ) {}

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Currency::class,
            fn (): Collection => Currency::query()->orderBy('code')->get()
        );
    }

    /**
     * All stored pair rates (current rows only — no history). For exchange-rates UI.
     *
     * @return list<array{id: int, from_currency_id: int, to_currency_id: int, from_code: string|null, to_code: string|null, rate: float, effective_from: string|null}>
     */
    public function listPairRates(): array
    {
        $pairs = CurrencyPairRate::query()
            ->with([
                'fromCurrency:id,code,name',
                'toCurrency:id,code,name',
            ])
            ->orderBy('from_currency_id')
            ->orderBy('to_currency_id')
            ->get();

        return $pairs->map(static function (CurrencyPairRate $row): array {
            return [
                'id' => (int) $row->id,
                'from_currency_id' => (int) $row->from_currency_id,
                'to_currency_id' => (int) $row->to_currency_id,
                'from_code' => $row->fromCurrency?->code,
                'to_code' => $row->toCurrency?->code,
                'rate' => (float) $row->rate,
                'effective_from' => $row->effective_from?->toIso8601String(),
            ];
        })->values()->all();
    }

    public function create(CurrencyData $data): Currency
    {
        return DB::transaction(function () use ($data): Currency {
            $currency = Currency::query()->create($data->toModelArray());

            if ($data->isPrimary) {
                TenantSetting::singleton()->update(['primary_currency_id' => $currency->id]);
            } elseif ($data->rate !== null && $data->rate > 0 && $data->fromCurrencyId !== null && $data->fromCurrencyId !== $currency->id) {
                $toId = $data->toCurrencyId ?? $currency->id;
                $this->exchangeRateService->setPairRate(
                    $data->fromCurrencyId,
                    $toId,
                    $data->rate,
                    Auth::user()?->name
                );
            }

            TenantReferenceCache::forget(self::CACHE_LIST);

            return $currency->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Currency $currency, array $patch): Currency
    {
        return DB::transaction(function () use ($currency, $patch): Currency {
            $modelKeys = [
                'name', 'code', 'iso_code', 'symbol', 'smallest_unit', 'round_limit',
                'acceptable_amount_overdue', 'allowed_difference_in_receipt', 'allowed_difference_in_payment', 'active',
            ];
            $scalar = array_intersect_key($patch, array_flip($modelKeys));
            if ($scalar !== []) {
                $currency->update($scalar);
            }

            if (array_key_exists('is_primary', $patch) && $patch['is_primary']) {
                TenantSetting::singleton()->update(['primary_currency_id' => $currency->id]);
            }

            if (array_key_exists('rate', $patch) && $patch['rate'] !== null && is_numeric($patch['rate']) && (float) $patch['rate'] > 0) {
                $fromId = isset($patch['from_currency_id']) ? (int) $patch['from_currency_id'] : null;
                if ($fromId && $fromId !== $currency->id) {
                    $toId = isset($patch['to_currency_id']) ? (int) $patch['to_currency_id'] : $currency->id;
                    $this->exchangeRateService->setPairRate(
                        $fromId,
                        $toId,
                        (float) $patch['rate'],
                        Auth::user()?->name
                    );
                }
            }

            TenantReferenceCache::forget(self::CACHE_LIST);

            return $currency->refresh();
        });
    }

    public function delete(Currency $currency): void
    {
        if ($currency->isPrimary()) {
            abort(422, 'Cannot delete the primary currency. Set another currency as primary first.', ['X-Error-Code' => 'CURRENCY_PRIMARY_DELETE_FORBIDDEN']);
        }

        $currency->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    /**
     * @return array{currency: Currency, pairs: array<int, array{to_currency: Currency, current_rate: float, history: \Illuminate\Support\Collection}>}
     */
    public function pairRateHistory(Currency $currency, ?string $fromDate, ?string $toDate): array
    {
        return $this->exchangeRateService->getPairRateHistory((int) $currency->id, $fromDate, $toDate);
    }
}
