<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerCheque\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Sales\CustomerCheque\Models\CustomerCheque;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptService;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cheques received from customers: in hand (in "Cheques to collect") until they clear into a bank account
 * or bounce. A bounce reverses the cheque's receipt, so the invoice is open again and the customer owes it.
 */
class CustomerChequeService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly CustomerReceiptService $customerReceiptService,
    ) {}

    /**
     * @param  array{status?: ?string, customer_id?: ?string, search?: ?string, due_from?: ?string, due_to?: ?string}  $filters
     * @return LengthAwarePaginator<int, CustomerCheque>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = CustomerCheque::query()->with([
            'customer:id,customer_code,name',
            'currency:id,code,symbol',
            'receipt:id,receipt_number,sales_invoice_id',
            'clearedCashBankAccount:id,code,name',
        ]);
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }
        if (! empty($filters['due_from'])) {
            $query->where('due_date', '>=', $filters['due_from']);
        }
        if (! empty($filters['due_to'])) {
            $query->where('due_date', '<=', $filters['due_to']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['cheque_number', 'bank_name']);

        return $query->orderBy('due_date')->orderBy('cheque_number')->paginate($perPage);
    }

    /**
     * The cheque's money moves from "Cheques to collect" into a bank account in the cheque currency.
     */
    public function clear(CustomerCheque $cheque, int $cashBankAccountId, ?string $date, ?string $userId): CustomerCheque
    {
        return DB::transaction(function () use ($cheque, $cashBankAccountId, $date, $userId): CustomerCheque {
            $locked = CustomerCheque::query()->whereKey($cheque->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CustomerCheque::STATUS_IN_HAND) {
                abort(422, 'Only a cheque in hand can be cleared.', ['X-Error-Code' => 'CUSTOMER_CHEQUE_NOT_IN_HAND']);
            }
            $bank = CashBankAccount::query()->find($cashBankAccountId);
            if ($bank === null || ! $bank->is_active) {
                abort(422, 'Choose an active cash/bank account.', ['X-Error-Code' => 'TREASURY_ACCOUNT_INACTIVE']);
            }
            if ((int) $bank->currency_id !== (int) $locked->currency_id) {
                abort(422, 'The bank account must be in the cheque currency.', ['X-Error-Code' => 'PAYMENT_CASH_BANK_CURRENCY_MISMATCH']);
            }

            $day = $date !== null ? Carbon::parse($date)->toDateString() : now()->toDateString();
            $this->journalPostingService->customerChequeCleared($locked, $bank, $userId, $day);
            $locked->update([
                'status' => CustomerCheque::STATUS_CLEARED,
                'cleared_cash_bank_account_id' => $bank->id,
                'cleared_on' => $day,
                'updated_by' => $userId,
            ]);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * The cheque was refused: its clearing (if any) and its receipt are reversed, so the invoices it paid
     * are open again and the customer owes the money.
     */
    public function bounce(CustomerCheque $cheque, ?string $date, ?string $userId): CustomerCheque
    {
        return DB::transaction(function () use ($cheque, $date, $userId): CustomerCheque {
            $locked = CustomerCheque::query()->whereKey($cheque->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [CustomerCheque::STATUS_IN_HAND, CustomerCheque::STATUS_CLEARED], true)) {
                abort(422, 'This cheque is already settled.', ['X-Error-Code' => 'CUSTOMER_CHEQUE_NOT_OPEN']);
            }
            $day = $date !== null ? Carbon::parse($date)->toDateString() : now()->toDateString();
            if ($locked->status === CustomerCheque::STATUS_CLEARED) {
                $this->journalPostingService->reverse(CustomerCheque::REFERENCE_TYPE, (string) $locked->id, $userId, $day);
            }
            $receipt = CustomerReceipt::query()->findOrFail($locked->customer_receipt_id);
            $this->customerReceiptService->reverse($receipt, $userId, false, true);

            $locked->update([
                'status' => CustomerCheque::STATUS_BOUNCED,
                'bounced_on' => $day,
                'updated_by' => $userId,
            ]);

            return $this->find((string) $locked->id);
        });
    }

    public function find(string $id): CustomerCheque
    {
        return CustomerCheque::query()->with([
            'customer:id,customer_code,name',
            'currency:id,code,symbol',
            'receipt:id,receipt_number,sales_invoice_id',
            'clearedCashBankAccount:id,code,name',
        ])->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(CustomerCheque $cheque): array
    {
        return [
            'id' => (string) $cheque->id,
            'cheque_number' => $cheque->cheque_number,
            'bank_name' => $cheque->bank_name,
            'due_date' => $cheque->due_date?->toDateString(),
            'amount' => (string) $cheque->amount,
            'currency_id' => (int) $cheque->currency_id,
            'currency' => $cheque->currency !== null ? ['id' => (int) $cheque->currency->id, 'code' => $cheque->currency->code, 'symbol' => $cheque->currency->symbol] : null,
            'status' => $cheque->status,
            'customer' => $cheque->customer !== null ? ['id' => (string) $cheque->customer->id, 'code' => $cheque->customer->customer_code, 'name' => $cheque->customer->name] : null,
            'customer_receipt_id' => (string) $cheque->customer_receipt_id,
            'receipt_number' => $cheque->receipt?->receipt_number,
            'sales_invoice_id' => $cheque->receipt?->sales_invoice_id,
            'cleared_cash_bank_account' => $cheque->clearedCashBankAccount !== null
                ? ['id' => (int) $cheque->clearedCashBankAccount->id, 'code' => $cheque->clearedCashBankAccount->code, 'name' => $cheque->clearedCashBankAccount->name]
                : null,
            'cleared_on' => $cheque->cleared_on?->toDateString(),
            'bounced_on' => $cheque->bounced_on?->toDateString(),
        ];
    }
}
