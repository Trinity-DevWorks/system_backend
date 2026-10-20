<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\DTOs\JournalEntryResponseData;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Accounting\Support\SourceDocuments;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

class AccountingReportService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * Per account: opening balance, period debit and credit, closing balance.
     * Without a level only posting accounts are listed; with a level, accounts at that
     * depth carry their descendants' totals (depth 1 = the one-digit classes).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>, from: ?string, to: ?string, level: ?int, branch_id: ?int}
     */
    public function trialBalance(?string $from, ?string $to, ?int $level, ?int $branchId = null): array
    {
        $scoped = $this->branchScope->apply($branchId);
        $filters = $scoped['filters'];
        $accounts = Account::query()->orderBy('code')->get();
        $opening = $from !== null ? $this->balances->totals(null, null, $from, $filters) : [];
        $period = $this->balances->totals($from, $to, null, $filters);

        if ($level !== null) {
            $opening = $this->balances->rollUp($accounts, $opening);
            $period = $this->balances->rollUp($accounts, $period);
        }

        $depths = $this->depths($accounts);
        $rows = [];
        $totals = array_fill_keys([
            'opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit',
        ], '0');

        foreach ($accounts as $account) {
            $depth = $depths[$account->id] ?? 1;
            $show = $level === null
                ? ! $account->is_group
                : ($depth === $level || ($depth < $level && ! $account->is_group));
            if (! $show) {
                continue;
            }

            $openingNet = AccountBalanceService::net($opening[$account->id] ?? []);
            $periodDebit = JournalMath::store($period[$account->id]['debit'] ?? '0');
            $periodCredit = JournalMath::store($period[$account->id]['credit'] ?? '0');
            $closingNet = JournalMath::store(JournalMath::add($openingNet, JournalMath::sub($periodDebit, $periodCredit)));

            if (JournalMath::isZero($openingNet, 4) && JournalMath::isZero($periodDebit, 4) && JournalMath::isZero($periodCredit, 4)) {
                continue;
            }

            $row = [
                'account_id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'name_ar' => $account->name_ar !== null && trim((string) $account->name_ar) !== '' ? (string) $account->name_ar : null,
                'type' => $account->type?->value,
                'normal_balance' => $account->normal_balance?->value,
                'is_group' => (bool) $account->is_group,
                'depth' => $depth,
                'opening_balance' => $openingNet,
                'opening_debit' => self::debitSide($openingNet),
                'opening_credit' => self::creditSide($openingNet),
                'period_debit' => $periodDebit,
                'period_credit' => $periodCredit,
                'closing_balance' => $closingNet,
                'closing_debit' => self::debitSide($closingNet),
                'closing_credit' => self::creditSide($closingNet),
            ];
            foreach (array_keys($totals) as $key) {
                $totals[$key] = JournalMath::store(JournalMath::add($totals[$key], $row[$key]));
            }
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'level' => $level,
            'branch_id' => $scoped['branch_id'],
        ];
    }

    /**
     * Lines on one account (and its descendants for a group) with a running balance.
     *
     * @return array<string, mixed>
     */
    public function accountLedger(Account $account, ?string $from, ?string $to, ?int $branchId = null): array
    {
        $scoped = $this->branchScope->apply($branchId);
        $filters = $scoped['filters'];
        $accountIds = $this->descendantIds($account);

        $openingQuery = $this->balances->linesQuery()
            ->whereIn('l.account_id', $accountIds)
            ->when($from !== null, fn (Builder $q) => $q->where('je.entry_date', '<', $from))
            ->when($from === null, fn (Builder $q) => $q->whereRaw('1 = 0'));
        $this->balances->applyBranch($openingQuery, $filters);
        $openingRow = $openingQuery
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit')
            ->first();
        $opening = AccountBalanceService::net(['debit' => $openingRow->debit ?? '0', 'credit' => $openingRow->credit ?? '0']);

        $linesQuery = JournalEntryLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('je.status', '!=', 'draft')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->when($from !== null, fn ($q) => $q->where('je.entry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('je.entry_date', '<=', $to))
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_number')
            ->orderBy('journal_entry_lines.sort_order')
            ->orderBy('journal_entry_lines.id');
        $this->balances->applyBranch($linesQuery, $filters);
        $lines = $linesQuery
            ->with(['account:id,code,name,name_ar', 'currency:id,code'])
            ->get([
                'journal_entry_lines.*',
                'je.entry_number',
                'je.entry_date',
                'je.source_type',
                'je.source_id',
                'je.source_event',
                'je.description as entry_description',
            ]);

        $numbers = SourceDocuments::numbers($lines->map(fn ($l): array => [
            (string) $l->getAttribute('source_type'),
            $l->getAttribute('source_type') === 'manual' ? null : $l->getAttribute('source_id'),
        ]));
        $parties = JournalEntryResponseData::partyNames($lines);

        $running = $opening;
        $debitTotal = '0';
        $creditTotal = '0';
        $rows = [];
        foreach ($lines as $line) {
            $running = JournalMath::add($running, JournalMath::sub($line->debit, $line->credit));
            $debitTotal = JournalMath::add($debitTotal, $line->debit);
            $creditTotal = JournalMath::add($creditTotal, $line->credit);
            $rows[] = [
                'id' => (int) $line->id,
                'journal_entry_id' => (string) $line->journal_entry_id,
                'entry_number' => (string) $line->getAttribute('entry_number'),
                'entry_date' => Carbon::parse((string) $line->getAttribute('entry_date'))->toDateString(),
                'source_type' => (string) $line->getAttribute('source_type'),
                'source_id' => $line->getAttribute('source_id'),
                'source_event' => (string) $line->getAttribute('source_event'),
                'source_number' => $numbers[$line->getAttribute('source_type').':'.$line->getAttribute('source_id')] ?? null,
                'description' => $line->description ?? $line->getAttribute('entry_description'),
                'account_id' => (int) $line->account_id,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'account_name_ar' => $line->account?->name_ar,
                'party_type' => $line->party_type,
                'party_id' => $line->party_id,
                'party_name' => $line->party_id !== null ? ($parties[$line->party_type.':'.$line->party_id] ?? null) : null,
                'currency_code' => $line->currency?->code,
                'amount_currency' => (string) $line->amount_currency,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'balance' => JournalMath::store($running),
            ];
        }

        return [
            'account' => [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'name_ar' => $account->name_ar !== null && trim((string) $account->name_ar) !== '' ? (string) $account->name_ar : null,
                'type' => $account->type?->value,
                'normal_balance' => $account->normal_balance?->value,
                'is_group' => (bool) $account->is_group,
            ],
            'from' => $from,
            'to' => $to,
            'branch_id' => $scoped['branch_id'],
            'opening_balance' => $opening,
            'period_debit' => JournalMath::store($debitTotal),
            'period_credit' => JournalMath::store($creditTotal),
            'closing_balance' => JournalMath::store($running),
            'lines' => $rows,
        ];
    }

    /**
     * @return list<int>
     */
    private function descendantIds(Account $account): array
    {
        if (! $account->is_group) {
            return [(int) $account->id];
        }

        return Account::query()
            ->where('code', 'like', $account->code.'%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  iterable<Account>  $accounts
     * @return array<int, int>
     */
    private function depths(iterable $accounts): array
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

    private static function debitSide(string $net): string
    {
        return JournalMath::isPositive($net, 4) ? JournalMath::store($net) : '0.0000';
    }

    private static function creditSide(string $net): string
    {
        return JournalMath::isNegative($net, 4) ? JournalMath::store(JournalMath::abs($net)) : '0.0000';
    }
}
