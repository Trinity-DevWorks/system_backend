<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
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

class CurrencyController extends Controller
{
    use ResolvesListSection;

    private const INDEX_SECTIONS = ['names'];

    public function __construct(
        private readonly CurrencyService $currencyService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->resolveListSection($request, self::INDEX_SECTIONS) === 'names') {
            $rows = $this->currencyService->names()->map(fn (Currency $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'created_at' => (string) $c->created_at,
                'updated_at' => (string) $c->updated_at,
            ])->values()->all();

            return ApiResponse::success($rows, 'Currency names fetched successfully.');
        }

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
}
