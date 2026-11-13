<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\RecurringEntry\DTOs\RecurringEntryResponseData;
use App\Modules\RecurringEntry\Models\RecurringRun;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\RecurringEntry\Services\RecurringGenerationService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringRunController extends Controller
{
    public function __construct(private readonly RecurringGenerationService $generation) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->generation->paginateRuns([
                'template_id' => (string) $request->query('template_id', ''),
                'status' => (string) $request->query('status', ''),
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
            ], ListPagination::perPage($request)),
            fn (RecurringRun $run): array => RecurringEntryResponseData::run($run),
            'Recurring runs fetched successfully.'
        );
    }

    public function retry(Request $request, RecurringRun $recurring_run): JsonResponse
    {
        $result = $this->generation->retry($recurring_run, $this->userId($request));

        return ApiResponse::success([
            'generated' => $result['generated'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
            'error_code' => $result['failed'] > 0 ? RecurringTemplate::query()->whereKey($recurring_run->recurring_template_id)->value('last_error_code') : null,
        ], 'Recurring run retried successfully.');
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
