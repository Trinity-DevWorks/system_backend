<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Http\Requests\CloseFiscalYearRequest;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\FiscalYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FiscalYearController extends Controller
{
    public function __construct(
        private readonly FiscalYearService $years,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->years->list(), 'Fiscal years fetched successfully.');
    }

    public function close(CloseFiscalYearRequest $request): JsonResponse
    {
        $year = $this->years->close(
            Carbon::parse((string) $request->validated('ends_on'))->toDateString(),
            $this->userId($request),
        );

        return ApiResponse::success($this->years->present($year), 'Fiscal year closed successfully.');
    }

    public function reverse(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        $year = $this->years->reverse($fiscalYear, $this->userId($request));

        return ApiResponse::success($this->years->present($year), 'Fiscal year close reversed successfully.');
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
