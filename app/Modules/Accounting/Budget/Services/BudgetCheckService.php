<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Services;

use App\Modules\Accounting\Budget\Enums\BudgetStatus;
use App\Modules\Accounting\Budget\Models\Budget;
use App\Modules\Accounting\Budget\Models\BudgetLine;
use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\CompanySetting\Enums\BudgetWarningScope;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Over-budget warnings. Only expense accounts are checked, against the approved budget of the
 * document's branch (or the company budget when the branch has none). Warnings never block posting.
 */
class BudgetCheckService
{
    public const SOURCE_EXPENSE_VOUCHER = 'expense_voucher';

    public const SOURCE_PURCHASE_INVOICE = 'purchase_invoice';

    public const SOURCE_JOURNAL_ENTRY = 'journal_entry';

    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly AccountMappingService $mappings,
        private readonly DomainNotificationPublisher $notifications,
    ) {}

    /**
     * Warnings for a saved document. When $alreadyPosted is true the document is already in the
     * ledger actuals, so its own amounts are not added a second time.
     *
     * @return list<array<string, mixed>>
     */
    public function checkDocument(string $source, string $id, bool $alreadyPosted = false): array
    {
        $document = $this->documentLines($source, $id);
        if ($document === null) {
            return [];
        }

        return $this->check($document['lines'], $document['date'], $document['branch_id'], $alreadyPosted);
    }

    /**
     * @param  list<array{account_id: int, amount: string}>  $lines  amounts in the primary currency, debit positive
     * @return list<array<string, mixed>>
     */
    public function check(array $lines, string $date, ?int $branchId, bool $alreadyPosted = false): array
    {
        $settings = CompanySetting::current();
        if (! (bool) ($settings->budget_warnings_enabled ?? true)) {
            return [];
        }

        $day = Carbon::parse($date)->startOfDay();
        $budget = $this->approvedBudget($day, $branchId);
        if ($budget === null) {
            return [];
        }

        $added = [];
        foreach ($lines as $line) {
            $accountId = (int) $line['account_id'];
            $added[$accountId] = JournalMath::add($added[$accountId] ?? '0', (string) $line['amount']);
        }
        $expenseIds = Account::query()
            ->whereIn('id', array_keys($added))
            ->where('type', AccountType::Expense->value)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($expenseIds === []) {
            return [];
        }

        $scope = $settings->budget_warning_scope instanceof BudgetWarningScope ? $settings->budget_warning_scope : BudgetWarningScope::Month;
        $periodStart = $scope === BudgetWarningScope::Ytd
            ? Carbon::parse($budget->fiscal_year_start)->startOfDay()
            : $day->copy()->startOfMonth();
        $periodEnd = $day->copy()->endOfMonth()->startOfDay();

        $budgeted = [];
        $rows = BudgetLine::query()
            ->where('budget_id', $budget->id)
            ->whereIn('account_id', $expenseIds)
            ->whereDate('period_month', '>=', $periodStart->copy()->startOfMonth()->toDateString())
            ->whereDate('period_month', '<=', $periodEnd->copy()->startOfMonth()->toDateString())
            ->get(['account_id', 'amount']);
        foreach ($rows as $row) {
            $budgeted[(int) $row->account_id] = JournalMath::add($budgeted[(int) $row->account_id] ?? '0', (string) $row->amount);
        }

        $filters = ['exclude_source_types' => [JournalEntry::SOURCE_YEAR_END_CLOSE]];
        if ($budget->branch_id !== null) {
            $filters['branch_id'] = (int) $budget->branch_id;
        }
        $totals = $this->balances->totals($periodStart->toDateString(), $periodEnd->toDateString(), null, $filters);
        $accounts = Account::query()->whereIn('id', $expenseIds)->get(['id', 'code', 'name'])->keyBy('id');

        $warnings = [];
        foreach ($expenseIds as $accountId) {
            // No budget line for the account means no limit was planned: nothing to warn about.
            if (! isset($budgeted[$accountId])) {
                continue;
            }
            $sum = $totals[$accountId] ?? null;
            $actual = JournalMath::sub($sum['debit'] ?? '0', $sum['credit'] ?? '0');
            $after = $alreadyPosted ? $actual : JournalMath::add($actual, $added[$accountId]);
            $over = JournalMath::sub($after, $budgeted[$accountId]);
            if (! JournalMath::isPositive($over)) {
                continue;
            }
            $account = $accounts->get($accountId);
            $warnings[] = [
                'account_id' => $accountId,
                'account_code' => $account?->code,
                'account_name' => $account?->name,
                'budget' => JournalMath::store($budgeted[$accountId]),
                'actual_after' => JournalMath::store($after),
                'over_by' => JournalMath::store($over),
                'scope' => $scope->value,
                'period_from' => $periodStart->toDateString(),
                'period_to' => $periodEnd->toDateString(),
                'budget_id' => (string) $budget->id,
                'budget_name' => $budget->name,
            ];
        }

        return $warnings;
    }

    /**
     * Called after a document is posted: tells budget viewers when it went over budget. Never throws.
     */
    public function notifyAfterPost(string $source, string $id, string $label): void
    {
        try {
            $warnings = $this->checkDocument($source, $id, true);
            if ($warnings !== []) {
                $this->notifications->budgetExceeded($label, $warnings);
            }
        } catch (Throwable $exception) {
            Log::warning('Budget check after posting failed', ['source' => $source, 'id' => $id, 'error' => $exception->getMessage()]);
        }
    }

    private function approvedBudget(Carbon $day, ?int $branchId): ?Budget
    {
        $base = Budget::query()
            ->where('status', BudgetStatus::Approved)
            ->whereDate('fiscal_year_start', '<=', $day->toDateString())
            ->whereDate('fiscal_year_end', '>=', $day->toDateString());

        if ($branchId !== null) {
            $own = (clone $base)->where('branch_id', $branchId)->first();
            if ($own !== null) {
                return $own;
            }
        }

        return (clone $base)->whereNull('branch_id')->first();
    }

    /**
     * @return array{lines: list<array{account_id: int, amount: string}>, date: string, branch_id: ?int}|null
     */
    private function documentLines(string $source, string $id): ?array
    {
        return match ($source) {
            self::SOURCE_EXPENSE_VOUCHER => $this->voucherLines($id),
            self::SOURCE_PURCHASE_INVOICE => $this->invoiceLines($id),
            self::SOURCE_JOURNAL_ENTRY => $this->journalLines($id),
            default => null,
        };
    }

    /**
     * @return array{lines: list<array{account_id: int, amount: string}>, date: string, branch_id: ?int}|null
     */
    private function voucherLines(string $id): ?array
    {
        $voucher = ExpenseVoucher::query()->with('lines')->find($id);
        if ($voucher === null) {
            return null;
        }
        $rate = (string) $voucher->exchange_rate;
        $lines = [];
        foreach ($voucher->lines as $line) {
            $lines[] = [
                'account_id' => (int) $line->account_id,
                'amount' => ExchangeRateSnapshot::toPrimary((string) $line->amount, $rate, JournalMath::WORK_SCALE),
            ];
        }

        return ['lines' => $lines, 'date' => $voucher->voucher_date->toDateString(), 'branch_id' => $voucher->branch_id !== null ? (int) $voucher->branch_id : null];
    }

    /**
     * Only service (non-stock) lines hit an expense account. Stock, landed-cost and fixed-asset
     * lines go to balance-sheet accounts and are left out.
     *
     * @return array{lines: list<array{account_id: int, amount: string}>, date: string, branch_id: ?int}|null
     */
    private function invoiceLines(string $id): ?array
    {
        $invoice = PurchaseInvoice::query()->with('lines')->find($id);
        if ($invoice === null) {
            return null;
        }
        $items = Item::query()
            ->whereIn('id', $invoice->lines->pluck('item_id')->unique()->all())
            ->get(['id', 'track_inventory', 'is_landed_cost', 'is_fixed_asset'])
            ->keyBy('id');
        $rate = (string) $invoice->exchange_rate;
        $total = '0';
        foreach ($invoice->lines as $line) {
            $item = $items->get((string) $line->item_id);
            if ($item === null || $item->track_inventory || $item->is_landed_cost || $item->is_fixed_asset) {
                continue;
            }
            $total = JournalMath::add($total, ExchangeRateSnapshot::toPrimary((string) $line->line_subtotal, $rate, JournalMath::WORK_SCALE));
        }
        if (JournalMath::isZero($total)) {
            return ['lines' => [], 'date' => $invoice->invoice_date->toDateString(), 'branch_id' => null];
        }
        $account = $this->mappings->accountFor(AccountRole::NonStockPurchase);

        return [
            'lines' => [['account_id' => (int) $account->id, 'amount' => $total]],
            'date' => $invoice->invoice_date->toDateString(),
            'branch_id' => $invoice->branch_id !== null ? (int) $invoice->branch_id : null,
        ];
    }

    /**
     * @return array{lines: list<array{account_id: int, amount: string}>, date: string, branch_id: ?int}|null
     */
    private function journalLines(string $id): ?array
    {
        $entry = JournalEntry::query()->with('lines')->find($id);
        if ($entry === null) {
            return null;
        }
        $lines = [];
        foreach ($entry->lines as $line) {
            $lines[] = [
                'account_id' => (int) $line->account_id,
                'amount' => JournalMath::sub((string) $line->debit, (string) $line->credit),
            ];
        }

        return ['lines' => $lines, 'date' => $entry->entry_date->toDateString(), 'branch_id' => $entry->branch_id !== null ? (int) $entry->branch_id : null];
    }
}
