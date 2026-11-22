<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\Accounting\Budget\Services\BudgetCheckService;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Deferral\Enums\DeferralKind;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Services\DeferralScheduleService;
use App\Modules\Deferral\Support\DeferralAccountRules;
use App\Modules\Inventory\Purchasing\LandedCost\Services\LandedCostService;
use App\Modules\Inventory\Purchasing\LandedCost\Services\LandedCostSources;
use App\Modules\Treasury\Enums\TreasuryDocumentStatus;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Models\ExpenseVoucherLine;
use App\Modules\VatGroup\Models\VatGroup;
use App\Support\Decimal;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ExpenseVoucherService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly DocumentNumberService $numbers,
        private readonly CashBankAccountService $cashBankAccounts,
        private readonly DeferralScheduleService $deferrals,
        private readonly DeferralAccountRules $deferralAccounts,
    ) {}

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return LengthAwarePaginator<int, ExpenseVoucher>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ExpenseVoucher::query()
            ->with([
                'cashBankAccount:id,code,name,currency_id',
                'cashBankAccount.currency:id,code,symbol',
                'supplier:id,name',
                'recurringTemplate:id,template_number,name',
            ])
            ->orderByDesc('voucher_date')
            ->orderByDesc('created_at');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['voucher_number', 'payee', 'reference', 'notes']);

        return $query->paginate($perPage);
    }

    public function find(string $id): ExpenseVoucher
    {
        return ExpenseVoucher::query()
            ->with([
                'cashBankAccount.currency:id,code,symbol',
                'supplier:id,name',
                'recurringTemplate:id,template_number,name',
                'lines.account:id,code,name',
                'lines.deferralRecognitionAccount:id,code,name',
                'lines.deferralSchedule:id,schedule_number,status,source_line_id',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): ExpenseVoucher
    {
        return DB::transaction(function () use ($data, $userId): ExpenseVoucher {
            $attributes = [
                ...$this->header($data, null),
                'status' => TreasuryDocumentStatus::Draft,
                'created_by' => $userId,
            ];
            if (! empty($data['branch_id'])) {
                $attributes['branch_id'] = (int) $data['branch_id'];
            }
            $voucher = ExpenseVoucher::query()->create($attributes);
            $this->replaceLines($voucher, (array) ($data['lines'] ?? []));

            return $this->find((string) $voucher->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExpenseVoucher $voucher, array $data): ExpenseVoucher
    {
        return DB::transaction(function () use ($voucher, $data): ExpenseVoucher {
            $locked = $this->lockDraft($voucher);
            $locked->update($this->header($data, $locked));
            if (array_key_exists('lines', $data)) {
                $this->replaceLines($locked, (array) $data['lines']);
            }

            return $this->find((string) $locked->id);
        });
    }

    public function delete(ExpenseVoucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $this->lockDraft($voucher)->delete();
        });
    }

    public function post(ExpenseVoucher $voucher, ?string $userId): ExpenseVoucher
    {
        $posted = DB::transaction(function () use ($voucher, $userId): ExpenseVoucher {
            $locked = $this->lockDraft($voucher);
            if (! ExpenseVoucherLine::query()->where('expense_voucher_id', $locked->id)->exists() || ! Decimal::isPositive($locked->total)) {
                abort(422, 'Add at least one expense line before posting.', ['X-Error-Code' => 'EXPENSE_VOUCHER_NO_LINES']);
            }
            $cash = CashBankAccount::query()->find($locked->cash_bank_account_id);
            if ($cash === null || ! $cash->is_active) {
                abort(422, 'The cash/bank account must be active.', ['X-Error-Code' => 'TREASURY_ACCOUNT_INACTIVE']);
            }
            $this->cashBankAccounts->assertCanPayOut($cash, $locked->total);
            $this->numbers->assign($locked, 'voucher_number', DocumentType::ExpenseVoucher, (int) $locked->branch_id, $locked->voucher_date);
            $this->journalPostingService->expenseVoucher($locked, $userId);
            $locked->update([
                'status' => TreasuryDocumentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);
            $this->openDeferralSchedules($locked, $userId);

            return $this->find((string) $locked->id);
        });
        // Over-budget is only a warning: checked after the commit so it can never block or roll back the post.
        app(BudgetCheckService::class)->notifyAfterPost(BudgetCheckService::SOURCE_EXPENSE_VOUCHER, (string) $posted->id, (string) ($posted->voucher_number ?? ''));

        return $posted;
    }

    public function reverse(ExpenseVoucher $voucher, ?string $userId): ExpenseVoucher
    {
        return DB::transaction(function () use ($voucher, $userId): ExpenseVoucher {
            $locked = ExpenseVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TreasuryDocumentStatus::Posted) {
                abort(422, 'Only a posted expense voucher can be reversed.', ['X-Error-Code' => 'TREASURY_DOCUMENT_NOT_POSTED']);
            }
            LandedCostService::assertNotUsed('expense_voucher', (string) $locked->id);
            $this->cancelDeferralSchedules($locked, $userId);
            $this->journalPostingService->reverse(ExpenseVoucher::REFERENCE_TYPE, (string) $locked->id, $userId);
            $locked->update(['status' => TreasuryDocumentStatus::Reversed]);

            return $this->find((string) $locked->id);
        });
    }

    private function lockDraft(ExpenseVoucher $voucher): ExpenseVoucher
    {
        $locked = ExpenseVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== TreasuryDocumentStatus::Draft) {
            abort(422, 'Only a draft expense voucher can be changed.', ['X-Error-Code' => 'TREASURY_DOCUMENT_NOT_DRAFT']);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data, ?ExpenseVoucher $existing): array
    {
        $cashId = (int) ($data['cash_bank_account_id'] ?? $existing?->cash_bank_account_id);
        $cash = CashBankAccount::query()->find($cashId);
        if ($cash === null || ! $cash->is_active) {
            abort(422, 'Choose an active cash/bank account.', ['X-Error-Code' => 'TREASURY_ACCOUNT_INACTIVE']);
        }

        $sameAccount = $existing !== null && (int) $existing->cash_bank_account_id === $cashId;
        $out = [
            'cash_bank_account_id' => $cashId,
            'currency_id' => (int) $cash->currency_id,
            'exchange_rate' => ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                (int) $cash->currency_id,
                $data['exchange_rate'] ?? ($sameAccount ? $existing->exchange_rate : null),
                'TREASURY',
            ),
            'voucher_date' => $data['voucher_date'] ?? $existing?->voucher_date?->toDateString() ?? now()->toDateString(),
        ];
        foreach (['payee', 'supplier_id', 'reference', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) ($data[$key] ?? ''));
                $out[$key] = $value === '' ? null : $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function replaceLines(ExpenseVoucher $voucher, array $rows): void
    {
        ExpenseVoucherLine::query()->where('expense_voucher_id', $voucher->id)->delete();

        $subtotal = '0';
        $taxTotal = '0';
        foreach (array_values($rows) as $index => $row) {
            $account = Account::query()->find((int) ($row['account_id'] ?? 0));
            $spread = isset($row['deferral_start_date']) && $row['deferral_start_date'] !== null && $row['deferral_start_date'] !== '';
            if (! $account instanceof Account) {
                abort(422, 'Line '.($index + 1).': choose an active, non-group expense account.', [
                    'X-Error-Code' => 'EXPENSE_VOUCHER_ACCOUNT_INVALID',
                ]);
            }
            $recognitionId = null;
            if ($spread) {
                if ($account->type !== AccountType::Asset || ! $account->isPostable()) {
                    abort(422, 'Line '.($index + 1).': a spread line must use an active prepaid asset account.', [
                        'X-Error-Code' => 'EXPENSE_VOUCHER_ACCOUNT_INVALID',
                    ]);
                }
                $recognition = Account::query()->find((int) ($row['deferral_recognition_account_id'] ?? 0));
                if (! $recognition instanceof Account) {
                    abort(422, 'Line '.($index + 1).': choose the expense account that will recognise this prepayment.', [
                        'X-Error-Code' => 'DEFERRAL_ACCOUNT_INVALID',
                    ]);
                }
                $this->deferralAccounts->assertAccounts(DeferralKind::PrepaidExpense, $account, $recognition, (int) $voucher->currency_id);
                $recognitionId = (int) $recognition->id;
            } else {
                $this->assertLineAccount($account, $index);
            }
            $amount = PriceMath::normalize($row['amount'] ?? 0);
            if (! Decimal::isPositive($amount)) {
                abort(422, 'Line '.($index + 1).': enter an amount greater than zero.', ['X-Error-Code' => 'TREASURY_AMOUNT_INVALID']);
            }

            $vatGroupId = isset($row['vat_group_id']) && $row['vat_group_id'] !== '' ? (int) $row['vat_group_id'] : null;
            $rate = $vatGroupId !== null ? Decimal::round(VatGroup::query()->whereKey($vatGroupId)->value('percentage') ?? 0, 4) : '0.0000';
            $tax = PriceMath::normalize(Decimal::div(Decimal::mul($amount, $rate), '100', 12));

            ExpenseVoucherLine::query()->create([
                'expense_voucher_id' => $voucher->id,
                'account_id' => (int) $account->id,
                'description' => isset($row['description']) && trim((string) $row['description']) !== '' ? mb_substr(trim((string) $row['description']), 0, 500) : null,
                'amount' => $amount,
                'vat_group_id' => $vatGroupId,
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'sort_order' => $index + 1,
                'deferral_start_date' => $spread ? substr((string) $row['deferral_start_date'], 0, 10) : null,
                'deferral_end_date' => $spread ? substr((string) $row['deferral_end_date'], 0, 10) : null,
                'deferral_recognition_account_id' => $recognitionId,
                'deferral_method' => $spread ? (string) ($row['deferral_method'] ?? 'straight_line_monthly') : null,
            ]);

            $subtotal = Decimal::add($subtotal, $amount);
            $taxTotal = Decimal::add($taxTotal, $tax);
        }

        $voucher->update([
            'subtotal' => PriceMath::normalize($subtotal),
            'tax_total' => PriceMath::normalize($taxTotal),
            'total' => PriceMath::normalize(Decimal::add($subtotal, $taxTotal)),
        ]);
    }

    public function assertLineAccount(Account $account, int $index): void
    {
        $isClearing = (int) $account->id === app(LandedCostSources::class)->clearingAccountId();
        if (($account->type !== AccountType::Expense && ! $isClearing) || ! $account->isPostable()) {
            abort(422, 'Line '.($index + 1).': choose an active, non-group expense account.', [
                'X-Error-Code' => 'EXPENSE_VOUCHER_ACCOUNT_INVALID',
            ]);
        }
    }

    private function openDeferralSchedules(ExpenseVoucher $voucher, ?string $userId): void
    {
        $voucher->load('lines.account');
        foreach ($voucher->lines as $line) {
            if ($line->deferral_start_date === null || $line->deferral_end_date === null) {
                continue;
            }
            $label = trim((string) ($line->description ?: $line->account?->name));
            $schedule = $this->deferrals->createFromSource([
                'kind' => DeferralKind::PrepaidExpense->value,
                'name' => mb_substr(($voucher->voucher_number ?? 'Expense').' — '.$label, 0, 255),
                'branch_id' => (int) $voucher->branch_id,
                'deferral_account_id' => (int) $line->account_id,
                'recognition_account_id' => (int) $line->deferral_recognition_account_id,
                'currency_id' => (int) $voucher->currency_id,
                'exchange_rate' => (string) $voucher->exchange_rate,
                'total_amount' => (string) $line->amount,
                'start_date' => $line->deferral_start_date->toDateString(),
                'end_date' => $line->deferral_end_date->toDateString(),
                'method' => $line->deferral_method ?: 'straight_line_monthly',
                'posting_day' => 'period_end',
                'auto_post' => true,
            ], $userId, [
                'type' => 'expense_voucher',
                'id' => (string) $voucher->id,
                'line_id' => (int) $line->id,
                'supplier_id' => $voucher->supplier_id,
            ]);
            $this->deferrals->activate($schedule, $userId);
        }
    }

    private function cancelDeferralSchedules(ExpenseVoucher $voucher, ?string $userId): void
    {
        $schedules = DeferralSchedule::query()
            ->where('source_type', 'expense_voucher')
            ->where('source_id', $voucher->id)
            ->get();
        foreach ($schedules as $schedule) {
            if ($schedule->lines()->where('status', DeferralLineStatus::Posted->value)->exists()) {
                abort(422, 'Reverse the posted recognition lines before reversing this voucher.', [
                    'X-Error-Code' => 'DEFERRAL_HAS_POSTED_LINES',
                ]);
            }
        }
        foreach ($schedules as $schedule) {
            if ($schedule->status->value !== 'cancelled') {
                $this->deferrals->cancel($schedule, 'Source voucher reversed', $userId);
            }
        }
    }
}
