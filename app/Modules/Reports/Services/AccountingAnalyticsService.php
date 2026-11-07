<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Reports\Support\AnalyticsReport;
use App\Modules\Reports\Support\SqlReportPage;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cash flow statement (direct method) and general ledger detail, from posted journal lines
 * (primary currency). Both use the same branch scope as the other accounting reports.
 */
class AccountingAnalyticsService
{
    /** Display order of the cash flow categories. */
    private const CATEGORIES = ['customers', 'suppliers', 'expenses', 'other_income', 'vat', 'loans', 'equity', 'assets', 'exchange', 'other'];

    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly ReportBranchScope $branches,
        private readonly AccountBalanceService $balances,
    ) {}

    /**
     * Cash in and out of the cash & bank accounts, grouped by what the money was for.
     * Each journal entry's net cash movement is shared among its other (non-cash) lines
     * in proportion to their amounts; moves between two cash accounts are left out.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(?string $from, ?string $to, ?int $branchId): array
    {
        $defaults = $this->fiscal->currentMonthToDate();
        $from ??= $defaults['from'];
        $to ??= $defaults['to'];
        $scope = $this->branches->apply($branchId);
        $cashIds = $this->cashAccountIds();

        $opening = '0';
        $closingChange = '0';
        $buckets = array_fill_keys(self::CATEGORIES, ['in' => '0', 'out' => '0']);

        if ($cashIds !== []) {
            $openingQuery = $this->balances->linesQuery()->whereIn('l.account_id', $cashIds)->where('je.entry_date', '<', $from);
            $this->balances->applyBranch($openingQuery, $scope['filters']);
            $opening = Decimal::of($openingQuery->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as net')->value('net') ?? '0');

            $entryIds = $this->balances->linesQuery()
                ->whereIn('l.account_id', $cashIds)
                ->whereBetween('je.entry_date', [$from, $to])
                ->tap(fn ($q) => $this->balances->applyBranch($q, $scope['filters']))
                ->distinct()
                ->pluck('je.id');

            $lines = DB::table('journal_entry_lines as l')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->whereIn('l.journal_entry_id', $entryIds)
                ->get(['l.journal_entry_id', 'l.account_id', 'l.debit', 'l.credit', 'a.code', 'a.type'])
                ->groupBy('journal_entry_id');

            $roles = $this->roleByAccount();
            foreach ($lines as $entryLines) {
                $cash = '0';
                $others = [];
                foreach ($entryLines as $line) {
                    $net = Decimal::sub($line->debit, $line->credit);
                    if (in_array((int) $line->account_id, $cashIds, true)) {
                        $cash = Decimal::add($cash, $net);
                    } else {
                        $others[] = ['category' => $this->category((int) $line->account_id, (string) $line->code, (string) $line->type, $roles), 'weight' => Decimal::abs($net)];
                    }
                }
                $closingChange = Decimal::add($closingChange, $cash);
                if (Decimal::isZero($cash, 4) || $others === []) {
                    continue; // between cash accounts
                }
                $weight = array_reduce($others, fn (string $c, array $o): string => Decimal::add($c, $o['weight']), '0');
                foreach ($others as $other) {
                    $share = Decimal::isPositive($weight) ? Decimal::div(Decimal::mul($cash, $other['weight']), $weight) : '0';
                    $side = Decimal::isPositive($share) ? 'in' : 'out';
                    $buckets[$other['category']][$side] = Decimal::add($buckets[$other['category']][$side], Decimal::abs($share));
                }
            }
        }

        $rows = [['row_kind' => 'opening', 'category' => 'opening_cash', 'net' => Decimal::round($opening, 4)]];
        $in = '0';
        $out = '0';
        foreach (self::CATEGORIES as $category) {
            $b = $buckets[$category];
            if (Decimal::isZero($b['in'], 4) && Decimal::isZero($b['out'], 4)) {
                continue;
            }
            $in = Decimal::add($in, $b['in']);
            $out = Decimal::add($out, $b['out']);
            $rows[] = [
                'category' => $category,
                'cash_in' => Decimal::round($b['in'], 4),
                'cash_out' => Decimal::round($b['out'], 4),
                'net' => Decimal::round(Decimal::sub($b['in'], $b['out']), 4),
            ];
        }
        $rows[] = ['row_kind' => 'closing', 'category' => 'closing_cash', 'net' => Decimal::round(Decimal::add($opening, $closingChange), 4)];

        return AnalyticsReport::make(
            [['category', 'text'], ['cash_in', 'money'], ['cash_out', 'money'], ['net', 'money']],
            $rows,
            ['cash_in' => Decimal::round($in, 4), 'cash_out' => Decimal::round($out, 4), 'net' => Decimal::round(Decimal::sub($in, $out), 4)],
            ['from' => $from, 'to' => $to, 'branch_id' => $scope['branch_id']],
        );
    }

    /**
     * Every account (optionally a code range) with opening balance, its lines, running balance and closing.
     *
     * @return array<string, mixed>
     */
    public function generalLedger(?string $from, ?string $to, ?int $branchId, ?string $codeFrom, ?string $codeTo, array $list = []): array
    {
        $defaults = $this->fiscal->currentMonthToDate();
        $from ??= $defaults['from'];
        $to ??= $defaults['to'];
        $scope = $this->branches->apply($branchId);

        if (! empty($list['per_page'])) {
            return $this->generalLedgerPage($from, $to, $scope, $codeFrom, $codeTo, $list);
        }

        $accounts = Account::query()
            ->where('is_group', false)
            ->when($codeFrom !== null, fn ($q) => $q->where('code', '>=', $codeFrom))
            ->when($codeTo !== null, fn ($q) => $q->where('code', '<=', $codeTo))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $openings = $this->balances->totals(null, null, $from, $scope['filters']);

        $linesQuery = $this->balances->linesQuery()
            ->whereIn('l.account_id', $accounts->keys()->all())
            ->whereBetween('je.entry_date', [$from, $to])
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_number')
            ->orderBy('l.sort_order')
            ->orderBy('l.id');
        $this->balances->applyBranch($linesQuery, $scope['filters']);
        $lines = $linesQuery->get(['l.account_id', 'l.debit', 'l.credit', 'l.description', 'je.id as journal_entry_id', 'je.entry_number', 'je.entry_date', 'je.source_type', 'je.source_id', 'je.description as entry_description'])
            ->groupBy('account_id');

        $numbers = SourceDocuments::numbers($lines->flatten(1)->map(fn ($l): array => [(string) $l->source_type, $l->source_type === 'manual' ? null : $l->source_id]));

        $rows = [];
        $debitTotal = '0';
        $creditTotal = '0';
        foreach ($accounts as $id => $account) {
            $opening = isset($openings[$id]) ? AccountBalanceService::net($openings[$id]) : '0';
            $accountLines = $lines->get($id, collect());
            if ($accountLines->isEmpty() && Decimal::isZero($opening, 4)) {
                continue;
            }
            $label = $account->code.' — '.$account->name;
            $rows[] = ['row_kind' => 'opening', 'account' => $label, 'description' => 'opening_balance', 'balance' => Decimal::round($opening, 4)];
            $running = $opening;
            $accDebit = '0';
            $accCredit = '0';
            foreach ($accountLines as $line) {
                $running = Decimal::add($running, Decimal::sub($line->debit, $line->credit));
                $accDebit = Decimal::add($accDebit, $line->debit);
                $accCredit = Decimal::add($accCredit, $line->credit);
                $rows[] = [
                    'account' => $label,
                    'date' => Carbon::parse((string) $line->entry_date)->toDateString(),
                    'entry' => $line->entry_number,
                    'document' => $numbers[$line->source_type.':'.$line->source_id] ?? null,
                    'description' => $line->description ?? $line->entry_description,
                    'debit' => Decimal::round($line->debit, 4),
                    'credit' => Decimal::round($line->credit, 4),
                    'balance' => Decimal::round($running, 4),
                    ...self::lineLinks($line->journal_entry_id, $line->source_type, $line->source_id),
                ];
            }
            $rows[] = [
                'row_kind' => 'closing', 'account' => $label, 'description' => 'closing_balance',
                'debit' => Decimal::round($accDebit, 4), 'credit' => Decimal::round($accCredit, 4), 'balance' => Decimal::round($running, 4),
            ];
            $debitTotal = Decimal::add($debitTotal, $accDebit);
            $creditTotal = Decimal::add($creditTotal, $accCredit);
        }

        return AnalyticsReport::make(
            [['account', 'text'], ['date', 'date'], ['entry', 'text'], ['document', 'text'], ['description', 'text'], ['debit', 'money'], ['credit', 'money'], ['balance', 'money']],
            $rows,
            ['debit' => Decimal::round($debitTotal, 4), 'credit' => Decimal::round($creditTotal, 4)],
            ['from' => $from, 'to' => $to, 'branch_id' => $scope['branch_id'], 'account_from' => $codeFrom, 'account_to' => $codeTo],
        );
    }

    /**
     * General ledger page for the screen: the same rows as generalLedger() (opening, lines with running
     * balance, closing per account), built and paged in SQL. Search matches the account code or name.
     * Rows keep their statement order, so there is no column sort.
     *
     * @param  array{branch_id: ?int, filters: array<string, mixed>}  $scope
     * @param  array{search?: ?string, page?: ?int, per_page?: ?int}  $list
     * @return array<string, mixed>
     */
    private function generalLedgerPage(string $from, string $to, array $scope, ?string $codeFrom, ?string $codeTo, array $list): array
    {
        $search = trim((string) ($list['search'] ?? ''));

        $accounts = DB::table('accounts as a')
            ->where('a.is_group', false)
            ->when($codeFrom !== null, fn ($q) => $q->where('a.code', '>=', $codeFrom))
            ->when($codeTo !== null, fn ($q) => $q->where('a.code', '<=', $codeTo))
            ->when($search !== '', function ($q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('a.code', 'ilike', $like)->orWhere('a.name', 'ilike', $like));
            })
            ->select('a.id', 'a.code', 'a.name');

        $openings = $this->balances->linesQuery()->where('je.entry_date', '<', $from)
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) AS net');
        $this->balances->applyBranch($openings, $scope['filters']);

        $lines = $this->balances->linesQuery()->whereBetween('je.entry_date', [$from, $to])
            ->select(
                'l.id',
                'l.account_id',
                'l.debit',
                'l.credit',
                'l.description',
                'l.sort_order',
                'je.id as journal_entry_id',
                'je.entry_number',
                'je.entry_date',
                'je.source_type',
                'je.source_id',
                'je.description as entry_description'
            );
        $this->balances->applyBranch($lines, $scope['filters']);

        $sql = 'WITH acc AS ('.$accounts->toSql().'),'
            .' op AS ('.$openings->toSql().'),'
            .' ln AS (SELECT x.* FROM ('.$lines->toSql().') x WHERE x.account_id IN (SELECT id FROM acc)),'
            // Accounts with lines in the period or a non-zero opening (same rule as the export).
            .' act AS (SELECT acc.id, acc.code, acc.name, COALESCE(op.net, 0) AS opening FROM acc LEFT JOIN op ON op.account_id = acc.id'
            .'   WHERE EXISTS (SELECT 1 FROM ln WHERE ln.account_id = acc.id) OR ROUND(COALESCE(op.net, 0), 4) <> 0),'
            .' tot AS (SELECT account_id, SUM(debit) AS debit, SUM(credit) AS credit FROM ln GROUP BY account_id)'
            ." SELECT act.code, act.id AS account_id, act.code || ' — ' || act.name AS account, 0 AS part, NULL::date AS date,"
            .'   NULL::text AS entry, NULL::int AS sort_order, 0::bigint AS line_id, NULL::text AS source_type, NULL::text AS source_id, NULL::text AS entry_id,'
            ."   'opening_balance' AS description, NULL::numeric AS debit, NULL::numeric AS credit, ROUND(act.opening, 4) AS balance FROM act"
            .' UNION ALL'
            ." SELECT act.code, act.id, act.code || ' — ' || act.name, 1, ln.entry_date::date, ln.entry_number::text, ln.sort_order, ln.id,"
            .'   ln.source_type::text, ln.source_id::text, ln.journal_entry_id::text, COALESCE(ln.description, ln.entry_description), ROUND(ln.debit, 4), ROUND(ln.credit, 4),'
            .'   ROUND(act.opening + SUM(ln.debit - ln.credit) OVER (PARTITION BY ln.account_id ORDER BY ln.entry_date, ln.entry_number, ln.sort_order, ln.id'
            .'     ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW), 4)'
            .' FROM ln JOIN act ON act.id = ln.account_id'
            .' UNION ALL'
            ." SELECT act.code, act.id, act.code || ' — ' || act.name, 2, NULL, NULL, NULL, 0, NULL, NULL, NULL, 'closing_balance',"
            .'   ROUND(COALESCE(tot.debit, 0), 4), ROUND(COALESCE(tot.credit, 0), 4), ROUND(act.opening + COALESCE(tot.debit, 0) - COALESCE(tot.credit, 0), 4)'
            .' FROM act LEFT JOIN tot ON tot.account_id = act.id';
        $bindings = [...$accounts->getBindings(), ...$openings->getBindings(), ...$lines->getBindings()];

        // Totals: debit / credit of every line (opening and closing rows excluded).
        $result = SqlReportPage::run(
            [$sql, $bindings],
            null,
            [],
            'r.code ASC, r.account_id ASC, r.part ASC, r.date ASC NULLS FIRST, r.entry ASC NULLS FIRST, r.sort_order ASC NULLS FIRST, r.line_id ASC',
            ['debit' => 'CASE WHEN r.part = 1 THEN r.debit ELSE 0 END', 'credit' => 'CASE WHEN r.part = 1 THEN r.credit ELSE 0 END'],
            isset($list['page']) ? (int) $list['page'] : null,
            (int) $list['per_page']
        );

        // Document numbers only for the rows on this page.
        $numbers = SourceDocuments::numbers(collect($result['rows'])
            ->filter(fn (array $r): bool => (int) $r['part'] === 1)
            ->map(fn (array $r): array => [(string) $r['source_type'], $r['source_type'] === 'manual' ? null : $r['source_id']]));

        $rows = array_map(function (array $r) use ($numbers): array {
            $part = (int) $r['part'];
            if ($part === 0) {
                return ['row_kind' => 'opening', 'account' => $r['account'], 'description' => 'opening_balance', 'balance' => (string) $r['balance']];
            }
            if ($part === 2) {
                return ['row_kind' => 'closing', 'account' => $r['account'], 'description' => 'closing_balance',
                    'debit' => (string) $r['debit'], 'credit' => (string) $r['credit'], 'balance' => (string) $r['balance']];
            }

            return [
                'account' => $r['account'],
                'date' => (string) $r['date'],
                'entry' => $r['entry'],
                'document' => $numbers[$r['source_type'].':'.$r['source_id']] ?? null,
                'description' => $r['description'],
                'debit' => (string) $r['debit'],
                'credit' => (string) $r['credit'],
                'balance' => (string) $r['balance'],
                ...self::lineLinks($r['entry_id'], $r['source_type'], $r['source_id']),
            ];
        }, $result['rows']);

        $report = AnalyticsReport::make(
            [['account', 'text'], ['date', 'date'], ['entry', 'text'], ['document', 'text'], ['description', 'text'], ['debit', 'money'], ['credit', 'money'], ['balance', 'money']],
            $rows,
            ['debit' => Decimal::round($result['sums']['debit'], 4), 'credit' => Decimal::round($result['sums']['credit'], 4)],
            ['from' => $from, 'to' => $to, 'branch_id' => $scope['branch_id'], 'account_from' => $codeFrom, 'account_to' => $codeTo, 'search' => $search !== '' ? $search : null],
        );
        $report['pagination'] = SqlReportPage::meta($result, (int) $list['per_page'], [], '', false);

        return $report;
    }

    /**
     * Ids the screen needs to open the journal entry and its source document (not exported as columns).
     *
     * @return array{entry_id: ?string, source_type: ?string, source_id: ?string}
     */
    private static function lineLinks(mixed $entryId, mixed $sourceType, mixed $sourceId): array
    {
        $type = $sourceType !== null ? (string) $sourceType : null;

        return [
            'entry_id' => $entryId !== null ? (string) $entryId : null,
            'source_type' => $type,
            'source_id' => $type !== null && $type !== 'manual' && $sourceId !== null ? (string) $sourceId : null,
        ];
    }

    /**
     * Ledger accounts that hold cash: cash & bank accounts and the mapped Cash/Bank/Cheques accounts.
     *
     * @return list<int>
     */
    private function cashAccountIds(): array
    {
        $ids = DB::table('cash_bank_accounts')->pluck('account_id')
            ->merge(DB::table('account_mappings')->whereIn('role', [AccountRole::Cash->value, AccountRole::Bank->value, AccountRole::ChequesToCollect->value])->pluck('account_id'));

        return $ids->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function roleByAccount(): array
    {
        return DB::table('account_mappings')->pluck('role', 'account_id')->mapWithKeys(fn ($role, $id): array => [(int) $id => (string) $role])->all();
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function category(int $accountId, string $code, string $type, array $roles): string
    {
        $role = $roles[$accountId] ?? null;

        return match (true) {
            $role === AccountRole::Receivable->value => 'customers',
            $role === AccountRole::Payable->value => 'suppliers',
            in_array($role, [AccountRole::VatInput->value, AccountRole::VatOutput->value], true) => 'vat',
            in_array($role, [AccountRole::ExchangeGain->value, AccountRole::ExchangeLoss->value, AccountRole::UnrealizedExchangeGain->value, AccountRole::UnrealizedExchangeLoss->value], true) => 'exchange',
            $type === AccountType::Expense->value => 'expenses',
            $type === AccountType::Revenue->value => 'other_income',
            $type === AccountType::Equity->value => 'equity',
            $type === AccountType::Liability->value && str_starts_with($code, '16') => 'loans',
            $type === AccountType::Asset->value => 'assets',
            default => 'other',
        };
    }
}
