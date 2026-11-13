<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Services;

use App\Modules\Accounting\Budget\DTOs\BudgetResponseData;
use App\Modules\Accounting\Budget\Enums\BudgetStatus;
use App\Modules\Accounting\Budget\Models\Budget;
use App\Modules\Accounting\Budget\Models\BudgetLine;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Support\Export\TabularDocument;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class BudgetService
{
    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly AccountBalanceService $balances,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * @param  array{search?: ?string, status?: ?string, year?: ?int, branch_id?: ?int}  $filters
     * @return LengthAwarePaginator<int, Budget>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Budget::query()
            ->with(['branch:id,name'])
            ->orderByDesc('fiscal_year_start')
            ->orderBy('name')
            ->orderByDesc('version');

        ListPagination::applySearch($query, $filters['search'] ?? null, ['name', 'notes']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['year'])) {
            $query->whereYear('fiscal_year_start', (int) $filters['year']);
        }
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        // Branch budgets only for the branches the user may access; company budgets (no branch) for everyone.
        $scope = $this->branchScope->apply(null)['filters'];
        if (array_key_exists('branch_id', $scope)) {
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', (int) $scope['branch_id']));
        } elseif (array_key_exists('branch_ids', $scope)) {
            $ids = array_map('intval', (array) $scope['branch_ids']);
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $ids));
        }

        return $query->paginate($perPage);
    }

    public function find(Budget $budget): Budget
    {
        return $budget->load(['branch:id,name', 'createdByUser:id,name', 'approvedByUser:id,name']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): Budget
    {
        $bounds = $this->calendar->yearStartingIn((int) $data['year']);

        return Budget::query()->create([
            'name' => trim((string) $data['name']),
            'fiscal_year_start' => $bounds['starts_on'],
            'fiscal_year_end' => $bounds['ends_on'],
            'branch_id' => $data['branch_id'] ?? null,
            'version' => 1,
            'status' => BudgetStatus::Draft,
            'notes' => $this->nullableText($data['notes'] ?? null),
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Budget $budget, array $data): Budget
    {
        $this->assertDraft($budget);

        if (array_key_exists('name', $data)) {
            $budget->name = trim((string) $data['name']);
        }
        if (array_key_exists('branch_id', $data)) {
            $budget->branch_id = $data['branch_id'] !== null ? (int) $data['branch_id'] : null;
        }
        if (array_key_exists('notes', $data)) {
            $budget->notes = $this->nullableText($data['notes']);
        }
        $budget->save();

        return $budget;
    }

    public function delete(Budget $budget): void
    {
        $this->assertDraft($budget);
        $budget->delete();
    }

    /**
     * @param  list<array{account_id: int|string, period_month: string, amount: int|string|float}>  $lines
     */
    public function saveLines(Budget $budget, array $lines): void
    {
        $this->assertDraft($budget);
        $normalized = $this->normalizeLines($budget, $lines);

        if ($normalized === []) {
            return;
        }

        $this->upsertLines($budget, $normalized);
    }

    public function spreadEvenly(Budget $budget, int $accountId, mixed $yearlyAmount): void
    {
        $this->assertDraft($budget);
        $this->assertPostable([$accountId]);

        $months = $this->months($budget);
        $yearly = JournalMath::store($yearlyAmount);
        $monthly = JournalMath::store(bcdiv(JournalMath::of($yearly), '12', JournalMath::WORK_SCALE));
        $last = JournalMath::store(JournalMath::sub($yearly, JournalMath::mul($monthly, '11')));

        $lines = [];
        foreach ($months as $index => $month) {
            $lines[] = [
                'account_id' => $accountId,
                'period_month' => $month,
                'amount' => $index === 11 ? $last : $monthly,
            ];
        }

        $this->upsertLines($budget, $lines);
    }

    /**
     * Replace revenue and expense lines with ledger actuals of another fiscal year, adjusted by percent.
     */
    public function copyActuals(Budget $budget, int $sourceYear, mixed $percent): void
    {
        $this->assertDraft($budget);

        $bounds = $this->calendar->yearStartingIn($sourceYear);
        $sourceMonths = [];
        $cursor = Carbon::parse($bounds['starts_on'])->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $sourceMonths[] = $cursor->copy()->addMonths($i)->toDateString();
        }
        $targetMonths = $this->months($budget);

        $accounts = Account::query()
            ->where('is_group', false)
            ->where('is_active', true)
            ->whereIn('type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->get();

        $filters = ['exclude_source_types' => [JournalEntry::SOURCE_YEAR_END_CLOSE]];
        if ($budget->branch_id !== null) {
            $filters['branch_id'] = (int) $budget->branch_id;
        }

        $lines = [];
        foreach ($sourceMonths as $index => $month) {
            $end = Carbon::parse($month)->endOfMonth()->toDateString();
            $totals = $this->balances->totals($month, $end, null, $filters);
            foreach ($accounts as $account) {
                $sum = $totals[(int) $account->id] ?? null;
                $natural = $account->type === AccountType::Revenue
                    ? JournalMath::sub($sum['credit'] ?? '0', $sum['debit'] ?? '0')
                    : JournalMath::sub($sum['debit'] ?? '0', $sum['credit'] ?? '0');
                $amount = $this->scaleAmount($natural, $percent);
                if (JournalMath::isZero($amount)) {
                    continue;
                }
                $lines[] = [
                    'account_id' => (int) $account->id,
                    'period_month' => $targetMonths[$index],
                    'amount' => $amount,
                ];
            }
        }

        DB::transaction(function () use ($budget, $accounts, $lines): void {
            BudgetLine::query()
                ->where('budget_id', $budget->id)
                ->whereIn('account_id', $accounts->pluck('id')->all())
                ->delete();
            $this->upsertLines($budget, $lines);
        });
    }

    public function copyFrom(Budget $budget, string $sourceId, mixed $percent): void
    {
        $this->assertDraft($budget);
        if ($sourceId === (string) $budget->id) {
            abort(422, 'Choose a different budget to copy from.');
        }

        $source = Budget::query()->with('lines')->find($sourceId);
        if ($source === null) {
            abort(404, 'Budget not found.');
        }

        $sourceIndex = array_flip($this->months($source));
        $targetMonths = $this->months($budget);
        $raw = [];
        foreach ($source->lines as $line) {
            $key = $line->period_month->toDateString();
            if (! isset($sourceIndex[$key])) {
                continue;
            }
            $amount = $this->scaleAmount((string) $line->amount, $percent);
            if (JournalMath::isZero($amount)) {
                continue;
            }
            $raw[] = [
                'account_id' => (int) $line->account_id,
                'period_month' => $targetMonths[$sourceIndex[$key]],
                'amount' => $amount,
            ];
        }

        $postable = $this->postableIds(array_map(fn (array $line): int => $line['account_id'], $raw));
        $lines = array_values(array_filter(
            $raw,
            fn (array $line): bool => isset($postable[$line['account_id']])
        ));

        DB::transaction(function () use ($budget, $lines): void {
            BudgetLine::query()->where('budget_id', $budget->id)->delete();
            $this->upsertLines($budget, $lines);
        });
    }

    public function approve(Budget $budget, ?string $userId): Budget
    {
        return DB::transaction(function () use ($budget, $userId): Budget {
            /** @var Budget $locked */
            $locked = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            $this->assertApprovalSlotFree($locked);

            $locked->status = BudgetStatus::Approved;
            $locked->approved_by = $userId;
            $locked->approved_at = now();

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                $this->approvalConflict();
            }

            return $locked;
        });
    }

    public function revise(Budget $budget, ?string $userId): Budget
    {
        return DB::transaction(function () use ($budget, $userId): Budget {
            /** @var Budget $locked */
            $locked = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BudgetStatus::Approved) {
                abort(422, 'Only an approved budget can be revised.');
            }

            $locked->status = BudgetStatus::Archived;
            $locked->save();

            $copy = Budget::query()->create([
                'name' => $locked->name,
                'fiscal_year_start' => $locked->fiscal_year_start->toDateString(),
                'fiscal_year_end' => $locked->fiscal_year_end->toDateString(),
                'branch_id' => $locked->branch_id,
                'version' => (int) $locked->version + 1,
                'parent_budget_id' => $locked->id,
                'status' => BudgetStatus::Draft,
                'notes' => $locked->notes,
                'created_by' => $userId,
            ]);

            $lines = [];
            foreach ($locked->lines()->get() as $line) {
                $lines[] = [
                    'account_id' => (int) $line->account_id,
                    'period_month' => $line->period_month->toDateString(),
                    'amount' => JournalMath::store($line->amount),
                ];
            }
            $this->upsertLines($copy, $lines);

            return $copy;
        });
    }

    public function archive(Budget $budget): Budget
    {
        if ($budget->status === BudgetStatus::Archived) {
            return $budget;
        }

        $budget->status = BudgetStatus::Archived;
        $budget->save();

        return $budget;
    }

    /**
     * @return array{months: list<string>, rows: list<array<string, mixed>>}
     */
    public function grid(Budget $budget, bool $includeBalanceSheet = false): array
    {
        $months = $this->months($budget);
        /** @var EloquentCollection<int, Account> $accounts */
        $accounts = Account::query()->orderBy('code')->get();

        $parents = [];
        foreach ($accounts as $account) {
            $parents[(int) $account->id] = $account->parent_id !== null ? (int) $account->parent_id : null;
        }

        $lineRows = BudgetLine::query()->where('budget_id', $budget->id)->get();
        $own = [];
        $withLines = [];
        foreach ($lineRows as $line) {
            $month = $line->period_month->toDateString();
            if (! in_array($month, $months, true)) {
                continue;
            }
            $accountId = (int) $line->account_id;
            $own[$accountId][$month] = JournalMath::store($line->amount);
            $withLines[$accountId] = true;
        }

        $included = [];
        foreach ($accounts as $account) {
            $id = (int) $account->id;
            $pnl = $account->type === AccountType::Revenue || $account->type === AccountType::Expense;
            $inScope = $includeBalanceSheet || $pnl;
            if (isset($withLines[$id]) || ($account->is_active && $inScope)) {
                $included[$id] = true;
            }
        }
        foreach (array_keys($included) as $id) {
            $cursor = $parents[$id] ?? null;
            $guard = 0;
            while ($cursor !== null && $guard++ < 32) {
                $included[$cursor] = true;
                $cursor = $parents[$cursor] ?? null;
            }
        }

        $rolled = $this->rollAmounts($parents, $own);
        $depths = $this->depths($parents);
        $rows = [];
        foreach ($accounts as $account) {
            $id = (int) $account->id;
            if (! isset($included[$id])) {
                continue;
            }
            $amounts = [];
            $total = '0.0000';
            foreach ($months as $month) {
                $value = JournalMath::store($rolled[$id][$month] ?? '0');
                $amounts[$month] = $value;
                $total = JournalMath::store(JournalMath::add($total, $value));
            }
            $rows[] = [
                'account_id' => $id,
                'code' => $account->code,
                'name' => $account->name,
                'name_ar' => $account->name_ar,
                'type' => $account->type?->value,
                'level' => $depths[$id] ?? 1,
                'is_group' => (bool) $account->is_group,
                'is_active' => (bool) $account->is_active,
                'is_postable' => $account->isPostable(),
                'parent_id' => $account->parent_id !== null ? (int) $account->parent_id : null,
                'amounts' => $amounts,
                'total' => $total,
            ];
        }

        return ['months' => $months, 'rows' => $rows];
    }

    /**
     * Excel / PDF of the budget. By default only accounts with an amount; `$allAccounts`
     * lists every postable account (an empty template to fill and import).
     */
    public function document(Budget $budget, bool $allAccounts = false): TabularDocument
    {
        $grid = $this->grid($budget, true);
        $monthHeaders = array_map(
            fn (string $month): string => Carbon::parse($month)->format('Y-m'),
            $grid['months']
        );
        $rows = [];
        foreach ($grid['rows'] as $row) {
            if (! $row['is_postable']) {
                continue;
            }
            if (! $allAccounts && ! $this->hasAmount($row['amounts'])) {
                continue;
            }
            $cells = [$row['code'], $row['name']];
            foreach ($grid['months'] as $month) {
                $cells[] = $row['amounts'][$month];
            }
            $cells[] = $row['total'];
            $rows[] = $cells;
        }

        $filters = [
            $budget->name,
            $budget->fiscal_year_start->toDateString().' – '.$budget->fiscal_year_end->toDateString(),
        ];
        if ($budget->branch_id !== null) {
            $budget->loadMissing('branch:id,name');
            $filters[] = (string) ($budget->branch?->name ?? '');
        }

        return new TabularDocument(
            title: 'Budget '.$budget->name,
            filename: 'budget-'.$budget->fiscal_year_start->format('Y'),
            headings: ['code', 'name', ...$monthHeaders, 'total'],
            rows: $rows,
            filters: $filters,
        );
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    private function hasAmount(array $amounts): bool
    {
        foreach ($amounts as $amount) {
            if ((float) $amount != 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{row: int, code: string, message: string}>
     */
    public function import(Budget $budget, UploadedFile $file): array
    {
        $this->assertDraft($budget);

        try {
            $sheet = IOFactory::load($file->getRealPath() ?: $file->getPathname())->getActiveSheet();
            $matrix = $sheet->toArray(null, true, false, false);
        } catch (\Throwable) {
            return [['row' => 1, 'code' => '', 'message' => 'The file could not be read.']];
        }

        $headerIndex = null;
        foreach ($matrix as $index => $row) {
            if (strtolower($this->cellText($row[0] ?? null)) === 'code') {
                $headerIndex = $index;

                break;
            }
        }
        if ($headerIndex === null) {
            return [['row' => 1, 'code' => '', 'message' => 'The sheet needs a code column and the twelve fiscal months.']];
        }

        /** @var list<mixed> $header */
        $header = $matrix[$headerIndex];
        $months = $this->months($budget);
        $monthColumns = [];
        foreach ($header as $column => $value) {
            if ($column === 0) {
                continue;
            }
            $month = $this->monthFromCell($value);
            if ($month !== null && in_array($month, $months, true)) {
                $monthColumns[$month] = $column;
            }
        }
        foreach ($months as $month) {
            if (! isset($monthColumns[$month])) {
                return [['row' => $headerIndex + 1, 'code' => '', 'message' => 'Month columns do not match this budget.']];
            }
        }

        $errors = [];
        $parsed = [];
        $seen = [];
        foreach ($matrix as $index => $row) {
            if ($index <= $headerIndex) {
                continue;
            }
            $code = $this->cellText($row[0] ?? null);
            if ($code === '' || strtolower($code) === 'code') {
                continue;
            }
            $sheetRow = $index + 1;
            if (isset($seen[$code])) {
                $errors[] = ['row' => $sheetRow, 'code' => $code, 'message' => 'This account is listed more than once.'];

                continue;
            }
            $seen[$code] = true;

            $amounts = [];
            $invalidAmount = false;
            foreach ($months as $month) {
                $cell = $row[$monthColumns[$month]] ?? null;
                if ($cell === null || (is_string($cell) && trim($cell) === '')) {
                    $amounts[$month] = '0';

                    continue;
                }
                if (! is_numeric($cell)) {
                    $invalidAmount = true;

                    break;
                }
                $amounts[$month] = JournalMath::store($cell);
            }
            if ($invalidAmount) {
                $errors[] = ['row' => $sheetRow, 'code' => $code, 'message' => 'A month amount is not a number.'];

                continue;
            }
            $parsed[] = ['row' => $sheetRow, 'code' => $code, 'amounts' => $amounts];
        }

        if ($errors !== []) {
            return $errors;
        }

        $accounts = Account::query()->whereIn('code', array_column($parsed, 'code'))->get()->keyBy('code');
        $lines = [];
        foreach ($parsed as $row) {
            $account = $accounts->get($row['code']);
            if ($account === null || ! $account->isPostable()) {
                $errors[] = [
                    'row' => $row['row'],
                    'code' => $row['code'],
                    'message' => 'Budgets are entered on active posting accounts only.',
                ];

                continue;
            }
            foreach ($row['amounts'] as $month => $amount) {
                $lines[] = [
                    'account_id' => (int) $account->id,
                    'period_month' => $month,
                    'amount' => $amount,
                ];
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $this->upsertLines($budget, $lines);

        return [];
    }

    /**
     * @param  Collection<int, Budget>  $budgets
     * @return array<string, array{revenue: string, expense: string}>
     */
    public function statementTotals(Collection $budgets): array
    {
        $out = [];
        foreach ($budgets as $budget) {
            $out[(string) $budget->id] = ['revenue' => '0.0000', 'expense' => '0.0000'];
        }
        if ($out === []) {
            return $out;
        }

        $rows = BudgetLine::query()
            ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
            ->whereIn('budget_lines.budget_id', array_keys($out))
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->groupBy('budget_lines.budget_id', 'accounts.type')
            ->selectRaw('budget_lines.budget_id, accounts.type, SUM(budget_lines.amount) as total')
            ->get();

        foreach ($rows as $row) {
            $id = (string) $row->getAttribute('budget_id');
            $type = (string) $row->getAttribute('type');
            if (! isset($out[$id]) || ($type !== 'revenue' && $type !== 'expense')) {
                continue;
            }
            $out[$id][$type] = JournalMath::store($row->getAttribute('total'));
        }

        return $out;
    }

    /**
     * @param  array{revenue: string, expense: string}|null  $totals
     * @return array<string, mixed>
     */
    public function present(Budget $budget, ?array $totals = null): array
    {
        $budget->loadMissing(['branch:id,name', 'createdByUser:id,name', 'approvedByUser:id,name']);
        $totals ??= $this->statementTotals(collect([$budget]))[(string) $budget->id]
            ?? ['revenue' => '0.0000', 'expense' => '0.0000'];

        return BudgetResponseData::budget($budget, $totals);
    }

    /**
     * @return list<string>
     */
    public function months(Budget $budget): array
    {
        $start = Carbon::parse($budget->fiscal_year_start)->startOfMonth();
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->copy()->addMonths($i)->toDateString();
        }

        return $months;
    }

    private function assertDraft(Budget $budget): void
    {
        if ($budget->status !== BudgetStatus::Draft) {
            abort(422, 'Only a draft budget can be changed.', ['X-Error-Code' => 'BUDGET_NOT_DRAFT']);
        }
    }

    private function assertApprovalSlotFree(Budget $budget): void
    {
        $taken = Budget::query()
            ->where('status', BudgetStatus::Approved)
            ->whereDate('fiscal_year_start', $budget->fiscal_year_start->toDateString())
            ->when(
                $budget->branch_id === null,
                fn ($query) => $query->whereNull('branch_id'),
                fn ($query) => $query->where('branch_id', $budget->branch_id),
            )
            ->whereKeyNot($budget->id)
            ->exists();

        if ($taken) {
            $this->approvalConflict();
        }
    }

    private function approvalConflict(): never
    {
        abort(422, 'An approved budget already exists for this fiscal year and branch.', [
            'X-Error-Code' => 'BUDGET_ALREADY_APPROVED_FOR_YEAR',
        ]);
    }

    /**
     * @param  list<array{account_id: int|string, period_month: string, amount: int|string|float}>  $lines
     * @return list<array{account_id: int, period_month: string, amount: string}>
     */
    private function normalizeLines(Budget $budget, array $lines): array
    {
        $allowed = array_flip($this->months($budget));
        $keyed = [];
        foreach ($lines as $line) {
            $month = Carbon::parse((string) $line['period_month'])->toDateString();
            if (! isset($allowed[$month])) {
                abort(422, 'A budget month is outside this fiscal year.', [
                    'X-Error-Code' => 'BUDGET_MONTH_OUT_OF_RANGE',
                ]);
            }
            $accountId = (int) $line['account_id'];
            $keyed[$accountId.'|'.$month] = [
                'account_id' => $accountId,
                'period_month' => $month,
                'amount' => JournalMath::store($line['amount']),
            ];
        }

        $normalized = array_values($keyed);
        $this->assertPostable(array_map(fn (array $line): int => $line['account_id'], $normalized));

        return $normalized;
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function assertPostable(array $accountIds): void
    {
        $ids = array_values(array_unique($accountIds));
        if ($ids === []) {
            return;
        }

        $found = $this->postableIds($ids);
        if (count($found) !== count($ids)) {
            abort(422, 'Budgets are entered on active posting accounts only.', [
                'X-Error-Code' => 'BUDGET_ACCOUNT_INVALID',
            ]);
        }
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, true>
     */
    private function postableIds(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $ids = Account::query()
            ->whereIn('id', array_values(array_unique($accountIds)))
            ->where('is_group', false)
            ->where('is_active', true)
            ->pluck('id');

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * @param  list<array{account_id: int, period_month: string, amount: string}>  $lines
     */
    private function upsertLines(Budget $budget, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = [
                'budget_id' => $budget->id,
                'account_id' => $line['account_id'],
                'period_month' => $line['period_month'],
                'amount' => $line['amount'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BudgetLine::query()->upsert(
                $chunk,
                ['budget_id', 'account_id', 'period_month'],
                ['amount', 'updated_at']
            );
        }
    }

    private function scaleAmount(mixed $amount, mixed $percent): string
    {
        if ($percent === null || $percent === '' || JournalMath::isZero($percent)) {
            return JournalMath::store($amount);
        }

        $factor = bcadd('1', bcdiv(JournalMath::of($percent), '100', JournalMath::WORK_SCALE), JournalMath::WORK_SCALE);

        return JournalMath::store(bcmul(JournalMath::of($amount), $factor, JournalMath::WORK_SCALE));
    }

    /**
     * @param  array<int, int|null>  $parents
     * @param  array<int, array<string, string>>  $own
     * @return array<int, array<string, string>>
     */
    private function rollAmounts(array $parents, array $own): array
    {
        $out = [];
        foreach ($own as $accountId => $months) {
            $id = $accountId;
            $guard = 0;
            while ($id !== null && array_key_exists($id, $parents) && $guard++ < 32) {
                foreach ($months as $month => $amount) {
                    $out[$id][$month] = JournalMath::store(JournalMath::add($out[$id][$month] ?? '0', $amount));
                }
                $id = $parents[$id];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int|null>  $parents
     * @return array<int, int>
     */
    private function depths(array $parents): array
    {
        $depths = [];
        foreach ($parents as $id => $parent) {
            $depth = 1;
            $cursor = $parent;
            $guard = 0;
            while ($cursor !== null && $guard++ < 32) {
                $depth++;
                $cursor = $parents[$cursor] ?? null;
            }
            $depths[$id] = $depth;
        }

        return $depths;
    }

    private function cellText(mixed $value): string
    {
        if (is_float($value) && floor($value) == $value) {
            return (string) (int) $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return trim((string) $value);
    }

    private function monthFromCell(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->startOfMonth()->toDateString();
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            if ($number > 20000 && $number < 100000) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($number))->startOfMonth()->toDateString();
            }
        }

        $text = str_replace('_', '-', trim((string) $value));
        if (preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', $text, $match) !== 1) {
            return null;
        }
        if (($match[3] ?? '01') !== '01') {
            return null;
        }

        return sprintf('%s-%s-01', $match[1], $match[2]);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
