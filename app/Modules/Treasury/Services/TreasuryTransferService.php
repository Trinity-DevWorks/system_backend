<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Treasury\Enums\TreasuryDocumentStatus;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Models\TreasuryTransfer;
use App\Support\Decimal;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TreasuryTransferService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly DocumentNumberService $numbers,
        private readonly CashBankAccountService $cashBankAccounts,
    ) {}

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return LengthAwarePaginator<int, TreasuryTransfer>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = TreasuryTransfer::query()
            ->with(['fromAccount:id,code,name,currency_id', 'fromAccount.currency:id,code', 'toAccount:id,code,name,currency_id', 'toAccount.currency:id,code'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('created_at');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['transfer_number', 'reference', 'notes']);

        return $query->paginate($perPage);
    }

    public function find(string $id): TreasuryTransfer
    {
        return TreasuryTransfer::query()
            ->with(['fromAccount.currency:id,code', 'toAccount.currency:id,code'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): TreasuryTransfer
    {
        return DB::transaction(function () use ($data, $userId): TreasuryTransfer {
            $transfer = TreasuryTransfer::query()->create([
                ...$this->normalize($data, null),
                'status' => TreasuryDocumentStatus::Draft,
                'created_by' => $userId,
            ]);

            return $this->find((string) $transfer->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TreasuryTransfer $transfer, array $data): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $data): TreasuryTransfer {
            $locked = $this->lockDraft($transfer);
            $locked->update($this->normalize($data, $locked));

            return $this->find((string) $locked->id);
        });
    }

    public function delete(TreasuryTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $this->lockDraft($transfer)->delete();
        });
    }

    public function post(TreasuryTransfer $transfer, ?string $userId): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): TreasuryTransfer {
            $locked = $this->lockDraft($transfer);
            [$from] = $this->assertAccountsUsable($locked->from_account_id, $locked->to_account_id);
            $this->cashBankAccounts->assertCanPayOut($from, $locked->amount_from);
            $this->numbers->assign($locked, 'transfer_number', DocumentType::TreasuryTransfer, (int) $locked->branch_id, $locked->transfer_date);
            $this->journalPostingService->treasuryTransfer($locked, $userId);
            $locked->update([
                'status' => TreasuryDocumentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find((string) $locked->id);
        });
    }

    public function reverse(TreasuryTransfer $transfer, ?string $userId): TreasuryTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): TreasuryTransfer {
            $locked = TreasuryTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TreasuryDocumentStatus::Posted) {
                abort(422, 'Only a posted transfer can be reversed.', ['X-Error-Code' => 'TREASURY_DOCUMENT_NOT_POSTED']);
            }
            $this->journalPostingService->reverse(TreasuryTransfer::REFERENCE_TYPE, (string) $locked->id, $userId);
            $locked->update(['status' => TreasuryDocumentStatus::Reversed]);

            return $this->find((string) $locked->id);
        });
    }

    private function lockDraft(TreasuryTransfer $transfer): TreasuryTransfer
    {
        $locked = TreasuryTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== TreasuryDocumentStatus::Draft) {
            abort(422, 'Only a draft transfer can be changed.', ['X-Error-Code' => 'TREASURY_DOCUMENT_NOT_DRAFT']);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?TreasuryTransfer $existing): array
    {
        $fromId = (int) ($data['from_account_id'] ?? $existing?->from_account_id);
        $toId = (int) ($data['to_account_id'] ?? $existing?->to_account_id);
        [$from, $to] = $this->assertAccountsUsable($fromId, $toId);

        $amountFrom = PriceMath::normalize($data['amount_from'] ?? $existing?->amount_from ?? 0);
        if (! Decimal::isPositive($amountFrom)) {
            abort(422, 'Enter an amount greater than zero.', ['X-Error-Code' => 'TREASURY_AMOUNT_INVALID']);
        }

        $sameCurrency = (int) $from->currency_id === (int) $to->currency_id;
        $amountTo = $sameCurrency
            ? $amountFrom
            : PriceMath::normalize($data['amount_to'] ?? $existing?->amount_to ?? 0);
        if (! Decimal::isPositive($amountTo)) {
            abort(422, 'Enter the amount received in the destination currency.', ['X-Error-Code' => 'TREASURY_AMOUNT_INVALID']);
        }

        $rateFrom = ExchangeRateSnapshot::resolve(
            $this->exchangeRateService,
            (int) $from->currency_id,
            $data['exchange_rate'] ?? ($existing !== null && (int) $existing->from_account_id === $fromId ? $existing->exchange_rate : null),
            'TREASURY',
        );
        $rateTo = $sameCurrency
            ? $rateFrom
            : ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                (int) $to->currency_id,
                $data['exchange_rate_to'] ?? ($existing !== null && (int) $existing->to_account_id === $toId ? $existing->exchange_rate_to : null),
                'TREASURY',
            );

        $out = [
            'from_account_id' => $fromId,
            'to_account_id' => $toId,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'exchange_rate' => $rateFrom,
            'exchange_rate_to' => $rateTo,
            'transfer_date' => $data['transfer_date'] ?? $existing?->transfer_date?->toDateString() ?? now()->toDateString(),
        ];
        foreach (['reference', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) ($data[$key] ?? ''));
                $out[$key] = $value === '' ? null : $value;
            }
        }

        return $out;
    }

    /**
     * @return array{0: CashBankAccount, 1: CashBankAccount}
     */
    private function assertAccountsUsable(int $fromId, int $toId): array
    {
        if ($fromId === $toId) {
            abort(422, 'Choose two different cash/bank accounts.', ['X-Error-Code' => 'TREASURY_SAME_ACCOUNT']);
        }
        $from = CashBankAccount::query()->find($fromId);
        $to = CashBankAccount::query()->find($toId);
        if ($from === null || $to === null || ! $from->is_active || ! $to->is_active) {
            abort(422, 'Both cash/bank accounts must exist and be active.', ['X-Error-Code' => 'TREASURY_ACCOUNT_INACTIVE']);
        }

        return [$from, $to];
    }
}
