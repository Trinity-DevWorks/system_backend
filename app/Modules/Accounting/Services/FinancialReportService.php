<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\PartySubledger;
use App\Modules\Accounting\Support\ReportAmounts;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Accounting\Support\ReportLayout;
use App\Modules\Inventory\Stock\Models\StockBalance;
use Illuminate\Support\Collection;

class FinancialReportService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly ReportLayout $layout,
        private readonly FiscalCalendar $fiscal,
        private readonly AccountMappingService $mappings,
        private readonly PartySubledger $parties,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function incomeStatement(?string $from, ?string $to, ?int $branchId, ?int $level, string $compare): array
    {
        $defaults = $this->fiscal->defaultPeriod();
        $from = $from ?? $defaults['from'];
        $to = $to ?? $defaults['to'];
        $comparePeriod = $this->comparePeriod($compare, $from, $to);
        $scoped = $this->branchScope->apply($branchId);
        $branchId = $scoped['branch_id'];
        $filters = $scoped['filters'];

        $accounts = Account::query()->orderBy('code')->get();
        $current = $this->balances->totals($from, $to, null, $filters);
        $compared = $comparePeriod !== null
            ? $this->balances->totals($comparePeriod['from'], $comparePeriod['to'], null, $filters)
            : null;

        $built = $this->buildSections(
            $this->layout->incomeStatement(),
            $accounts,
            $current,
            $compared,
            [AccountType::Revenue, AccountType::Expense],
            $level,
            [],
        );
        $this->foldUnclassifiedIntoNetResult($built);

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'level' => $level,
            'compare' => $comparePeriod === null ? 'none' : $compare,
            'period' => ['from' => $from, 'to' => $to],
            'compare_period' => $comparePeriod,
            'sections' => $built['sections'],
            'unclassified' => $built['unclassified'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceSheet(?string $asOf, ?string $compareAsOf, ?int $level): array
    {
        $asOf = $asOf ?? $this->fiscal->today();
        $accounts = Account::query()->orderBy('code')->get();
        $snapshot = $this->balances->totals(null, $asOf);
        $compareSnapshot = $compareAsOf !== null ? $this->balances->totals(null, $compareAsOf) : null;

        $currentResult = $this->resultSplit($accounts, $asOf);
        $compareResult = $compareAsOf !== null ? $this->resultSplit($accounts, $compareAsOf) : null;
        $computed = $this->computedRows($currentResult, $compareResult);

        $assets = $this->buildSections(
            $this->layout->balanceSheet('assets'),
            $accounts,
            $snapshot,
            $compareSnapshot,
            [AccountType::Asset],
            $level,
            [],
        );
        $equity = $this->buildSections(
            $this->layout->balanceSheet('equity_and_liabilities'),
            $accounts,
            $snapshot,
            $compareSnapshot,
            [AccountType::Liability, AccountType::Equity],
            $level,
            $computed,
        );

        $assetTotal = $this->sideTotal($assets);
        $equityTotal = $this->sideTotal($equity);
        $assetCompare = $compareAsOf !== null ? $this->sideTotal($assets, true) : null;
        $equityCompare = $compareAsOf !== null ? $this->sideTotal($equity, true) : null;

        $unclassifiedRows = array_merge($assets['unclassified']['rows'], $equity['unclassified']['rows']);

        return [
            'as_of' => $asOf,
            'compare_as_of' => $compareAsOf,
            'level' => $level,
            'assets' => [
                'sections' => $assets['sections'],
                'total' => $assetTotal,
                'compare_total' => $assetCompare,
            ],
            'equity_and_liabilities' => [
                'sections' => $equity['sections'],
                'total' => $equityTotal,
                'compare_total' => $equityCompare,
            ],
            'total_assets' => $assetTotal,
            'compare_total_assets' => $assetCompare,
            'total_equity_and_liabilities' => $equityTotal,
            'compare_total_equity_and_liabilities' => $equityCompare,
            'difference' => JournalMath::store(JournalMath::sub($assetTotal, $equityTotal)),
            'compare_difference' => $assetCompare !== null && $equityCompare !== null
                ? JournalMath::store(JournalMath::sub($assetCompare, $equityCompare))
                : null,
            'unclassified' => ['rows' => $unclassifiedRows],
            'checks' => $this->checks($snapshot, $asOf),
        ];
    }

    /**
     * Profit of every revenue and expense account, including ones the layout
     * does not name. After a year-end close those balances sit on
     * 1210 / 1250 and both figures fall to zero on their own.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{current: string, prior: string}
     */
    private function resultSplit(Collection $accounts, string $asOf): array
    {
        $start = $this->fiscal->fiscalYearStart($asOf);

        return [
            'current' => $this->profit($accounts, $this->balances->totals($start, $asOf)),
            'prior' => $this->profit($accounts, $this->balances->totals(null, null, $start)),
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, array{debit: string, credit: string}>  $totals
     */
    private function profit(Collection $accounts, array $totals): string
    {
        $profit = '0';
        foreach ($accounts as $account) {
            if ($account->is_group || $account->type === null || ! isset($totals[$account->id])) {
                continue;
            }
            $natural = ReportAmounts::natural($account->type, $totals[$account->id]);
            $profit = match ($account->type) {
                AccountType::Revenue => JournalMath::add($profit, $natural),
                AccountType::Expense => JournalMath::sub($profit, $natural),
                default => $profit,
            };
        }

        return JournalMath::store($profit);
    }

    /**
     * @param  array{current: string, prior: string}  $current
     * @param  array{current: string, prior: string}|null  $compare
     * @return array<string, list<array<string, mixed>>>
     */
    private function computedRows(array $current, ?array $compare): array
    {
        $rows = [];
        foreach (['unclosed_prior_years_result' => 'prior', 'current_year_result' => 'current'] as $key => $field) {
            $amount = $current[$field];
            $compareAmount = $compare !== null ? $compare[$field] : null;
            $rows[] = $this->row(null, null, null, null, 1, $amount, $compareAmount, $key);
        }

        return ['equity' => $rows];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, array{debit: string, credit: string}>  $currentTotals
     * @param  array<int, array{debit: string, credit: string}>|null  $compareTotals
     * @param  list<AccountType>  $types
     * @param  array<string, list<array<string, mixed>>>  $computedBySection
     * @return array{sections: list<array<string, mixed>>, unclassified: array{rows: list<array<string, mixed>>, effect: string, compare_effect: ?string}}
     */
    private function buildSections(
        array $sections,
        Collection $accounts,
        array $currentTotals,
        ?array $compareTotals,
        array $types,
        ?int $level,
        array $computedBySection,
    ): array {
        $comparing = $compareTotals !== null;
        $depths = $this->depths($accounts);
        $currentRows = $this->visible($accounts, $currentTotals, $types, $level, $depths);
        $compareRows = $comparing ? $this->visible($accounts, $compareTotals, $types, $level, $depths) : [];

        /** @var array<string, list<array<string, mixed>>> $buckets */
        $buckets = [];
        $unclassified = [];
        $effect = '0';
        $compareEffect = $comparing ? '0' : null;

        $ids = array_unique([...array_keys($currentRows), ...array_keys($compareRows)]);
        sort($ids);
        $ordered = [];
        foreach ($ids as $id) {
            $ordered[$id] = $currentRows[$id]['account'] ?? $compareRows[$id]['account'];
        }
        uasort($ordered, fn (Account $a, Account $b): int => strcmp((string) $a->code, (string) $b->code));

        foreach ($ordered as $id => $account) {
            $amount = $currentRows[$id]['amount'] ?? '0.0000';
            $compareAmount = $comparing ? ($compareRows[$id]['amount'] ?? '0.0000') : null;
            if (ReportAmounts::zero($amount) && ReportAmounts::zero($compareAmount)) {
                continue;
            }
            $depth = $currentRows[$id]['depth'] ?? $compareRows[$id]['depth'] ?? 1;
            $row = $this->row(
                (int) $account->id,
                (string) $account->code,
                (string) $account->name,
                $account->name_ar !== null && trim((string) $account->name_ar) !== '' ? (string) $account->name_ar : null,
                $depth,
                $amount,
                $compareAmount,
                null,
            );
            $section = $this->layout->match($account, $sections);
            if ($section === null) {
                $unclassified[] = $row;
                $signed = $account->type === AccountType::Expense ? JournalMath::neg($amount) : $amount;
                $effect = JournalMath::add($effect, $signed);
                if ($comparing) {
                    $signedCompare = $account->type === AccountType::Expense ? JournalMath::neg($compareAmount ?? '0') : ($compareAmount ?? '0');
                    $compareEffect = JournalMath::add($compareEffect ?? '0', $signedCompare);
                }

                continue;
            }
            $buckets[$section][] = $row;
        }

        $amounts = [];
        $compareAmounts = [];
        $out = [];
        foreach ($sections as $section) {
            $key = (string) $section['key'];
            $isTotal = isset($section['total_of']) && is_array($section['total_of']);
            if ($isTotal) {
                $amount = $this->combine($section['total_of'], $amounts);
                $compareAmount = $comparing ? $this->combine($section['total_of'], $compareAmounts) : null;
            } else {
                $rows = $buckets[$key] ?? [];
                $amount = $this->sumColumn($rows, 'amount');
                $compareAmount = $comparing ? $this->sumColumn($rows, 'compare_amount') : null;
                foreach ($computedBySection[$key] ?? [] as $computed) {
                    $rows[] = $computed;
                    $amount = JournalMath::add($amount, $computed['amount']);
                    if ($comparing) {
                        $compareAmount = JournalMath::add($compareAmount ?? '0', $computed['compare_amount'] ?? '0');
                    }
                }
            }
            $amount = JournalMath::store($amount);
            $compareAmount = $compareAmount !== null ? JournalMath::store($compareAmount) : null;
            $amounts[$key] = $amount;
            if ($comparing) {
                $compareAmounts[$key] = $compareAmount;
            }
            $payload = [
                'key' => $key,
                'is_total' => $isTotal,
                'amount' => $amount,
                'compare_amount' => $compareAmount,
                'change' => ReportAmounts::change($amount, $compareAmount),
            ];
            if (! $isTotal) {
                $payload['rows'] = $buckets[$key] ?? [];
                if (isset($computedBySection[$key])) {
                    $payload['rows'] = array_merge($payload['rows'], $computedBySection[$key]);
                }
            }
            $out[] = $payload;
        }

        return [
            'sections' => $out,
            'unclassified' => [
                'rows' => $unclassified,
                'effect' => JournalMath::store($effect),
                'compare_effect' => $compareEffect !== null ? JournalMath::store($compareEffect) : null,
            ],
        ];
    }

    /**
     * Unclassified profit and loss still belongs in the net result, otherwise
     * the balance sheet current-year row would not match this statement.
     *
     * @param  array{sections: list<array<string, mixed>>, unclassified: array{rows: list<array<string, mixed>>, effect: string, compare_effect: ?string}}  $built
     *
     * @param-out array{sections: list<array<string, mixed>>, unclassified: array{rows: list<array<string, mixed>>}}  $built
     */
    private function foldUnclassifiedIntoNetResult(array &$built): void
    {
        $effect = $built['unclassified']['effect'];
        $compareEffect = $built['unclassified']['compare_effect'];
        if (ReportAmounts::zero($effect) && ReportAmounts::zero($compareEffect)) {
            unset($built['unclassified']['effect'], $built['unclassified']['compare_effect']);

            return;
        }
        foreach ($built['sections'] as &$section) {
            if ($section['key'] !== 'net_result') {
                continue;
            }
            $section['amount'] = JournalMath::store(JournalMath::add($section['amount'], $effect));
            if ($section['compare_amount'] !== null && $compareEffect !== null) {
                $section['compare_amount'] = JournalMath::store(JournalMath::add($section['compare_amount'], $compareEffect));
            }
            $section['change'] = ReportAmounts::change($section['amount'], $section['compare_amount']);
        }
        unset($section);
        unset($built['unclassified']['effect'], $built['unclassified']['compare_effect']);
    }

    /**
     * @param  array{sections: list<array<string, mixed>>, unclassified: array{rows: list<array<string, mixed>>}}  $side
     */
    private function sideTotal(array $side, bool $compare = false): string
    {
        $column = $compare ? 'compare_amount' : 'amount';
        $total = '0';
        foreach ($side['sections'] as $section) {
            $total = JournalMath::add($total, $section[$column] ?? '0');
        }
        foreach ($side['unclassified']['rows'] as $row) {
            $total = JournalMath::add($total, $row[$column] ?? '0');
        }

        return JournalMath::store($total);
    }

    /**
     * @param  array<int, array{debit: string, credit: string}>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function checks(array $snapshot, string $asOf): array
    {
        $inventory = $this->mappings->accountFor(AccountRole::Inventory);
        $receivable = $this->mappings->accountFor(AccountRole::Receivable);
        $payable = $this->mappings->accountFor(AccountRole::Payable);

        $inventoryLedger = ReportAmounts::natural($inventory->type, $snapshot[$inventory->id] ?? []);
        $stock = null;
        $stockDifference = null;
        if ($asOf === $this->fiscal->today()) {
            $stock = JournalMath::store(StockBalance::query()->sum('inventory_value'));
            $stockDifference = JournalMath::store(JournalMath::sub($inventoryLedger, $stock));
        }

        $receivableLedger = ReportAmounts::natural($receivable->type, $snapshot[$receivable->id] ?? []);
        $customerParties = $this->parties->customers($asOf);
        $payableLedger = ReportAmounts::natural($payable->type, $snapshot[$payable->id] ?? []);
        $supplierParties = $this->parties->suppliers($asOf);

        return [
            'inventory' => [
                'account_id' => (int) $inventory->id,
                'ledger' => $inventoryLedger,
                'stock' => $stock,
                'difference' => $stockDifference,
            ],
            'receivables' => [
                'account_id' => (int) $receivable->id,
                'ledger' => $receivableLedger,
                'parties' => $customerParties,
                'difference' => JournalMath::store(JournalMath::sub($receivableLedger, $customerParties)),
            ],
            'payables' => [
                'account_id' => (int) $payable->id,
                'ledger' => $payableLedger,
                'parties' => $supplierParties,
                'difference' => JournalMath::store(JournalMath::sub($payableLedger, $supplierParties)),
            ],
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, array{debit: string, credit: string}>  $totals
     * @param  list<AccountType>  $types
     * @param  array<int, int>  $depths
     * @return array<int, array{account: Account, amount: string, depth: int}>
     */
    private function visible(Collection $accounts, array $totals, array $types, ?int $level, array $depths): array
    {
        if ($level !== null) {
            $totals = $this->balances->rollUp($accounts, $totals);
        }
        $allowed = array_map(fn (AccountType $type): string => $type->value, $types);
        $out = [];
        foreach ($accounts as $account) {
            $type = $account->type?->value;
            if ($type === null || ! in_array($type, $allowed, true)) {
                continue;
            }
            $depth = $depths[(int) $account->id] ?? 1;
            $show = $level === null
                ? ! $account->is_group
                : ($depth === $level || ($depth < $level && ! $account->is_group));
            if (! $show) {
                continue;
            }
            $out[(int) $account->id] = [
                'account' => $account,
                'amount' => ReportAmounts::natural($account->type, $totals[(int) $account->id] ?? []),
                'depth' => $depth,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $parts
     * @param  array<string, string>  $amounts
     */
    private function combine(array $parts, array $amounts): string
    {
        $total = '0';
        foreach ($parts as $part) {
            $part = (string) $part;
            $negative = str_starts_with($part, '-');
            $key = ltrim($part, '-');
            $piece = $amounts[$key] ?? '0';
            $total = $negative ? JournalMath::sub($total, $piece) : JournalMath::add($total, $piece);
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sumColumn(array $rows, string $column): string
    {
        $total = '0';
        foreach ($rows as $row) {
            $total = JournalMath::add($total, $row[$column] ?? '0');
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?int $accountId, ?string $code, ?string $name, ?string $nameAr, int $depth, string $amount, ?string $compareAmount, ?string $computedKey): array
    {
        $row = [
            'account_id' => $accountId,
            'code' => $code,
            'name' => $name,
            'name_ar' => $nameAr,
            'depth' => $depth,
            'amount' => JournalMath::store($amount),
            'compare_amount' => $compareAmount !== null ? JournalMath::store($compareAmount) : null,
            'change' => ReportAmounts::change(JournalMath::store($amount), $compareAmount !== null ? JournalMath::store($compareAmount) : null),
        ];
        if ($computedKey !== null) {
            $row['key'] = $computedKey;
            $row['computed'] = true;
        }

        return $row;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<int, int>
     */
    private function depths(Collection $accounts): array
    {
        $parents = [];
        foreach ($accounts as $account) {
            $parents[(int) $account->id] = $account->parent_id !== null ? (int) $account->parent_id : null;
        }
        $depths = [];
        foreach ($parents as $id => $parent) {
            $depth = 1;
            $cursor = $parent;
            while ($cursor !== null && $depth < 32) {
                $depth++;
                $cursor = $parents[$cursor] ?? null;
            }
            $depths[$id] = $depth;
        }

        return $depths;
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function comparePeriod(string $compare, string $from, string $to): ?array
    {
        return match ($compare) {
            'previous_period' => $this->fiscal->previousPeriod($from, $to),
            'previous_year' => $this->fiscal->previousYear($from, $to),
            default => null,
        };
    }
}
