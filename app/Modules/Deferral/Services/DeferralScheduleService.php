<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Services;

use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Deferral\Enums\DeferralKind;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Enums\DeferralMethod;
use App\Modules\Deferral\Enums\DeferralPostingDay;
use App\Modules\Deferral\Enums\DeferralSourceType;
use App\Modules\Deferral\Enums\DeferralStatus;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Models\DeferralScheduleLine;
use App\Modules\Deferral\Support\DeferralAccountRules;
use App\Modules\Deferral\Support\DeferralScheduleCalculator;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeferralScheduleService
{
    public function __construct(
        private readonly DeferralScheduleCalculator $calculator,
        private readonly DeferralAccountRules $accountRules,
        private readonly AccountMappingService $accountMappings,
        private readonly AccountBalanceService $balances,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly DocumentNumberService $numbers,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * @param  array{status?: string, kind?: string, source_type?: string, search?: string}  $filters
     * @return LengthAwarePaginator<int, DeferralSchedule>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = DeferralSchedule::query()
            ->with([
                'branch:id,name',
                'currency:id,code,symbol',
                'deferralAccount:id,code,name',
                'recognitionAccount:id,code,name',
            ])
            ->withCount([
                'lines',
                'lines as posted_lines_count' => fn ($q) => $q->where('status', DeferralLineStatus::Posted->value),
            ])
            ->withMin(['lines as pending_posting_date' => fn ($q) => $q->where('status', DeferralLineStatus::Pending->value)], 'posting_date')
            ->orderByDesc('created_at');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }
        $this->scopeToBranches($query);
        ListPagination::applySearch($query, $filters['search'] ?? null, ['schedule_number', 'name', 'description']);

        return $query->paginate($perPage);
    }

    public function find(string $id): DeferralSchedule
    {
        return DeferralSchedule::query()
            ->with([
                'branch:id,name',
                'currency:id,code,symbol',
                'deferralAccount:id,code,name',
                'recognitionAccount:id,code,name',
                'supplier:id,name',
                'customer:id,name',
                'createdByUser:id,name',
                'activatedByUser:id,name',
                'lines.journalEntry:id,entry_number',
            ])
            ->withCount([
                'lines',
                'lines as posted_lines_count' => fn ($q) => $q->where('status', DeferralLineStatus::Posted->value),
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: ?string, line_id?: ?int}  $source
     */
    public function create(array $data, ?string $userId, array $source = []): DeferralSchedule
    {
        return DB::transaction(function () use ($data, $userId, $source): DeferralSchedule {
            $schedule = new DeferralSchedule($this->attributes($data, null, $source));
            $schedule->status = DeferralStatus::Draft;
            $schedule->created_by = $userId;
            $schedule->recognized_amount = '0';
            $schedule->recognized_amount_primary = '0';
            $this->guardSourceLine($schedule);
            $schedule->save();
            $this->writeLines($schedule, $this->calculator->build(
                (string) $schedule->total_amount,
                (string) $schedule->total_amount_primary,
                $schedule->start_date->copy(),
                $schedule->end_date->copy(),
                $schedule->method,
                $schedule->posting_day,
            ));

            return $this->find((string) $schedule->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type: string, id: string, line_id: int, supplier_id?: ?string}  $source
     */
    public function createFromSource(array $data, ?string $userId, array $source): DeferralSchedule
    {
        return $this->create($data, $userId, $source);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DeferralSchedule $schedule, array $data): DeferralSchedule
    {
        return DB::transaction(function () use ($schedule, $data): DeferralSchedule {
            $locked = $this->lockDraft($schedule);
            $locked->fill($this->attributes($data, $locked, []));
            $locked->save();
            $locked->lines()->delete();
            $this->writeLines($locked, $this->calculator->build(
                (string) $locked->total_amount,
                (string) $locked->total_amount_primary,
                $locked->start_date->copy(),
                $locked->end_date->copy(),
                $locked->method,
                $locked->posting_day,
            ));

            return $this->find((string) $locked->id);
        });
    }

    public function delete(DeferralSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule): void {
            $this->lockDraft($schedule)->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{lines: list<array<string, mixed>>, total_amount: string, total_amount_primary: string}
     */
    public function preview(array $data): array
    {
        $attributes = $this->attributes($data, null, ['type' => DeferralSourceType::Manual->value]);
        $lines = $this->calculator->build(
            $attributes['total_amount'],
            $attributes['total_amount_primary'],
            Carbon::parse($attributes['start_date']),
            Carbon::parse($attributes['end_date']),
            $attributes['method'],
            $attributes['posting_day'],
        );

        return [
            'lines' => $lines,
            'total_amount' => $attributes['total_amount'],
            'total_amount_primary' => $attributes['total_amount_primary'],
        ];
    }

    /**
     * @return array{schedule: DeferralSchedule, warnings: list<array{code: string, message: string}>}
     */
    public function activate(DeferralSchedule $schedule, ?string $userId): array
    {
        return DB::transaction(function () use ($schedule, $userId): array {
            $locked = $this->lockDraft($schedule);
            $warnings = $this->balanceWarnings($locked);
            if ($warnings !== [] && (bool) config('accounting.deferrals.strict_balance_check')) {
                abort(422, $warnings[0]['message'], ['X-Error-Code' => 'DEFERRAL_INSUFFICIENT_BALANCE']);
            }
            $locked->schedule_number = $this->numbers->next(DocumentType::DeferralSchedule, (int) $locked->branch_id, $locked->start_date);
            $locked->status = DeferralStatus::Active;
            $locked->activated_by = $userId;
            $locked->activated_at = now();
            $locked->save();

            return ['schedule' => $this->find((string) $locked->id), 'warnings' => $warnings];
        });
    }

    public function pause(DeferralSchedule $schedule): DeferralSchedule
    {
        return $this->setStatus($schedule, DeferralStatus::Active, DeferralStatus::Paused, 'Only an active schedule can be paused.');
    }

    public function resume(DeferralSchedule $schedule): DeferralSchedule
    {
        return $this->setStatus($schedule, DeferralStatus::Paused, DeferralStatus::Active, 'Only a paused schedule can be resumed.');
    }

    public function cancel(DeferralSchedule $schedule, string $reason, ?string $userId): DeferralSchedule
    {
        return DB::transaction(function () use ($schedule, $reason, $userId): DeferralSchedule {
            $locked = DeferralSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                abort(422, 'This schedule is already finished.', ['X-Error-Code' => 'DEFERRAL_NOT_ACTIVE']);
            }
            if ($locked->lines()->where('status', DeferralLineStatus::Posted->value)->exists()) {
                abort(422, 'Reverse the posted recognition lines before cancelling this schedule.', [
                    'X-Error-Code' => 'DEFERRAL_HAS_POSTED_LINES',
                ]);
            }
            $locked->lines()->where('status', DeferralLineStatus::Pending->value)->update([
                'status' => DeferralLineStatus::Cancelled->value,
            ]);
            $locked->update([
                'status' => DeferralStatus::Cancelled,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 500),
            ]);

            return $this->find((string) $locked->id);
        });
    }

    public function reschedule(DeferralSchedule $schedule, Carbon $end, ?DeferralMethod $method): DeferralSchedule
    {
        return DB::transaction(function () use ($schedule, $end, $method): DeferralSchedule {
            $locked = DeferralSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [DeferralStatus::Active, DeferralStatus::Paused], true)) {
                abort(422, 'Only an active or paused schedule can be rescheduled.', ['X-Error-Code' => 'DEFERRAL_NOT_ACTIVE']);
            }
            $locked->load('lines');
            if (! $locked->lines->contains(fn (DeferralScheduleLine $line): bool => $line->status === DeferralLineStatus::Pending)) {
                abort(422, 'There is no pending amount left to reschedule.', ['X-Error-Code' => 'DEFERRAL_LINE_NOT_PENDING']);
            }
            $rebuilt = $this->calculator->rebuildRemaining($locked, $end, $method);
            $locked->lines()->where('status', DeferralLineStatus::Pending->value)->delete();
            $this->writeLines($locked, $rebuilt);
            $locked->end_date = $end->copy();
            if ($method !== null) {
                $locked->method = $method;
            }
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    public function refreshTotals(DeferralSchedule $schedule): void
    {
        $posted = DeferralScheduleLine::query()
            ->where('deferral_schedule_id', $schedule->id)
            ->where('status', DeferralLineStatus::Posted->value)
            ->get(['amount', 'amount_primary']);
        $amount = '0';
        $primary = '0';
        foreach ($posted as $line) {
            $amount = JournalMath::add($amount, $line->amount);
            $primary = JournalMath::add($primary, $line->amount_primary);
        }
        $pending = DeferralScheduleLine::query()
            ->where('deferral_schedule_id', $schedule->id)
            ->where('status', DeferralLineStatus::Pending->value)
            ->exists();
        $schedule->recognized_amount = JournalMath::store($amount);
        $schedule->recognized_amount_primary = JournalMath::store($primary);
        if (! $pending && $schedule->status !== DeferralStatus::Cancelled) {
            $schedule->status = DeferralStatus::Completed;
        } elseif ($pending && $schedule->status === DeferralStatus::Completed) {
            $schedule->status = DeferralStatus::Active;
        }
        $schedule->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{type?: string, id?: ?string, line_id?: ?int, supplier_id?: ?string, customer_id?: ?string}  $source
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?DeferralSchedule $existing, array $source): array
    {
        $kind = DeferralKind::from((string) ($data['kind'] ?? $existing?->kind->value));
        $deferralId = (int) ($data['deferral_account_id'] ?? $existing?->deferral_account_id ?? 0);
        if ($deferralId <= 0) {
            $deferralId = (int) $this->accountMappings->accountFor($kind->deferralRole())->id;
        }
        $recognitionId = (int) ($data['recognition_account_id'] ?? $existing?->recognition_account_id ?? 0);
        $deferral = Account::query()->find($deferralId);
        $recognition = Account::query()->find($recognitionId);
        if (! $deferral instanceof Account || ! $recognition instanceof Account) {
            abort(422, 'Choose the deferral account and the recognition account.', ['X-Error-Code' => 'DEFERRAL_ACCOUNT_INVALID']);
        }

        $currencyId = (int) ($data['currency_id'] ?? $existing?->currency_id ?? 0);
        $this->accountRules->assertAccounts($kind, $deferral, $recognition, $currencyId > 0 ? $currencyId : null);
        $rate = ExchangeRateSnapshot::resolve(
            $this->exchangeRateService,
            $currencyId,
            $data['exchange_rate'] ?? $existing?->exchange_rate,
            'ACCOUNTING',
        );
        $total = JournalMath::store(JournalMath::round($data['total_amount'] ?? $existing?->total_amount ?? 0));
        if (! JournalMath::isPositive($total)) {
            abort(422, 'The amount to defer must be greater than zero.', ['X-Error-Code' => 'DEFERRAL_INVALID_PERIOD']);
        }
        $primary = JournalMath::store(JournalMath::round(
            ExchangeRateSnapshot::toPrimary($total, $rate, JournalMath::WORK_SCALE)
        ));

        $sourceType = DeferralSourceType::from((string) ($source['type'] ?? $existing?->source_type->value ?? DeferralSourceType::Manual->value));

        return [
            'kind' => $kind,
            'name' => mb_substr(trim((string) ($data['name'] ?? $existing?->name ?? '')), 0, 255),
            'description' => $this->nullableText($data, 'description', $existing?->description, 500),
            // Left empty on a new manual schedule: the active branch is filled in (HasDocumentBranch).
            'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : $existing?->branch_id,
            'deferral_account_id' => (int) $deferral->id,
            'recognition_account_id' => (int) $recognition->id,
            'currency_id' => $currencyId,
            'exchange_rate' => $rate,
            'total_amount' => $total,
            'total_amount_primary' => $primary,
            'start_date' => substr((string) ($data['start_date'] ?? $existing?->start_date?->toDateString()), 0, 10),
            'end_date' => substr((string) ($data['end_date'] ?? $existing?->end_date?->toDateString()), 0, 10),
            'method' => DeferralMethod::from((string) ($data['method'] ?? $existing?->method->value)),
            'posting_day' => DeferralPostingDay::from((string) ($data['posting_day'] ?? $existing?->posting_day->value ?? DeferralPostingDay::PeriodEnd->value)),
            'auto_post' => (bool) ($data['auto_post'] ?? $existing?->auto_post ?? true),
            'source_type' => $sourceType,
            'source_id' => $source['id'] ?? $existing?->source_id,
            'source_line_id' => $source['line_id'] ?? $existing?->source_line_id,
            'supplier_id' => $source['supplier_id'] ?? ($data['supplier_id'] ?? $existing?->supplier_id),
            'customer_id' => $source['customer_id'] ?? ($data['customer_id'] ?? $existing?->customer_id),
        ];
    }

    /**
     * @param  list<array{line_no: int, period_start: string, period_end: string, posting_date: string, amount: string, amount_primary: string}>  $rows
     */
    private function writeLines(DeferralSchedule $schedule, array $rows): void
    {
        foreach ($rows as $row) {
            DeferralScheduleLine::query()->create([
                'deferral_schedule_id' => $schedule->id,
                'line_no' => $row['line_no'],
                'period_start' => $row['period_start'],
                'period_end' => $row['period_end'],
                'posting_date' => $row['posting_date'],
                'amount' => $row['amount'],
                'amount_primary' => $row['amount_primary'],
                'status' => DeferralLineStatus::Pending,
            ]);
        }
    }

    private function lockDraft(DeferralSchedule $schedule): DeferralSchedule
    {
        $locked = DeferralSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== DeferralStatus::Draft) {
            abort(422, 'Only a draft schedule can be changed.', ['X-Error-Code' => 'DEFERRAL_NOT_DRAFT']);
        }

        return $locked;
    }

    private function setStatus(DeferralSchedule $schedule, DeferralStatus $from, DeferralStatus $to, string $message): DeferralSchedule
    {
        return DB::transaction(function () use ($schedule, $from, $to, $message): DeferralSchedule {
            $locked = DeferralSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                abort(422, $message, ['X-Error-Code' => 'DEFERRAL_NOT_ACTIVE']);
            }
            $locked->status = $to;
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    private function guardSourceLine(DeferralSchedule $schedule): void
    {
        if ($schedule->source_line_id === null) {
            return;
        }
        $taken = DeferralSchedule::query()
            ->where('source_type', $schedule->source_type->value)
            ->where('source_id', $schedule->source_id)
            ->where('source_line_id', $schedule->source_line_id)
            ->exists();
        if ($taken) {
            abort(422, 'This line already has a deferral schedule.', ['X-Error-Code' => 'DEFERRAL_SOURCE_LINE_TAKEN']);
        }
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function balanceWarnings(DeferralSchedule $schedule): array
    {
        if ($schedule->source_type !== DeferralSourceType::Manual) {
            return [];
        }
        $schedule->loadMissing('deferralAccount');
        $totals = $this->balances->totals(null, $schedule->start_date->toDateString());
        $sum = $totals[(int) $schedule->deferral_account_id] ?? ['debit' => '0', 'credit' => '0'];
        $net = AccountBalanceService::net($sum);
        $normal = $schedule->deferralAccount?->normal_balance;
        $balance = $normal === NormalBalance::Credit ? JournalMath::store(JournalMath::neg($net)) : $net;
        if (JournalMath::compare($balance, (string) $schedule->total_amount_primary) >= 0) {
            return [];
        }

        return [[
            'code' => 'DEFERRAL_INSUFFICIENT_BALANCE',
            'message' => 'The deferral account balance ('.$balance.') is lower than this schedule ('.(string) $schedule->total_amount_primary.').',
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableText(array $data, string $key, ?string $existing, int $max): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $existing;
        }
        $value = trim((string) ($data[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Only schedules of the branches the user may access (ReportBranchScope).
     *
     * @param  Builder<DeferralSchedule>  $query
     */
    private function scopeToBranches(Builder $query): void
    {
        $filters = $this->branchScope->apply(null)['filters'];
        if (array_key_exists('branch_id', $filters)) {
            $query->where('branch_id', (int) $filters['branch_id']);
        } elseif (array_key_exists('branch_ids', $filters)) {
            $ids = (array) $filters['branch_ids'];
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('branch_id', $ids);
        }
    }
}
