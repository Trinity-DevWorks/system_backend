<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Services;

use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalLineRules;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\RecurringEntry\Enums\RateMode;
use App\Modules\RecurringEntry\Enums\RecurringDocumentType;
use App\Modules\RecurringEntry\Enums\RecurringRunStatus;
use App\Modules\RecurringEntry\Enums\RecurringTemplateStatus;
use App\Modules\RecurringEntry\Models\RecurringRun;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\RecurringEntry\Models\RecurringTemplateLine;
use App\Modules\RecurringEntry\Support\DescriptionPlaceholders;
use App\Modules\RecurringEntry\Support\RecurrenceCalculator;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Services\ExpenseVoucherService;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use App\Support\TenantClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringTemplateService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly JournalLineRules $journalLineRules,
        private readonly ExpenseVoucherService $expenseVouchers,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * @param  array{status?: string, document_type?: string, search?: string}  $filters
     * @return LengthAwarePaginator<int, RecurringTemplate>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = RecurringTemplate::query()
            ->with([
                'branch:id,name',
                'currency:id,code,symbol',
                'cashBankAccount:id,code,name',
                'lines:id,recurring_template_id,debit,amount',
            ])
            ->orderByRaw('next_run_date asc nulls last')
            ->orderByDesc('created_at');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }
        $this->scopeToBranches($query);
        ListPagination::applySearch($query, $filters['search'] ?? null, ['template_number', 'name', 'description', 'payee', 'reference']);

        return $query->paginate($perPage);
    }

    public function find(string $id): RecurringTemplate
    {
        return RecurringTemplate::query()
            ->with([
                'branch:id,name',
                'currency:id,code,symbol',
                'cashBankAccount:id,code,name',
                'supplier:id,name',
                'createdByUser:id,name',
                'lines.account:id,code,name',
                'lines.vatGroup:id,name,percentage',
                'runs' => fn ($q) => $q->with('triggeredByUser:id,name')->limit(10),
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): RecurringTemplate
    {
        return DB::transaction(function () use ($data, $userId): RecurringTemplate {
            $header = $this->header($data, null);
            RecurrenceCalculator::assertValid($header);
            $this->assertLines($header, (array) ($data['lines'] ?? []));

            $template = RecurringTemplate::query()->create([
                ...$header,
                'occurrences_count' => 0,
                'next_occurrence_index' => 0,
                'status' => RecurringTemplateStatus::Active,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->replaceLines($template, (array) ($data['lines'] ?? []));
            $template->template_number = $this->numbers->next(DocumentType::RecurringTemplate, (int) $template->branch_id, $template->start_date);
            $this->recompute($template);
            $template->save();

            return $this->find((string) $template->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RecurringTemplate $template, array $data, ?string $userId): RecurringTemplate
    {
        return DB::transaction(function () use ($template, $data, $userId): RecurringTemplate {
            $locked = RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                abort(422, 'A completed or cancelled template cannot be edited.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NOT_EDITABLE']);
            }
            $this->assertScheduleEditable($locked, $data);
            $header = $this->header($data, $locked);
            RecurrenceCalculator::assertValid($header);
            $lines = array_key_exists('lines', $data) ? (array) $data['lines'] : null;
            if ($lines !== null) {
                $this->assertLines($header, $lines);
            }

            $locked->fill($header);
            $locked->updated_by = $userId;
            if ($lines !== null) {
                $this->replaceLines($locked, $lines);
            }
            $this->recompute($locked);
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    public function delete(RecurringTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $locked = RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($locked->runs()->exists()) {
                abort(422, 'This template has already run. Cancel it instead of deleting it.', [
                    'X-Error-Code' => 'RECURRING_TEMPLATE_HAS_RUNS',
                ]);
            }
            $locked->delete();
        });
    }

    public function pause(RecurringTemplate $template): RecurringTemplate
    {
        return $this->transition($template, RecurringTemplateStatus::Active, RecurringTemplateStatus::Paused, 'Only an active template can be paused.');
    }

    public function resume(RecurringTemplate $template, bool $catchUp): RecurringTemplate
    {
        return DB::transaction(function () use ($template, $catchUp): RecurringTemplate {
            $locked = RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RecurringTemplateStatus::Paused) {
                abort(422, 'Only a paused template can be resumed.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NOT_EDITABLE']);
            }
            $locked->status = RecurringTemplateStatus::Active;
            if (! $catchUp) {
                $slot = RecurrenceCalculator::firstOnOrAfter($locked, TenantClock::today(), (int) $locked->next_occurrence_index);
                $finished = $locked->max_occurrences !== null && (int) $locked->occurrences_count >= (int) $locked->max_occurrences;
                if ($slot === null || $finished) {
                    $locked->status = RecurringTemplateStatus::Completed;
                    $locked->next_run_date = null;
                } else {
                    $locked->next_occurrence_index = $slot['n'];
                    $locked->next_run_date = $slot['date']->copy();
                }
            } else {
                $this->recompute($locked);
            }
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    public function cancel(RecurringTemplate $template): RecurringTemplate
    {
        return DB::transaction(function () use ($template): RecurringTemplate {
            $locked = RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                abort(422, 'This template is already finished.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NOT_EDITABLE']);
            }
            $locked->status = RecurringTemplateStatus::Cancelled;
            $locked->next_run_date = null;
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    public function skip(RecurringTemplate $template, ?string $userId): RecurringTemplate
    {
        return DB::transaction(function () use ($template, $userId): RecurringTemplate {
            $locked = $this->lockActive($template);
            if ($locked->next_run_date === null) {
                abort(422, 'This template has no occurrence to skip.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NOT_ACTIVE']);
            }
            $date = $locked->next_run_date->toDateString();
            $existing = RecurringRun::query()
                ->where('recurring_template_id', $locked->id)
                ->whereDate('scheduled_date', $date)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && $existing->status !== RecurringRunStatus::Failed) {
                abort(422, 'This occurrence was already processed.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NOT_ACTIVE']);
            }
            if ($existing !== null) {
                $existing->update([
                    'status' => RecurringRunStatus::Skipped,
                    'error_code' => null,
                    'error_message' => null,
                    'document_id' => null,
                    'document_number' => null,
                    'triggered_by' => $userId,
                ]);
            } else {
                RecurringRun::query()->create([
                    'recurring_template_id' => $locked->id,
                    'scheduled_date' => $date,
                    'status' => RecurringRunStatus::Skipped,
                    'document_type' => $locked->document_type,
                    'triggered_by' => $userId,
                    'attempts' => 1,
                ]);
            }
            $locked->next_occurrence_index = (int) $locked->next_occurrence_index + 1;
            $locked->last_run_date = Carbon::parse($date);
            $locked->last_error_code = null;
            $this->recompute($locked);
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    /**
     * @param  RecurringTemplate|array<string, mixed>  $rule
     * @return list<array{date: string, description: ?string, total: string}>
     */
    public function preview(RecurringTemplate|array $rule, int $count): array
    {
        $max = (int) config('accounting.recurring.preview_max', 36);
        $count = min($max, max(1, $count));
        if (is_array($rule)) {
            $header = $this->header($rule, null);
            RecurrenceCalculator::assertValid($header);
            $from = 0;
            $description = $header['description'] ?? null;
            $total = $this->payloadTotal($header, (array) ($rule['lines'] ?? []));
            $source = $header;
        } else {
            $from = (int) $rule->next_occurrence_index;
            $description = $rule->description;
            $total = $rule->totalAmount();
            $source = $rule;
        }

        return array_map(fn (Carbon $date): array => [
            'date' => $date->toDateString(),
            'description' => DescriptionPlaceholders::render(is_string($description) ? $description : null, $date),
            'total' => $total,
        ], RecurrenceCalculator::preview($source, $count, $from));
    }

    public function recompute(RecurringTemplate $template): void
    {
        if ($template->status === RecurringTemplateStatus::Cancelled) {
            $template->next_run_date = null;

            return;
        }
        $next = RecurrenceCalculator::nextRunDate($template);
        if ($next === null) {
            $template->status = RecurringTemplateStatus::Completed;
            $template->next_run_date = null;

            return;
        }
        if ($template->status !== RecurringTemplateStatus::Paused) {
            $template->status = RecurringTemplateStatus::Active;
        }
        $template->next_run_date = $next->copy();
    }

    private function transition(RecurringTemplate $template, RecurringTemplateStatus $from, RecurringTemplateStatus $to, string $message): RecurringTemplate
    {
        return DB::transaction(function () use ($template, $from, $to, $message): RecurringTemplate {
            $locked = RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                abort(422, $message, ['X-Error-Code' => 'RECURRING_TEMPLATE_NOT_ACTIVE']);
            }
            $locked->status = $to;
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    private function lockActive(RecurringTemplate $template): RecurringTemplate
    {
        $locked = RecurringTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== RecurringTemplateStatus::Active) {
            abort(422, 'Only an active template can be run or skipped.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NOT_ACTIVE']);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data, ?RecurringTemplate $existing): array
    {
        $type = RecurringDocumentType::from((string) ($data['document_type'] ?? $existing?->document_type->value));
        $currencyId = (int) ($data['currency_id'] ?? $existing?->currency_id ?? 0);
        $cashId = $data['cash_bank_account_id'] ?? $existing?->cash_bank_account_id;
        if ($type === RecurringDocumentType::ExpenseVoucher) {
            $cash = CashBankAccount::query()->find((int) $cashId);
            if ($cash === null || ! $cash->is_active) {
                abort(422, 'Choose an active cash/bank account.', ['X-Error-Code' => 'TREASURY_ACCOUNT_INACTIVE']);
            }
            $currencyId = (int) $cash->currency_id;
            $cashId = (int) $cash->id;
        } elseif ($currencyId <= 0) {
            abort(422, 'A currency is required.', ['X-Error-Code' => 'RECURRING_TEMPLATE_INVALID_SCHEDULE']);
        } else {
            $cashId = null;
        }

        $rateMode = RateMode::from((string) ($data['rate_mode'] ?? $existing?->rate_mode->value ?? RateMode::Current->value));
        $fixed = $data['fixed_exchange_rate'] ?? $existing?->fixed_exchange_rate;
        if ($rateMode === RateMode::Fixed) {
            $fixed = ExchangeRateSnapshot::resolve($this->exchangeRateService, $currencyId, $fixed, 'JOURNAL');
        } else {
            $fixed = null;
        }

        $text = function (string $key, int $max) use ($data, $existing): ?string {
            if (! array_key_exists($key, $data) && $existing !== null) {
                $value = $existing->getAttribute($key);
            } else {
                $value = $data[$key] ?? null;
            }
            $value = trim((string) ($value ?? ''));

            return $value === '' ? null : mb_substr($value, 0, $max);
        };

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? $existing?->name ?? '')), 0, 255),
            'description' => $text('description', 500),
            'document_type' => $type,
            // New templates take the user's active branch (DocumentBranchResolver); edits keep the branch.
            'branch_id' => $existing?->branch_id,
            'currency_id' => $currencyId,
            'rate_mode' => $rateMode,
            'fixed_exchange_rate' => $fixed,
            'cash_bank_account_id' => $cashId,
            'payee' => $type === RecurringDocumentType::ExpenseVoucher ? $text('payee', 255) : null,
            'supplier_id' => $type === RecurringDocumentType::ExpenseVoucher ? ($data['supplier_id'] ?? $existing?->supplier_id) : null,
            'reference' => $text('reference', 128),
            'notes' => array_key_exists('notes', $data) || $existing === null
                ? (trim((string) ($data['notes'] ?? '')) !== '' ? trim((string) $data['notes']) : null)
                : $existing->notes,
            'frequency' => $data['frequency'] ?? $existing?->frequency->value,
            'interval' => (int) ($data['interval'] ?? $existing?->interval ?? 1),
            'day_of_month' => $this->nullableInt($data, 'day_of_month', $existing?->day_of_month),
            'day_of_week' => $this->nullableInt($data, 'day_of_week', $existing?->day_of_week),
            'start_date' => substr((string) ($data['start_date'] ?? $existing?->start_date?->toDateString()), 0, 10),
            'end_date' => $this->nullableDate($data, 'end_date', $existing?->end_date?->toDateString()),
            'max_occurrences' => $this->nullableInt($data, 'max_occurrences', $existing?->max_occurrences),
            'auto_post' => (bool) ($data['auto_post'] ?? $existing?->auto_post ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertScheduleEditable(RecurringTemplate $template, array $data): void
    {
        if ((int) $template->occurrences_count === 0) {
            return;
        }
        foreach (['frequency', 'interval', 'day_of_month', 'day_of_week', 'start_date', 'document_type'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $current = $template->getAttribute($field);
            $current = $current instanceof \BackedEnum ? $current->value : ($current instanceof Carbon ? $current->toDateString() : $current);
            $incoming = $data[$field];
            if ($incoming instanceof Carbon) {
                $incoming = $incoming->toDateString();
            }
            if ($field === 'start_date') {
                $incoming = substr((string) $incoming, 0, 10);
            }
            if ((string) $incoming !== (string) $current) {
                abort(422, 'The schedule cannot change after the first run. Adjust the end date or the amounts instead.', [
                    'X-Error-Code' => 'RECURRING_TEMPLATE_NOT_EDITABLE',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertLines(array $header, array $rows): void
    {
        if ($rows === []) {
            abort(422, 'Add at least one line.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NO_LINES']);
        }
        $type = $header['document_type'] instanceof RecurringDocumentType
            ? $header['document_type']
            : RecurringDocumentType::from((string) $header['document_type']);
        if ($type === RecurringDocumentType::ExpenseVoucher) {
            $total = '0';
            foreach (array_values($rows) as $index => $row) {
                $account = Account::query()->find((int) ($row['account_id'] ?? 0));
                if (! $account instanceof Account) {
                    abort(422, 'Line '.($index + 1).': choose an active, non-group expense account.', [
                        'X-Error-Code' => 'EXPENSE_VOUCHER_ACCOUNT_INVALID',
                    ]);
                }
                $this->expenseVouchers->assertLineAccount($account, $index);
                $amount = JournalMath::round($row['amount'] ?? 0);
                if (! JournalMath::isPositive($amount)) {
                    abort(422, 'Line '.($index + 1).': enter an amount greater than zero.', ['X-Error-Code' => 'TREASURY_AMOUNT_INVALID']);
                }
                $total = JournalMath::add($total, $amount);
            }
            if (! JournalMath::isPositive($total)) {
                abort(422, 'Add at least one line.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NO_LINES']);
            }

            return;
        }

        $this->assertJournalLines($header, $rows);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertJournalLines(array $header, array $rows): void
    {
        if (count($rows) < 2) {
            abort(422, 'A journal template needs at least two lines.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NO_LINES']);
        }
        $rate = $header['rate_mode'] === RateMode::Fixed || $header['rate_mode'] === RateMode::Fixed->value
            ? (string) $header['fixed_exchange_rate']
            : ExchangeRateSnapshot::resolve($this->exchangeRateService, (int) $header['currency_id'], null, 'JOURNAL');
        $debit = '0';
        $credit = '0';
        $prepared = [];
        foreach (array_values($rows) as $row) {
            $lineDebit = JournalMath::round($row['debit'] ?? 0);
            $lineCredit = JournalMath::round($row['credit'] ?? 0);
            if ((JournalMath::isPositive($lineDebit) && JournalMath::isPositive($lineCredit))
                || (! JournalMath::isPositive($lineDebit) && ! JournalMath::isPositive($lineCredit))) {
                abort(422, 'Each journal line needs an amount on exactly one side, and debits must equal credits.', [
                    'X-Error-Code' => 'RECURRING_TEMPLATE_UNBALANCED',
                ]);
            }
            $debit = JournalMath::add($debit, $lineDebit);
            $credit = JournalMath::add($credit, $lineCredit);
            $primaryDebit = JournalMath::isPositive($lineDebit)
                ? JournalMath::round(ExchangeRateSnapshot::toPrimary(JournalMath::store($lineDebit), $rate, JournalMath::WORK_SCALE))
                : '0';
            $primaryCredit = JournalMath::isPositive($lineCredit)
                ? JournalMath::round(ExchangeRateSnapshot::toPrimary(JournalMath::store($lineCredit), $rate, JournalMath::WORK_SCALE))
                : '0';
            $prepared[] = new JournalLineData(
                accountId: (int) $row['account_id'],
                debit: JournalMath::store($primaryDebit),
                credit: JournalMath::store($primaryCredit),
                currencyId: (int) $header['currency_id'],
                exchangeRate: $rate,
                amountCurrency: JournalMath::store(JournalMath::sub($lineDebit, $lineCredit)),
                partyType: isset($row['party_type']) && $row['party_type'] !== '' ? (string) $row['party_type'] : null,
                partyId: isset($row['party_id']) && $row['party_id'] !== '' ? (string) $row['party_id'] : null,
                description: isset($row['description']) ? (string) $row['description'] : null,
            );
        }
        if (JournalMath::compare($debit, $credit) !== 0) {
            abort(422, 'Journal template debits must equal credits.', ['X-Error-Code' => 'RECURRING_TEMPLATE_UNBALANCED']);
        }
        if (! JournalMath::isPositive($debit)) {
            abort(422, 'Add at least one line.', ['X-Error-Code' => 'RECURRING_TEMPLATE_NO_LINES']);
        }
        $this->journalLineRules->prepare($prepared, true);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceLines(RecurringTemplate $template, array $rows): void
    {
        RecurringTemplateLine::query()->where('recurring_template_id', $template->id)->delete();
        $type = $template->document_type;
        foreach (array_values($rows) as $index => $row) {
            $description = isset($row['description']) ? trim((string) $row['description']) : '';
            RecurringTemplateLine::query()->create([
                'recurring_template_id' => $template->id,
                'account_id' => (int) $row['account_id'],
                'description' => $description !== '' ? mb_substr($description, 0, 500) : null,
                'debit' => $type === RecurringDocumentType::JournalEntry ? JournalMath::store(JournalMath::round($row['debit'] ?? 0)) : '0',
                'credit' => $type === RecurringDocumentType::JournalEntry ? JournalMath::store(JournalMath::round($row['credit'] ?? 0)) : '0',
                'amount' => $type === RecurringDocumentType::ExpenseVoucher ? JournalMath::store(JournalMath::round($row['amount'] ?? 0)) : '0',
                'vat_group_id' => $type === RecurringDocumentType::ExpenseVoucher && ! empty($row['vat_group_id']) ? (int) $row['vat_group_id'] : null,
                'party_type' => $type === RecurringDocumentType::JournalEntry && ! empty($row['party_type']) ? (string) $row['party_type'] : null,
                'party_id' => $type === RecurringDocumentType::JournalEntry && ! empty($row['party_id']) ? (string) $row['party_id'] : null,
                'sort_order' => $index + 1,
            ]);
        }
        $template->unsetRelation('lines');
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $rows
     */
    private function payloadTotal(array $header, array $rows): string
    {
        $type = $header['document_type'] instanceof RecurringDocumentType
            ? $header['document_type']
            : RecurringDocumentType::from((string) $header['document_type']);
        $total = '0';
        foreach ($rows as $row) {
            $part = $type === RecurringDocumentType::ExpenseVoucher ? ($row['amount'] ?? 0) : ($row['debit'] ?? 0);
            $total = JournalMath::add($total, JournalMath::round($part));
        }

        return JournalMath::store($total);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableInt(array $data, string $key, mixed $existing): ?int
    {
        if (! array_key_exists($key, $data)) {
            return $existing !== null ? (int) $existing : null;
        }
        if ($data[$key] === null || $data[$key] === '') {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableDate(array $data, string $key, ?string $existing): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $existing;
        }
        if ($data[$key] === null || $data[$key] === '') {
            return null;
        }

        return substr((string) $data[$key], 0, 10);
    }

    /**
     * Only templates of the branches the user may access (ReportBranchScope).
     *
     * @param  Builder<RecurringTemplate>  $query
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
