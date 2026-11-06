<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Treasury\Enums\CashBankAccountType;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Models\TreasuryTransfer;
use App\Support\Decimal;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CashBankAccountService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * @return Collection<int, CashBankAccount>
     */
    public function list(): Collection
    {
        return CashBankAccount::query()->with(['currency:id,code,symbol', 'account:id,code,name', 'branch:id,name'])->orderBy('name')->get();
    }

    /**
     * @return LengthAwarePaginator<int, CashBankAccount>
     */
    public function paginate(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = CashBankAccount::query()
            ->with(['currency:id,code,symbol', 'account:id,code,name', 'branch:id,name'])
            ->orderBy('type')
            ->orderBy('name');
        ListPagination::applySearch($query, $search, ['code', 'name', 'bank_name', 'iban', 'account_number']);

        return $query->paginate($perPage);
    }

    /**
     * Current balance from posted journal lines: in the account currency and in the primary currency.
     *
     * @param  list<int>  $ledgerAccountIds
     * @return array<int, array{balance: string, balance_primary: string}>
     */
    public function balances(array $ledgerAccountIds): array
    {
        if ($ledgerAccountIds === []) {
            return [];
        }

        $rows = JournalEntryLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('je.status', '!=', 'draft')
            ->whereIn('journal_entry_lines.account_id', $ledgerAccountIds)
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.account_id, SUM(journal_entry_lines.amount_currency) as balance, SUM(journal_entry_lines.debit) - SUM(journal_entry_lines.credit) as balance_primary')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->getAttribute('account_id')] = [
                'balance' => Decimal::round($row->getAttribute('balance') ?? 0, 4),
                'balance_primary' => Decimal::round($row->getAttribute('balance_primary') ?? 0, 4),
            ];
        }

        return $out;
    }

    /**
     * Available money in the account's own currency (posted journal lines, opening balance included).
     */
    public function availableBalance(CashBankAccount $account): string
    {
        return $this->balances([(int) $account->account_id])[(int) $account->account_id]['balance'] ?? '0';
    }

    /**
     * A cash box cannot pay out more than it holds. Bank accounts may go into overdraft.
     * Skipped when accounting is off, since balances come from the journal.
     */
    public function assertCanPayOut(CashBankAccount $account, mixed $amount): void
    {
        if ($account->type !== CashBankAccountType::Cash || ! $this->journalPostingService->enabled()) {
            return;
        }
        $available = $this->availableBalance($account);
        if (Decimal::cmp($amount, $available) > 0) {
            abort(422, 'Not enough money in this cash account. Available: '.Decimal::round($available, 2).'.', [
                'X-Error-Code' => 'TREASURY_INSUFFICIENT_BALANCE',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CashBankAccount
    {
        return DB::transaction(function () use ($data): CashBankAccount {
            $this->assertLedgerAccount((int) $data['account_id'], null);

            $account = CashBankAccount::query()->create($this->attributes($data));
            $this->journalPostingService->cashBankOpeningBalance($account);

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CashBankAccount $account, array $data): CashBankAccount
    {
        return DB::transaction(function () use ($account, $data): CashBankAccount {
            if (array_key_exists('account_id', $data) && (int) $data['account_id'] !== (int) $account->account_id) {
                if ($this->isUsed($account)) {
                    abort(422, 'The ledger account cannot change once documents use this cash/bank account.', [
                        'X-Error-Code' => 'TREASURY_ACCOUNT_IN_USE',
                    ]);
                }
                $this->assertLedgerAccount((int) $data['account_id'], (int) $account->id);
            }
            if (array_key_exists('currency_id', $data) && (int) $data['currency_id'] !== (int) $account->currency_id && $this->isUsed($account)) {
                abort(422, 'The currency cannot change once documents use this cash/bank account.', [
                    'X-Error-Code' => 'TREASURY_ACCOUNT_IN_USE',
                ]);
            }
            $account->fill($this->attributes($data));
            // The opening entry follows the amount, date, ledger account and currency.
            $openingChanged = $account->isDirty(['opening_balance', 'opening_date', 'account_id', 'currency_id', 'branch_id', 'code']);
            $account->save();
            if ($openingChanged) {
                $this->journalPostingService->replaceCashBankOpeningBalance($account->refresh());
            }

            return $account->refresh();
        });
    }

    public function delete(CashBankAccount $account): void
    {
        if ($this->isUsed($account)) {
            abort(409, 'This cash/bank account is used by transfers or expense vouchers. Deactivate it instead.', [
                'X-Error-Code' => 'TREASURY_ACCOUNT_IN_USE',
            ]);
        }
        DB::transaction(function () use ($account): void {
            $this->journalEntryService->deleteAutomatic(SourceDocuments::CASH_BANK_OPENING_BALANCE, (string) $account->id);
            $account->delete();
        });
    }

    private function isUsed(CashBankAccount $account): bool
    {
        return TreasuryTransfer::query()->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id)->exists()
            || ExpenseVoucher::query()->where('cash_bank_account_id', $account->id)->exists()
            || DB::table('customer_receipts')->where('cash_bank_account_id', $account->id)->exists()
            || DB::table('sales_invoice_payments')->where('cash_bank_account_id', $account->id)->exists();
    }

    private function assertLedgerAccount(int $accountId, ?int $ignoreId): void
    {
        $ledger = Account::query()->find($accountId);
        if ($ledger === null || $ledger->type !== AccountType::Asset || ! $ledger->isPostable()) {
            abort(422, 'Choose an active, non-group asset account for a cash/bank account.', [
                'X-Error-Code' => 'TREASURY_LEDGER_ACCOUNT_INVALID',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $out = [];
        foreach (['code', 'name', 'type', 'currency_id', 'account_id', 'branch_id', 'bank_name', 'iban', 'account_number', 'opening_balance', 'opening_date', 'is_active'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $out[$key] = is_string($value) && trim($value) === '' && $key !== 'code' && $key !== 'name' ? null : $value;
            }
        }
        if (array_key_exists('opening_balance', $out)) {
            $out['opening_balance'] = $out['opening_balance'] ?? 0;
            // An amount without a date opens today.
            if (Decimal::isPositive($out['opening_balance']) && empty($out['opening_date'] ?? null)) {
                $out['opening_date'] = now()->toDateString();
            }
        }
        if (isset($out['code'])) {
            $out['code'] = strtoupper(trim((string) $out['code']));
        }

        return $out;
    }
}
