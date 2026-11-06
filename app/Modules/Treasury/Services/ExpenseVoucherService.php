<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Services\ExchangeRateService;
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
    ) {}

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return LengthAwarePaginator<int, ExpenseVoucher>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ExpenseVoucher::query()
            ->with(['cashBankAccount:id,code,name,currency_id', 'cashBankAccount.currency:id,code,symbol', 'supplier:id,name'])
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
            ->with(['cashBankAccount.currency:id,code,symbol', 'supplier:id,name', 'lines.account:id,code,name'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): ExpenseVoucher
    {
        return DB::transaction(function () use ($data, $userId): ExpenseVoucher {
            $voucher = ExpenseVoucher::query()->create([
                ...$this->header($data, null),
                'status' => TreasuryDocumentStatus::Draft,
                'created_by' => $userId,
            ]);
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
        return DB::transaction(function () use ($voucher, $userId): ExpenseVoucher {
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

            return $this->find((string) $locked->id);
        });
    }

    public function reverse(ExpenseVoucher $voucher, ?string $userId): ExpenseVoucher
    {
        return DB::transaction(function () use ($voucher, $userId): ExpenseVoucher {
            $locked = ExpenseVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TreasuryDocumentStatus::Posted) {
                abort(422, 'Only a posted expense voucher can be reversed.', ['X-Error-Code' => 'TREASURY_DOCUMENT_NOT_POSTED']);
            }
            LandedCostService::assertNotUsed('expense_voucher', (string) $locked->id);
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
            $isClearing = $account !== null && (int) $account->id === app(LandedCostSources::class)->clearingAccountId();
            if ($account === null || ($account->type !== AccountType::Expense && ! $isClearing) || ! $account->isPostable()) {
                abort(422, 'Line '.($index + 1).': choose an active, non-group expense account.', [
                    'X-Error-Code' => 'EXPENSE_VOUCHER_ACCOUNT_INVALID',
                ]);
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
}
