<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Budget\Models\Budget;
use App\Modules\Accounting\Budget\Models\BudgetLine;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Reports\Support\AnalyticsReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Budget vs actual for one budget over a period inside its fiscal year.
 *
 * Amounts use the account's natural sign (revenue = credit − debit, expense = debit − credit).
 * Variance = actual − budget; "favourable" means under budget for expenses, over budget for revenue.
 * Actuals skip the year-end closing entry and use the budget's branch (all branches for a company budget).
 */
class BudgetReportService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(string $budgetId, ?string $from, ?string $to, ?int $level): array
    {
        $budget = Budget::query()->find($budgetId);
        if ($budget === null) {
            abort(422, 'Choose a budget.', ['X-Error-Code' => 'REPORT_BUDGET_REQUIRED']);
        }

        $yearStart = Carbon::parse($budget->fiscal_year_start)->startOfDay();
        $yearEnd = Carbon::parse($budget->fiscal_year_end)->startOfDay();
        $start = $from !== null ? Carbon::parse($from)->startOfDay() : $yearStart->copy();
        $end = $to !== null ? Carbon::parse($to)->startOfDay() : $yearEnd->copy();
        // The period always stays inside the budget's fiscal year.
        $start = $start->lessThan($yearStart) ? $yearStart->copy() : $start;
        $end = $end->greaterThan($yearEnd) ? $yearEnd->copy() : $end;
        if ($end->lessThan($start)) {
            $end = $start->copy();
        }

        /** @var Collection<int, Account> $accounts */
        $accounts = Account::query()->orderBy('code')->get();
        $parents = [];
        foreach ($accounts as $account) {
            $parents[(int) $account->id] = $account->parent_id !== null ? (int) $account->parent_id : null;
        }
        $types = $accounts->mapWithKeys(fn (Account $a): array => [(int) $a->id => $a->type])->all();
        $isPnl = fn (?AccountType $type): bool => $type === AccountType::Revenue || $type === AccountType::Expense;

        // Budget: whole months that fall in the period; full year for the "remaining" column.
        $periodBudget = [];
        $yearBudget = [];
        $firstMonth = $start->copy()->startOfMonth()->toDateString();
        $lastMonth = $end->copy()->startOfMonth()->toDateString();
        foreach (BudgetLine::query()->where('budget_id', $budget->id)->get(['account_id', 'period_month', 'amount']) as $line) {
            $id = (int) $line->account_id;
            if (! $isPnl($types[$id] ?? null)) {
                continue;
            }
            $amount = (string) $line->amount;
            $yearBudget[$id] = JournalMath::add($yearBudget[$id] ?? '0', $amount);
            $month = $line->period_month->toDateString();
            if ($month >= $firstMonth && $month <= $lastMonth) {
                $periodBudget[$id] = JournalMath::add($periodBudget[$id] ?? '0', $amount);
            }
        }

        // Actuals from the ledger, in the account's natural sign.
        $filters = ['exclude_source_types' => [JournalEntry::SOURCE_YEAR_END_CLOSE]];
        if ($budget->branch_id !== null) {
            $filters['branch_id'] = (int) $budget->branch_id;
        }
        $actual = [];
        foreach ($this->balances->totals($start->toDateString(), $end->toDateString(), null, $filters) as $id => $sum) {
            $type = $types[(int) $id] ?? null;
            if (! $isPnl($type)) {
                continue;
            }
            $actual[(int) $id] = $type === AccountType::Revenue
                ? JournalMath::sub($sum['credit'] ?? '0', $sum['debit'] ?? '0')
                : JournalMath::sub($sum['debit'] ?? '0', $sum['credit'] ?? '0');
        }

        $rolledBudget = $this->rollUp($parents, $periodBudget);
        $rolledYear = $this->rollUp($parents, $yearBudget);
        $rolledActual = $this->rollUp($parents, $actual);
        $depths = $this->depths($parents);
        $maxLevel = $level !== null && $level > 0 ? $level : null;

        $rows = [];
        foreach ($accounts as $account) {
            $id = (int) $account->id;
            if (! isset($rolledBudget[$id]) && ! isset($rolledActual[$id]) && ! isset($rolledYear[$id])) {
                continue;
            }
            $depth = $depths[$id] ?? 1;
            if ($maxLevel !== null && $depth > $maxLevel) {
                continue;
            }
            $budgetAmount = JournalMath::store($rolledBudget[$id] ?? '0');
            $actualAmount = JournalMath::store($rolledActual[$id] ?? '0');
            $yearAmount = JournalMath::store($rolledYear[$id] ?? '0');
            $rows[] = $this->row($account, $depth, $budgetAmount, $actualAmount, $yearAmount, $maxLevel !== null && $depth === $maxLevel);
        }

        $summary = $this->summary($accounts, $periodBudget, $actual, $yearBudget);

        return AnalyticsReport::make(
            [
                ['account_code', 'text'], ['account_name', 'text'],
                ['budget', 'money'], ['actual', 'money'], ['variance', 'money'], ['variance_percent', 'percent'],
                ['year_budget', 'money'], ['remaining', 'money'],
            ],
            $rows,
            null,
            [
                'budget_id' => (string) $budget->id,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'branch_id' => $budget->branch_id !== null ? (int) $budget->branch_id : null,
                'level' => $maxLevel,
                'summary' => $summary,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Account $account, int $depth, string $budget, string $actual, string $year, bool $collapsed): array
    {
        $variance = JournalMath::store(JournalMath::sub($actual, $budget));
        $revenue = $account->type === AccountType::Revenue;

        return [
            'account_id' => (int) $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'account_type' => $account->type?->value,
            'level' => $depth,
            // A group, or an account shown as a roll-up because of the chosen level.
            'is_group' => (bool) $account->is_group || $collapsed,
            'budget' => $budget,
            'actual' => $actual,
            'variance' => $variance,
            'variance_percent' => JournalMath::isZero($budget) ? null : $this->percent($variance, $budget),
            'used_percent' => JournalMath::isZero($budget) ? null : $this->percent($actual, $budget),
            'year_budget' => $year,
            'remaining' => JournalMath::store(JournalMath::sub($year, $actual)),
            'favourable' => $revenue ? ! JournalMath::isNegative($variance) : ! JournalMath::isPositive($variance),
        ];
    }

    /**
     * Revenue, expense and net (revenue − expense) over postable accounts, so groups are not counted twice.
     *
     * @param  iterable<Account>  $accounts
     * @param  array<int, string>  $budget
     * @param  array<int, string>  $actual
     * @param  array<int, string>  $year
     * @return array<string, array{budget: string, actual: string, variance: string, year_budget: string, favourable: bool}>
     */
    private function summary(iterable $accounts, array $budget, array $actual, array $year): array
    {
        $sum = ['revenue' => ['0', '0', '0'], 'expense' => ['0', '0', '0']];
        foreach ($accounts as $account) {
            $key = $account->type?->value;
            if ($key !== 'revenue' && $key !== 'expense') {
                continue;
            }
            $id = (int) $account->id;
            $sum[$key][0] = JournalMath::add($sum[$key][0], $budget[$id] ?? '0');
            $sum[$key][1] = JournalMath::add($sum[$key][1], $actual[$id] ?? '0');
            $sum[$key][2] = JournalMath::add($sum[$key][2], $year[$id] ?? '0');
        }
        $make = function (string $b, string $a, string $y, bool $higherIsBetter): array {
            $variance = JournalMath::sub($a, $b);

            return [
                'budget' => JournalMath::store($b),
                'actual' => JournalMath::store($a),
                'variance' => JournalMath::store($variance),
                'year_budget' => JournalMath::store($y),
                'favourable' => $higherIsBetter ? ! JournalMath::isNegative($variance) : ! JournalMath::isPositive($variance),
            ];
        };

        return [
            'revenue' => $make($sum['revenue'][0], $sum['revenue'][1], $sum['revenue'][2], true),
            'expense' => $make($sum['expense'][0], $sum['expense'][1], $sum['expense'][2], false),
            'net' => $make(
                JournalMath::sub($sum['revenue'][0], $sum['expense'][0]),
                JournalMath::sub($sum['revenue'][1], $sum['expense'][1]),
                JournalMath::sub($sum['revenue'][2], $sum['expense'][2]),
                true,
            ),
        ];
    }

    private function percent(string $part, string $whole): string
    {
        return JournalMath::store(bcdiv(bcmul(JournalMath::of($part), '100', JournalMath::WORK_SCALE), JournalMath::of($whole), JournalMath::WORK_SCALE));
    }

    /**
     * @param  array<int, int|null>  $parents
     * @param  array<int, string>  $own
     * @return array<int, string>
     */
    private function rollUp(array $parents, array $own): array
    {
        $out = [];
        foreach ($own as $accountId => $amount) {
            $id = $accountId;
            $guard = 0;
            while ($id !== null && array_key_exists($id, $parents) && $guard++ < 32) {
                $out[$id] = JournalMath::add($out[$id] ?? '0', $amount);
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
}
