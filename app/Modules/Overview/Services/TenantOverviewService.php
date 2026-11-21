<?php

declare(strict_types=1);

namespace App\Modules\Overview\Services;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\CompanyProfile\Services\CompanyProfileService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\Inventory\Stock\Services\PurchasingAlertService;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use App\Services\ModuleEntitlementService;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Tenant home figures. Each section is included only when the tenant has the
 * module and the user may view the underlying records.
 */
final class TenantOverviewService
{
    /** @var list<string> */
    private const AMOUNT_COLUMNS = ['grand_total', 'amount', 'net_to_pay'];

    /** @var list<string> */
    private const DATE_COLUMNS = ['invoice_date', 'credit_date', 'payment_date', 'due_on'];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ModuleEntitlementService $modules,
        private readonly CompanyProfileService $companyProfile,
        private readonly BranchContextService $branches,
        private readonly PurchasingAlertService $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $window = $this->window();
        $primary = Currency::getPrimary();
        $branch = $this->branches->contextPayload($user)['active_branch'] ?? null;

        return [
            'generated_at' => now()->toIso8601String(),
            'company_name' => (string) $this->companyProfile->get()->company_name,
            'branch' => is_array($branch) ? [
                'id' => (int) $branch['id'],
                'name' => (string) $branch['name'],
            ] : null,
            'currency' => $primary === null ? null : [
                'code' => (string) $primary->code,
                'symbol' => (string) ($primary->symbol ?? ''),
            ],
            'sales' => $this->salesSection($user, $window),
            'purchasing' => $this->purchasingSection($user, $window),
            'inventory' => $this->inventorySection($user),
            'accounting' => $this->accountingSection($user, $window),
            'directory' => $this->directory($user),
        ];
    }

    /**
     * @param  array{today: string, month_start: string, month_end: string, trend_start: string, periods: list<string>}  $window
     * @return array<string, mixed>|null
     */
    private function salesSection(User $user, array $window): ?array
    {
        if (! $this->canSee($user, 'sales', 'sales_invoices')) {
            return null;
        }

        $grossMonth = $this->postedPrimary(SalesInvoice::class, 'invoice_date', 'grand_total', $window['month_start'], $window['month_end']);
        $grossToday = $this->postedPrimary(SalesInvoice::class, 'invoice_date', 'grand_total', $window['today'], $window['today']);
        $grossTrend = $this->monthlyPrimary(SalesInvoice::class, 'invoice_date', 'grand_total', $window['trend_start'], $window['month_end']);

        $creditsMonth = null;
        $grossDisplayed = null;
        $headline = $grossMonth;
        $today = $grossToday;
        $trend = $grossTrend;

        if ($this->canSee($user, 'sales', 'sales_credit_notes')) {
            $creditsMonth = $this->postedPrimary(SalesCreditNote::class, 'credit_date', 'grand_total', $window['month_start'], $window['month_end']);
            $creditsToday = $this->postedPrimary(SalesCreditNote::class, 'credit_date', 'grand_total', $window['today'], $window['today']);
            $creditsTrend = $this->monthlyPrimary(SalesCreditNote::class, 'credit_date', 'grand_total', $window['trend_start'], $window['month_end']);
            $headline = $this->money(JournalMath::sub($grossMonth, $creditsMonth));
            $today = $this->money(JournalMath::sub($grossToday, $creditsToday));
            $trend = $this->subtractMonthly($grossTrend, $creditsTrend);
            $grossDisplayed = $grossMonth;
        }

        $open = $this->openDocuments(SalesInvoice::class, $window['today']);

        return [
            'month_total' => $headline,
            'today_total' => $today,
            'gross_month' => $grossDisplayed,
            'credits_month' => $creditsMonth,
            'collected_month' => $this->canSee($user, 'sales', 'customer_receipts')
                ? $this->postedPrimary(CustomerReceipt::class, 'payment_date', 'amount', $window['month_start'], $window['month_end'])
                : null,
            'open_total' => $open['total'],
            'overdue_count' => $open['overdue_count'],
            'overdue_total' => $open['overdue_total'],
            'draft_count' => $this->statusCount(SalesInvoice::class, 'draft'),
            'open_by_currency' => $open['by_currency'],
            'trend' => $this->filledTrend($trend, $window['periods']),
            'recent' => $this->recentDocuments(SalesInvoice::class, 'invoice_number', 'invoice_date', 'customer'),
        ];
    }

    /**
     * @param  array{today: string, month_start: string, month_end: string, trend_start: string, periods: list<string>}  $window
     * @return array<string, mixed>|null
     */
    private function purchasingSection(User $user, array $window): ?array
    {
        if (! $this->canSee($user, 'purchasing', 'purchase_invoices')) {
            return null;
        }

        $open = $this->openDocuments(PurchaseInvoice::class, $window['today']);

        return [
            'month_total' => $this->postedPrimary(PurchaseInvoice::class, 'invoice_date', 'grand_total', $window['month_start'], $window['month_end']),
            'today_total' => $this->postedPrimary(PurchaseInvoice::class, 'invoice_date', 'grand_total', $window['today'], $window['today']),
            'gross_month' => null,
            'credits_month' => null,
            'paid_month' => $this->canSee($user, 'purchasing', 'supplier_payments')
                ? $this->postedPrimary(SupplierPayment::class, 'payment_date', 'amount', $window['month_start'], $window['month_end'])
                : null,
            'open_total' => $open['total'],
            'overdue_count' => $open['overdue_count'],
            'overdue_total' => $open['overdue_total'],
            'draft_count' => $this->statusCount(PurchaseInvoice::class, 'draft'),
            'open_by_currency' => $open['by_currency'],
            'trend' => $this->filledTrend(
                $this->monthlyPrimary(PurchaseInvoice::class, 'invoice_date', 'grand_total', $window['trend_start'], $window['month_end']),
                $window['periods'],
            ),
            'recent' => $this->recentDocuments(PurchaseInvoice::class, 'invoice_number', 'invoice_date', 'supplier'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inventorySection(User $user): ?array
    {
        if (! $this->canSee($user, 'inventory', 'stock')) {
            return null;
        }

        $page = $this->alerts->paginate(['only_alerts' => true], 5);
        $alerts = [];
        foreach ($page->items() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $warehouse = is_array($row['warehouse'] ?? null) ? $row['warehouse'] : [];
            $alerts[] = [
                'item_name' => (string) ($item['name'] ?? ''),
                'item_code' => (string) ($item['item_code'] ?? ''),
                'warehouse_name' => (string) ($warehouse['name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'on_hand_qty' => (string) ($row['on_hand_qty'] ?? '0'),
            ];
        }

        return [
            'alert_count' => (int) $page->total(),
            'alerts' => $alerts,
        ];
    }

    /**
     * @param  array{today: string, month_start: string, month_end: string, trend_start: string, periods: list<string>}  $window
     * @return array<string, mixed>|null
     */
    private function accountingSection(User $user, array $window): ?array
    {
        $reports = $this->canSee($user, 'accounting', 'accounting_reports');
        $journals = $this->canSee($user, 'accounting', 'journal_entries');
        if (! $reports && ! $journals) {
            return null;
        }

        $section = [
            'revenue_month' => null,
            'expense_month' => null,
            'net_month' => null,
            'draft_count' => null,
            'posted_month_count' => null,
        ];

        if ($reports) {
            $rows = JournalEntryLine::query()
                ->toBase()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
                ->where('journal_entries.status', JournalEntryStatus::Posted->value)
                ->whereBetween('journal_entries.entry_date', [$window['month_start'], $window['month_end']])
                ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
                ->groupBy('accounts.type')
                ->selectRaw('accounts.type as account_type')
                ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as debit_total')
                ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as credit_total')
                ->get()
                ->keyBy('account_type');

            $revenueRow = $rows->get(AccountType::Revenue->value);
            $expenseRow = $rows->get(AccountType::Expense->value);
            $revenue = $this->money(JournalMath::sub($revenueRow->credit_total ?? '0', $revenueRow->debit_total ?? '0'));
            $expense = $this->money(JournalMath::sub($expenseRow->debit_total ?? '0', $expenseRow->credit_total ?? '0'));
            $section['revenue_month'] = $revenue;
            $section['expense_month'] = $expense;
            $section['net_month'] = $this->money(JournalMath::sub($revenue, $expense));
        }

        if ($journals) {
            $section['draft_count'] = $this->statusCount(JournalEntry::class, JournalEntryStatus::Draft->value);
            $section['posted_month_count'] = JournalEntry::query()
                ->where('status', JournalEntryStatus::Posted->value)
                ->whereBetween('entry_date', [$window['month_start'], $window['month_end']])
                ->count();
        }

        return $section;
    }

    /**
     * @return array{customers: int|null, suppliers: int|null, items: int|null, users: int|null}
     */
    private function directory(User $user): array
    {
        return [
            'customers' => $this->canSee($user, 'sales', 'customers')
                ? Customer::query()->where('status', CustomerStatus::Active)->count()
                : null,
            'suppliers' => $this->canSee($user, 'purchasing', 'suppliers')
                ? Supplier::query()->where('is_active', true)->count()
                : null,
            'items' => $this->canSee($user, 'inventory', 'items')
                ? Item::query()->where('is_active', true)->count()
                : null,
            'users' => $this->canSee($user, 'core', 'users')
                ? User::query()->where('is_active', true)->count()
                : null,
        ];
    }

    private function canSee(User $user, string $module, string $resource): bool
    {
        $tenantId = tenant('id');
        if (! is_string($tenantId) && ! is_int($tenantId)) {
            return false;
        }

        return $this->modules->tenantHas((string) $tenantId, $module)
            && $this->permissions->userHas($resource, 'view', $user);
    }

    /**
     * @return array{today: string, month_start: string, month_end: string, trend_start: string, periods: list<string>}
     */
    private function window(): array
    {
        $timezone = (string) (CompanySetting::current()->timezone ?: 'UTC');

        try {
            $now = Carbon::now($timezone)->toImmutable();
        } catch (\Throwable) {
            $now = Carbon::now('UTC')->toImmutable();
        }

        $monthStart = $now->startOfMonth();
        $trendStart = $monthStart->subMonths(5);
        $periods = [];
        for ($i = 0; $i < 6; $i++) {
            $periods[] = $trendStart->addMonths($i)->format('Y-m');
        }

        return [
            'today' => $now->toDateString(),
            'month_start' => $monthStart->toDateString(),
            'month_end' => $now->endOfMonth()->toDateString(),
            'trend_start' => $trendStart->toDateString(),
            'periods' => $periods,
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function postedPrimary(string $model, string $dateColumn, string $amountColumn, string $from, string $to): string
    {
        $table = $this->table($model, $dateColumn, $amountColumn);
        $row = $model::query()
            ->where("{$table}.status", 'posted')
            ->whereBetween("{$table}.{$dateColumn}", [$from, $to])
            ->toBase()
            ->selectRaw("COALESCE(SUM({$table}.{$amountColumn} / NULLIF({$table}.exchange_rate, 0)), 0) as total")
            ->get()
            ->first();

        return $this->money($row->total ?? '0');
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    private function monthlyPrimary(string $model, string $dateColumn, string $amountColumn, string $from, string $to): array
    {
        $table = $this->table($model, $dateColumn, $amountColumn);
        $rows = $model::query()
            ->where("{$table}.status", 'posted')
            ->whereBetween("{$table}.{$dateColumn}", [$from, $to])
            ->toBase()
            ->selectRaw("to_char({$table}.{$dateColumn}, 'YYYY-MM') as period")
            ->selectRaw("COALESCE(SUM({$table}.{$amountColumn} / NULLIF({$table}.exchange_rate, 0)), 0) as total")
            ->groupByRaw("to_char({$table}.{$dateColumn}, 'YYYY-MM')")
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->period] = $this->money($row->total);
        }

        return $out;
    }

    /**
     * Posted documents that still have a balance, converted with each document's own rate.
     *
     * @param  class-string<Model>  $model
     * @return array{total: string, overdue_count: int, overdue_total: string, by_currency: list<array{code: string, symbol: string, amount: string}>}
     */
    private function openDocuments(string $model, string $today): array
    {
        $table = $this->table($model, 'due_on', 'net_to_pay');
        $open = $model::query()
            ->where("{$table}.status", 'posted')
            ->where("{$table}.net_to_pay", '>', 0);

        $overdueCount = (clone $open)->whereDate("{$table}.due_on", '<', $today)->count();
        $overdueRow = (clone $open)
            ->whereDate("{$table}.due_on", '<', $today)
            ->toBase()
            ->selectRaw("COALESCE(SUM({$table}.net_to_pay / NULLIF({$table}.exchange_rate, 0)), 0) as total")
            ->get()
            ->first();

        $rows = (clone $open)
            ->toBase()
            ->join('currencies', 'currencies.id', '=', "{$table}.currency_id")
            ->groupBy('currencies.code', 'currencies.symbol')
            ->orderBy('currencies.code')
            ->selectRaw('currencies.code as code')
            ->selectRaw("COALESCE(currencies.symbol, '') as symbol")
            ->selectRaw("COALESCE(SUM({$table}.net_to_pay), 0) as amount")
            ->selectRaw("COALESCE(SUM({$table}.net_to_pay / NULLIF({$table}.exchange_rate, 0)), 0) as primary_amount")
            ->get();

        $primary = '0';
        $byCurrency = [];
        foreach ($rows as $row) {
            $primary = JournalMath::add($primary, $row->primary_amount ?? '0');
            $byCurrency[] = [
                'code' => (string) $row->code,
                'symbol' => (string) $row->symbol,
                'amount' => $this->money($row->amount),
            ];
        }

        return [
            'total' => $this->money($primary),
            'overdue_count' => $overdueCount,
            'overdue_total' => $this->money($overdueRow->total ?? '0'),
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<array<string, mixed>>
     */
    private function recentDocuments(string $model, string $numberColumn, string $dateColumn, string $partyRelation): array
    {
        $this->table($model, $dateColumn, 'grand_total');

        return $model::query()
            ->with([
                $partyRelation.':id,name',
                'currency:id,code,symbol',
            ])
            ->orderByDesc($dateColumn)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function (Model $row) use ($numberColumn, $dateColumn, $partyRelation): array {
                $party = $row->getRelation($partyRelation);
                $currency = $row->getRelation('currency');
                $date = $row->getAttribute($dateColumn);
                $status = $row->getAttribute('status');

                return [
                    'id' => (string) $row->getKey(),
                    'number' => (string) ($row->getAttribute($numberColumn) ?? ''),
                    'party' => $party instanceof Model ? (string) ($party->getAttribute('name') ?? '') : '',
                    'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : null,
                    'status' => $status instanceof \BackedEnum ? (string) $status->value : (string) $status,
                    'grand_total' => $this->money($row->getAttribute('grand_total')),
                    'currency_code' => $currency instanceof Model ? (string) ($currency->getAttribute('code') ?? '') : '',
                    'currency_symbol' => $currency instanceof Model ? (string) ($currency->getAttribute('symbol') ?? '') : '',
                ];
            })
            ->all();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function statusCount(string $model, string $status): int
    {
        return $model::query()->where('status', $status)->count();
    }

    /**
     * @param  array<string, string>  $gross
     * @param  array<string, string>  $credits
     * @return array<string, string>
     */
    private function subtractMonthly(array $gross, array $credits): array
    {
        $periods = array_unique([...array_keys($gross), ...array_keys($credits)]);
        $out = [];
        foreach ($periods as $period) {
            $out[$period] = $this->money(JournalMath::sub($gross[$period] ?? '0', $credits[$period] ?? '0'));
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $totals
     * @param  list<string>  $periods
     * @return list<array{period: string, total: string}>
     */
    private function filledTrend(array $totals, array $periods): array
    {
        $trend = [];
        foreach ($periods as $period) {
            $trend[] = [
                'period' => $period,
                'total' => $totals[$period] ?? $this->money('0'),
            ];
        }

        return $trend;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function table(string $model, string $dateColumn, string $amountColumn): string
    {
        if (! in_array($dateColumn, self::DATE_COLUMNS, true) || ! in_array($amountColumn, self::AMOUNT_COLUMNS, true)) {
            throw new InvalidArgumentException('Unsupported overview aggregate.');
        }

        return (new $model)->getTable();
    }

    private function money(mixed $value): string
    {
        return JournalMath::store(JournalMath::round($value, JournalMath::STORAGE_SCALE));
    }
}
