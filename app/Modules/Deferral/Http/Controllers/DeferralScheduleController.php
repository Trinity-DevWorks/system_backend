<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Deferral\DTOs\DeferralResponseData;
use App\Modules\Deferral\Enums\DeferralKind;
use App\Modules\Deferral\Enums\DeferralMethod;
use App\Modules\Deferral\Http\Requests\CancelDeferralRequest;
use App\Modules\Deferral\Http\Requests\PreviewDeferralScheduleRequest;
use App\Modules\Deferral\Http\Requests\RecognizeRemainingRequest;
use App\Modules\Deferral\Http\Requests\RescheduleDeferralRequest;
use App\Modules\Deferral\Http\Requests\SaveDeferralScheduleRequest;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Models\DeferralScheduleLine;
use App\Modules\Deferral\Services\DeferralPostingService;
use App\Modules\Deferral\Services\DeferralScheduleService;
use App\Support\ListPagination;
use App\Support\TenantClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DeferralScheduleController extends Controller
{
    public function __construct(
        private readonly DeferralScheduleService $schedules,
        private readonly DeferralPostingService $posting,
    ) {}

    /**
     * Default deferral account per kind (from the account mappings), so forms can pre-fill it.
     */
    public function defaults(AccountMappingService $mappings): JsonResponse
    {
        $out = [];
        foreach (DeferralKind::cases() as $kind) {
            try {
                $account = $mappings->accountFor($kind->deferralRole());
                $out[$kind->value] = ['id' => (int) $account->id, 'code' => $account->code, 'name' => $account->name];
            } catch (HttpException) {
                $out[$kind->value] = null;
            }
        }

        return ApiResponse::success($out, 'Deferral defaults fetched successfully.');
    }

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->schedules->paginate([
                'status' => (string) $request->query('status', ''),
                'kind' => (string) $request->query('kind', ''),
                'source_type' => (string) $request->query('source_type', ''),
                'search' => ListPagination::search($request),
            ], ListPagination::perPage($request)),
            fn (DeferralSchedule $schedule): array => DeferralResponseData::scheduleRow($schedule),
            'Deferral schedules fetched successfully.'
        );
    }

    public function store(SaveDeferralScheduleRequest $request): JsonResponse
    {
        return ApiResponse::created(
            DeferralResponseData::schedule($this->schedules->create($request->validated(), $this->userId($request))),
            'Deferral schedule created successfully.'
        );
    }

    public function show(DeferralSchedule $deferral_schedule): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->schedules->find((string) $deferral_schedule->id)),
            'Deferral schedule fetched successfully.'
        );
    }

    public function update(SaveDeferralScheduleRequest $request, DeferralSchedule $deferral_schedule): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->schedules->update($deferral_schedule, $request->validated())),
            'Deferral schedule updated successfully.'
        );
    }

    public function destroy(DeferralSchedule $deferral_schedule): JsonResponse
    {
        $this->schedules->delete($deferral_schedule);

        return ApiResponse::success(null, 'Deferral schedule deleted successfully.');
    }

    public function preview(PreviewDeferralScheduleRequest $request): JsonResponse
    {
        return ApiResponse::success($this->schedules->preview($request->validated()), 'Deferral preview fetched successfully.');
    }

    public function activate(Request $request, DeferralSchedule $deferral_schedule): JsonResponse
    {
        $result = $this->schedules->activate($deferral_schedule, $this->userId($request));

        return ApiResponse::success(
            DeferralResponseData::schedule($result['schedule'], $result['warnings']),
            'Deferral schedule activated successfully.'
        );
    }

    public function postDue(Request $request, DeferralSchedule $deferral_schedule): JsonResponse
    {
        $posted = $this->posting->postDue($deferral_schedule, $this->asOf($request), $this->userId($request));

        return ApiResponse::success([
            'posted' => $posted,
            'schedule' => DeferralResponseData::schedule($this->schedules->find((string) $deferral_schedule->id)),
        ], 'Due recognition lines posted successfully.');
    }

    public function postLine(Request $request, DeferralSchedule $deferral_schedule, DeferralScheduleLine $line): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->posting->postLine($deferral_schedule, $line, $this->userId($request))),
            'Recognition line posted successfully.'
        );
    }

    public function reverseLine(Request $request, DeferralSchedule $deferral_schedule, DeferralScheduleLine $line): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->posting->reverseLine($deferral_schedule, $line, $this->userId($request))),
            'Recognition line reversed successfully.'
        );
    }

    public function pause(DeferralSchedule $deferral_schedule): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->schedules->pause($deferral_schedule)),
            'Deferral schedule paused successfully.'
        );
    }

    public function resume(DeferralSchedule $deferral_schedule): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->schedules->resume($deferral_schedule)),
            'Deferral schedule resumed successfully.'
        );
    }

    public function reschedule(RescheduleDeferralRequest $request, DeferralSchedule $deferral_schedule): JsonResponse
    {
        $method = $request->validated('method');

        return ApiResponse::success(
            DeferralResponseData::schedule($this->schedules->reschedule(
                $deferral_schedule,
                Carbon::parse((string) $request->validated('end_date')),
                is_string($method) && $method !== '' ? DeferralMethod::from($method) : null,
            )),
            'Deferral schedule rescheduled successfully.'
        );
    }

    public function recognizeRemaining(RecognizeRemainingRequest $request, DeferralSchedule $deferral_schedule): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->posting->recognizeRemaining(
                $deferral_schedule,
                Carbon::parse((string) $request->validated('date')),
                $this->userId($request),
            )),
            'Remaining balance recognised successfully.'
        );
    }

    public function cancel(CancelDeferralRequest $request, DeferralSchedule $deferral_schedule): JsonResponse
    {
        return ApiResponse::success(
            DeferralResponseData::schedule($this->schedules->cancel(
                $deferral_schedule,
                (string) $request->validated('reason'),
                $this->userId($request),
            )),
            'Deferral schedule cancelled successfully.'
        );
    }

    private function asOf(Request $request): Carbon
    {
        $raw = $request->input('as_of');
        if ($raw === null || $raw === '') {
            return TenantClock::today();
        }

        return Carbon::parse((string) $raw)->startOfDay();
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
