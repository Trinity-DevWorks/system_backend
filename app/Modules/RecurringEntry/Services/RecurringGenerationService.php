<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Services;

use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Modules\RecurringEntry\Enums\RecurringDocumentType;
use App\Modules\RecurringEntry\Enums\RecurringRunStatus;
use App\Modules\RecurringEntry\Enums\RecurringTemplateStatus;
use App\Modules\RecurringEntry\Models\RecurringRun;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\RecurringEntry\Support\RecurringDocumentBuilder;
use App\Modules\Treasury\Services\ExpenseVoucherService;
use App\Support\TenantClock;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RecurringGenerationService
{
    public function __construct(
        private readonly RecurringTemplateService $templates,
        private readonly RecurringDocumentBuilder $builder,
        private readonly JournalEntryService $journalEntries,
        private readonly ExpenseVoucherService $expenseVouchers,
        private readonly DomainNotificationPublisher $notifications,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * @return array{generated: int, failed: int, skipped: int, drafts: int}
     */
    public function runDue(?Carbon $asOf = null, ?string $triggeredBy = null): array
    {
        $asOf = ($asOf ?? TenantClock::today())->copy()->startOfDay();
        $ids = RecurringTemplate::query()
            ->where('status', RecurringTemplateStatus::Active)
            ->whereNotNull('next_run_date')
            ->whereDate('next_run_date', '<=', $asOf->toDateString())
            ->orderBy('next_run_date')
            ->pluck('id');

        $totals = ['generated' => 0, 'failed' => 0, 'skipped' => 0, 'drafts' => 0];
        foreach ($ids as $id) {
            $result = $this->runTemplate((string) $id, $asOf, $triggeredBy);
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }
        if ($totals['drafts'] > 0) {
            $this->notifications->recurringDraftsReady($totals['drafts']);
        }

        return $totals;
    }

    /**
     * @return array{generated: int, failed: int, skipped: int, drafts: int}
     */
    public function runTemplate(string $templateId, Carbon $asOf, ?string $triggeredBy): array
    {
        $counts = ['generated' => 0, 'failed' => 0, 'skipped' => 0, 'drafts' => 0];
        $limit = max(1, (int) config('accounting.recurring.max_catch_up', 24));
        $asOf = $asOf->copy()->startOfDay();

        for ($i = 0; $i < $limit; $i++) {
            $failedOn = null;
            $error = null;

            try {
                $outcome = DB::transaction(function () use ($templateId, $asOf, $triggeredBy, &$failedOn, &$error): string {
                    $template = RecurringTemplate::query()->whereKey($templateId)->lock('for update skip locked')->first();
                    if ($template === null || $template->status !== RecurringTemplateStatus::Active || $template->next_run_date === null) {
                        return 'stop';
                    }
                    if ($template->next_run_date->toDateString() > $asOf->toDateString()) {
                        return 'stop';
                    }

                    $date = $template->next_run_date->copy()->startOfDay();
                    $existing = RecurringRun::query()
                        ->where('recurring_template_id', $template->id)
                        ->whereDate('scheduled_date', $date->toDateString())
                        ->lockForUpdate()
                        ->first();
                    if ($existing !== null && in_array($existing->status, [RecurringRunStatus::Generated, RecurringRunStatus::Posted, RecurringRunStatus::Skipped], true)) {
                        $this->advanceIndex($template, $date);
                        $template->save();

                        return 'skipped';
                    }

                    try {
                        $actor = $this->builder->actorId($template->created_by);
                        if ($template->document_type === RecurringDocumentType::JournalEntry) {
                            $document = $this->builder->journalEntry($template, $date, $actor);
                            if ($template->auto_post) {
                                $document = $this->journalEntries->post($document, $actor);
                            }
                            $number = $document->entry_number;
                        } else {
                            $document = $this->builder->expenseVoucher($template, $date, $actor);
                            if ($template->auto_post) {
                                $document = $this->expenseVouchers->post($document, $actor);
                            }
                            $number = $document->voucher_number;
                        }
                        $status = $template->auto_post ? RecurringRunStatus::Posted : RecurringRunStatus::Generated;
                        $payload = [
                            'status' => $status,
                            'document_type' => $template->document_type,
                            'document_id' => (string) $document->id,
                            'document_number' => $number,
                            'error_code' => null,
                            'error_message' => null,
                            'triggered_by' => $triggeredBy,
                        ];
                        if ($existing !== null) {
                            $existing->update($payload);
                        } else {
                            RecurringRun::query()->create([
                                ...$payload,
                                'recurring_template_id' => $template->id,
                                'scheduled_date' => $date->toDateString(),
                                'attempts' => 1,
                            ]);
                        }
                        $template->occurrences_count = (int) $template->occurrences_count + 1;
                        $template->last_error_code = null;
                        $this->advanceIndex($template, $date);
                        $template->save();

                        return $status === RecurringRunStatus::Posted ? 'posted' : 'draft';
                    } catch (Throwable $exception) {
                        $failedOn = $date->toDateString();
                        $error = $exception;

                        throw $exception;
                    }
                });
            } catch (Throwable $exception) {
                if ($failedOn === null) {
                    throw $exception;
                }
                $this->recordFailure($templateId, $failedOn, $error ?? $exception, $triggeredBy);
                $counts['failed']++;

                return $counts;
            }

            if ($outcome === 'stop') {
                break;
            }
            if ($outcome === 'skipped') {
                $counts['skipped']++;

                continue;
            }
            $counts['generated']++;
            if ($outcome === 'draft') {
                $counts['drafts']++;
            }
        }

        return $counts;
    }

    /**
     * @return array{generated: int, failed: int, skipped: int, drafts: int}
     */
    public function retry(RecurringRun $run, ?string $userId): array
    {
        $run->load('template');
        if ($run->status !== RecurringRunStatus::Failed) {
            abort(422, 'Only a failed run can be retried.', ['X-Error-Code' => 'RECURRING_RUN_NOT_FAILED']);
        }
        $template = $run->template;
        $due = $template?->next_run_date?->toDateString();
        if ($template === null || $due === null || $due !== $run->scheduled_date->toDateString()) {
            abort(422, 'Only the current due occurrence can be retried.', ['X-Error-Code' => 'RECURRING_RUN_NOT_FAILED']);
        }

        return $this->runTemplate((string) $template->id, $run->scheduled_date->copy()->startOfDay(), $userId);
    }

    /**
     * @param  array{template_id?: string, status?: string, from?: string, to?: string, search?: string}  $filters
     */
    public function paginateRuns(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = RecurringRun::query()
            ->with(['template:id,template_number,name', 'triggeredByUser:id,name'])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');
        if (! empty($filters['template_id'])) {
            $query->where('recurring_template_id', $filters['template_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('scheduled_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('scheduled_date', '<=', $filters['to']);
        }
        // Only runs of templates in the branches the user may access.
        $scope = $this->branchScope->apply(null)['filters'];
        if (array_key_exists('branch_id', $scope)) {
            $query->whereHas('template', fn ($q) => $q->where('branch_id', (int) $scope['branch_id']));
        } elseif (array_key_exists('branch_ids', $scope)) {
            $ids = (array) $scope['branch_ids'];
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereHas('template', fn ($q) => $q->whereIn('branch_id', $ids));
        }

        return $query->paginate($perPage);
    }

    private function advanceIndex(RecurringTemplate $template, Carbon $date): void
    {
        $template->last_run_date = $date->copy();
        $template->next_occurrence_index = (int) $template->next_occurrence_index + 1;
        $this->templates->recompute($template);
    }

    private function recordFailure(string $templateId, string $date, Throwable $error, ?string $triggeredBy): void
    {
        $code = $this->errorCode($error);
        $message = mb_substr($error->getMessage() !== '' ? $error->getMessage() : 'The occurrence could not be generated.', 0, 500);
        $run = null;
        $template = null;
        DB::transaction(function () use ($templateId, $date, $code, $message, $triggeredBy, &$run, &$template): void {
            $template = RecurringTemplate::query()->whereKey($templateId)->lockForUpdate()->first();
            if ($template === null) {
                return;
            }
            $run = RecurringRun::query()
                ->where('recurring_template_id', $templateId)
                ->whereDate('scheduled_date', $date)
                ->lockForUpdate()
                ->first();
            if ($run === null) {
                $run = RecurringRun::query()->create([
                    'recurring_template_id' => $templateId,
                    'scheduled_date' => $date,
                    'status' => RecurringRunStatus::Failed,
                    'document_type' => $template->document_type,
                    'error_code' => $code,
                    'error_message' => $message,
                    'triggered_by' => $triggeredBy,
                    'attempts' => 1,
                ]);
            } else {
                $run->update([
                    'status' => RecurringRunStatus::Failed,
                    'document_type' => $template->document_type,
                    'document_id' => null,
                    'document_number' => null,
                    'error_code' => $code,
                    'error_message' => $message,
                    'triggered_by' => $triggeredBy,
                    'attempts' => (int) $run->attempts + 1,
                ]);
            }
            $template->update(['last_error_code' => $code]);
        });
        if ($template !== null && $run !== null) {
            try {
                $this->notifications->recurringRunFailed($template, $run);
            } catch (Throwable) {
                // A notification failure must not hide the recorded run.
            }
        }
    }

    private function errorCode(Throwable $error): string
    {
        if ($error instanceof HttpExceptionInterface) {
            $code = $error->getHeaders()['X-Error-Code'] ?? null;
            if (is_string($code) && $code !== '') {
                return mb_substr($code, 0, 64);
            }
        }

        return 'RECURRING_RUN_FAILED';
    }
}
