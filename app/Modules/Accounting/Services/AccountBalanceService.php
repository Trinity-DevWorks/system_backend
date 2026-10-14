<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalMath;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    /**
     * Debit and credit totals per posting account over non-draft entries.
     * Reversed entries stay in: their reversal entry cancels them out.
     *
     * @return array<int, array{debit: string, credit: string}>
     */
    public function totals(?string $from = null, ?string $to = null, ?string $before = null): array
    {
        $rows = $this->linesQuery()
            ->when($from !== null, fn (Builder $q) => $q->where('je.entry_date', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('je.entry_date', '<=', $to))
            ->when($before !== null, fn (Builder $q) => $q->where('je.entry_date', '<', $before))
            ->groupBy('l.account_id')
            ->select('l.account_id', DB::raw('SUM(l.debit) AS debit'), DB::raw('SUM(l.credit) AS credit'))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->account_id] = [
                'debit' => JournalMath::store($row->debit),
                'credit' => JournalMath::store($row->credit),
            ];
        }

        return $out;
    }

    /**
     * Add each account's totals to all its ancestors.
     *
     * @param  Collection<int, Account>|iterable<Account>  $accounts
     * @param  array<int, array{debit: string, credit: string}>  $totals
     * @return array<int, array{debit: string, credit: string}>
     */
    public function rollUp(iterable $accounts, array $totals): array
    {
        $parents = [];
        foreach ($accounts as $account) {
            $parents[(int) $account->id] = $account->parent_id !== null ? (int) $account->parent_id : null;
        }

        $out = [];
        foreach ($parents as $id => $_) {
            $out[$id] = ['debit' => '0.0000', 'credit' => '0.0000'];
        }
        foreach ($totals as $accountId => $sum) {
            $id = $accountId;
            $guard = 0;
            while ($id !== null && isset($parents[$id]) && $guard++ < 32) {
                $out[$id]['debit'] = JournalMath::store(JournalMath::add($out[$id]['debit'], $sum['debit']));
                $out[$id]['credit'] = JournalMath::store(JournalMath::add($out[$id]['credit'], $sum['credit']));
                $id = $parents[$id];
            }
        }

        return $out;
    }

    /**
     * Debit minus credit.
     */
    public static function net(array $sum): string
    {
        return JournalMath::store(JournalMath::sub($sum['debit'] ?? '0', $sum['credit'] ?? '0'));
    }

    public function linesQuery(): Builder
    {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', '!=', JournalEntryStatus::Draft->value);
    }
}
