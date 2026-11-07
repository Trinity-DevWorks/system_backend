<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Http\Requests\CurrencyRevaluationRequest;
use App\Modules\Accounting\Services\CurrencyRevaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyRevaluationController extends Controller
{
    public function __construct(
        private readonly CurrencyRevaluationService $revaluations,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->revaluations->list(), 'Currency revaluations fetched successfully.');
    }

    public function preview(CurrencyRevaluationRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->revaluations->preview((string) $request->validated('date'), $request->rates()),
            'Currency revaluation preview fetched successfully.',
        );
    }

    public function store(CurrencyRevaluationRequest $request): JsonResponse
    {
        $entry = $this->revaluations->post((string) $request->validated('date'), $request->rates(), $this->userId($request));
        $row = collect($this->revaluations->list())->firstWhere('entry_id', (string) $entry->id);

        return ApiResponse::success($row, 'Currency revaluation posted successfully.');
    }

    public function destroy(string $date): JsonResponse
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            abort(404, 'Currency revaluation not found.');
        }
        $this->revaluations->undo($date);

        return ApiResponse::success(null, 'Currency revaluation undone successfully.');
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
