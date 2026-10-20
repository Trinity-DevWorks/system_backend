<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Http\Requests\UpdateAccountingPeriodRequest;
use App\Modules\Accounting\Services\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AccountingPeriodController extends Controller
{
    public function __construct(
        private readonly AccountingPeriodService $periods,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->periods->show(), 'Accounting period fetched successfully.');
    }

    public function update(UpdateAccountingPeriodRequest $request): JsonResponse
    {
        $raw = $request->validated('books_closed_through');
        $date = $raw !== null ? Carbon::parse((string) $raw)->toDateString() : null;
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        return ApiResponse::success(
            $this->periods->updateClosedThrough($date, $user),
            'Accounting period updated successfully.',
        );
    }
}
