<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\RecurringEntry\DTOs\RecurringEntryResponseData;
use App\Modules\RecurringEntry\Http\Requests\PreviewRecurringTemplateRequest;
use App\Modules\RecurringEntry\Http\Requests\SaveRecurringTemplateRequest;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\RecurringEntry\Services\RecurringGenerationService;
use App\Modules\RecurringEntry\Services\RecurringTemplateService;
use App\Support\ListPagination;
use App\Support\TenantClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RecurringTemplateController extends Controller
{
    public function __construct(
        private readonly RecurringTemplateService $templates,
        private readonly RecurringGenerationService $generation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->templates->paginate([
                'status' => (string) $request->query('status', ''),
                'document_type' => (string) $request->query('document_type', ''),
                'search' => ListPagination::search($request),
            ], ListPagination::perPage($request)),
            fn (RecurringTemplate $template): array => RecurringEntryResponseData::templateRow($template),
            'Recurring templates fetched successfully.'
        );
    }

    public function store(SaveRecurringTemplateRequest $request): JsonResponse
    {
        return ApiResponse::created(
            RecurringEntryResponseData::template($this->templates->create($request->validated(), $this->userId($request))),
            'Recurring template created successfully.'
        );
    }

    public function show(RecurringTemplate $recurring_template): JsonResponse
    {
        return ApiResponse::success(
            RecurringEntryResponseData::template($this->templates->find((string) $recurring_template->id)),
            'Recurring template fetched successfully.'
        );
    }

    public function update(SaveRecurringTemplateRequest $request, RecurringTemplate $recurring_template): JsonResponse
    {
        return ApiResponse::success(
            RecurringEntryResponseData::template($this->templates->update($recurring_template, $request->validated(), $this->userId($request))),
            'Recurring template updated successfully.'
        );
    }

    public function destroy(RecurringTemplate $recurring_template): JsonResponse
    {
        $this->templates->delete($recurring_template);

        return ApiResponse::success(null, 'Recurring template deleted successfully.');
    }

    public function preview(Request $request, RecurringTemplate $recurring_template): JsonResponse
    {
        $template = $this->templates->find((string) $recurring_template->id);

        return ApiResponse::success(
            $this->templates->preview($template, $this->count($request)),
            'Recurring template preview fetched successfully.'
        );
    }

    public function previewDraft(PreviewRecurringTemplateRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->templates->preview($request->validated(), $this->count($request)),
            'Recurring template preview fetched successfully.'
        );
    }

    public function run(Request $request, RecurringTemplate $recurring_template): JsonResponse
    {
        $asOf = $this->asOf($request);
        $result = $this->generation->runTemplate((string) $recurring_template->id, $asOf, $this->userId($request));

        // On failure, send the error code so the screen can say why.
        $errorCode = $result['failed'] > 0 ? $recurring_template->refresh()->last_error_code : null;

        return ApiResponse::success([...$this->counts($result), 'error_code' => $errorCode], 'Recurring template run completed.');
    }

    public function pause(RecurringTemplate $recurring_template): JsonResponse
    {
        return ApiResponse::success(
            RecurringEntryResponseData::template($this->templates->pause($recurring_template)),
            'Recurring template paused successfully.'
        );
    }

    public function resume(Request $request, RecurringTemplate $recurring_template): JsonResponse
    {
        return ApiResponse::success(
            RecurringEntryResponseData::template($this->templates->resume($recurring_template, $request->boolean('catch_up'))),
            'Recurring template resumed successfully.'
        );
    }

    public function skip(Request $request, RecurringTemplate $recurring_template): JsonResponse
    {
        return ApiResponse::success(
            RecurringEntryResponseData::template($this->templates->skip($recurring_template, $this->userId($request))),
            'Occurrence skipped successfully.'
        );
    }

    public function cancel(RecurringTemplate $recurring_template): JsonResponse
    {
        return ApiResponse::success(
            RecurringEntryResponseData::template($this->templates->cancel($recurring_template)),
            'Recurring template cancelled successfully.'
        );
    }

    private function count(Request $request): int
    {
        return (int) $request->input('count', $request->query('count', 12));
    }

    private function asOf(Request $request): Carbon
    {
        $raw = $request->input('as_of');
        $today = TenantClock::today();
        if ($raw === null || $raw === '') {
            return $today;
        }
        $date = Carbon::parse((string) $raw)->startOfDay();
        if ($date->gt($today)) {
            abort(422, 'The run date cannot be in the future.', ['X-Error-Code' => 'RECURRING_TEMPLATE_INVALID_SCHEDULE']);
        }

        return $date;
    }

    /**
     * @param  array{generated: int, failed: int, skipped: int, drafts?: int}  $result
     * @return array{generated: int, failed: int, skipped: int}
     */
    private function counts(array $result): array
    {
        return [
            'generated' => $result['generated'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
        ];
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
