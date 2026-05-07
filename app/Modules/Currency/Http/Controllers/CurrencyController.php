<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Currency\DTOs\CurrencyData;
use App\Modules\Currency\DTOs\CurrencyResponseData;
use App\Modules\Currency\Http\Requests\GetCurrencyRateHistoryRequest;
use App\Modules\Currency\Http\Requests\StoreCurrencyRequest;
use App\Modules\Currency\Http\Requests\UpdateCurrencyRequest;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CurrencyController extends Controller
{
    public function __construct(
        private readonly CurrencyService $currencyService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CurrencyResponseData::collectionToArray($this->currencyService->list()),
            'Currencies fetched successfully.'
        );
    }

    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        $currency = $this->currencyService->create(
            CurrencyData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            CurrencyResponseData::fromModel($currency)->toArray(),
            'Currency created successfully.'
        );
    }

    public function show(Currency $currency): JsonResponse
    {
        return ApiResponse::success(
            CurrencyResponseData::fromModel($currency)->toArray(),
            'Currency fetched successfully.'
        );
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $updated = $this->currencyService->update($currency, $request->validated());

        return ApiResponse::success(
            CurrencyResponseData::fromModel($updated)->toArray(),
            'Currency updated successfully.'
        );
    }

    public function destroy(Currency $currency): JsonResponse
    {
        $this->currencyService->delete($currency);

        return ApiResponse::success(null, 'Currency deleted successfully.');
    }

    public function pairRates(): JsonResponse
    {
        return ApiResponse::success(
            $this->currencyService->listPairRates(),
            'Currency pair rates retrieved.'
        );
    }

    public function rateHistory(GetCurrencyRateHistoryRequest $request, Currency $currency): JsonResponse
    {
        $q = $request->validated();
        $data = $this->currencyService->pairRateHistory(
            $currency,
            $q['from'] ?? null,
            $q['to'] ?? null
        );

        return ApiResponse::success(
            [
                'currency' => $data['currency']->only(['id', 'code', 'name', 'symbol']),
                'pairs' => array_map(static function (array $p): array {
                    /** @var Currency $to */
                    $to = $p['to_currency'];

                    return [
                        'to_currency' => $to->only(['id', 'code', 'name', 'symbol']),
                        'current_rate' => $p['current_rate'],
                        'history' => $p['history']->all(),
                    ];
                }, $data['pairs']),
            ],
            'Rate history retrieved.'
        );
    }

    public function fetchExchangeRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currencies' => ['required', 'array'],
            'currencies.*' => ['required', 'string', 'max:3'],
            'primary_currency_code' => ['required', 'string', 'max:3'],
        ]);

        $currencyCodes = array_map(static fn (string $v): string => strtoupper($v), $validated['currencies']);
        $primaryCode = strtoupper($validated['primary_currency_code']);
        $apiKey = config('services.exchange_rate.key');

        if (! $apiKey) {
            return ApiResponse::error(
                'Exchange rate API is not configured. Please enter exchange rates manually.',
                422,
                null,
                [],
                null,
                null,
                'API_KEY_NOT_CONFIGURED'
            );
        }

        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$primaryCode}";
        $httpClient = Http::timeout(10);
        if (app()->environment(['local', 'development', 'testing'])) {
            $httpClient = $httpClient->withoutVerifying();
        }
        $response = $httpClient->get($url);

        if (! $response->ok()) {
            return ApiResponse::error('Failed to fetch exchange rates from API.', 500);
        }

        $conversionRates = $response->json()['conversion_rates'] ?? [];
        $rates = [];
        foreach ($currencyCodes as $code) {
            if ($code === $primaryCode) {
                $rates[$code] = 1.0;
            } else {
                $rates[$code] = isset($conversionRates[$code]) ? (float) $conversionRates[$code] : null;
            }
        }

        return ApiResponse::success(
            [
                'rates' => $rates,
                'base_currency' => $primaryCode,
                'fetched_at' => now()->toIso8601String(),
            ],
            'Exchange rates fetched successfully.'
        );
    }
}
