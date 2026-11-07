<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerReceipt\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\CustomerCheque\Models\CustomerCheque;
use App\Modules\Sales\CustomerReceipt\DTOs\CustomerReceiptResponseData;
use App\Modules\Sales\CustomerReceipt\Enums\CustomerReceiptStatus;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceiptAllocation;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoicePayment;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Treasury\Support\PaymentCashBankAccount;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\CurrencyCashRound;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use App\Support\PaymentAllocation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerReceiptService
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly WarehouseService $warehouseService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * @param  array{
     *   status?:string|null,
     *   customer_id?:string|null,
     *   search?:string|null,
     *   from?:string|null,
     *   to?:string|null
     * }  $filters
     * @return LengthAwarePaginator<int, CustomerReceipt>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = CustomerReceipt::query()
            ->with([
                'customer:id,customer_code,name,phone,status,is_system',
                'currency:id,code,name,symbol',
                'paymentMethod:id,name,code,type,requires_reference,is_active',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('allocations');

        if (! empty($filters['status'])) {
            $status = CustomerReceiptStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }

        ListPagination::applySearch($query, $filters['search'] ?? null, ['receipt_number']);

        if (! empty($filters['from'])) {
            $query->where('payment_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('payment_date', '<=', $filters['to']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(string $id): CustomerReceipt
    {
        return CustomerReceipt::query()
            ->with([
                'customer',
                'currency',
                'paymentMethod',
                'createdByUser',
                'postedByUser',
                'allocations.salesInvoice',
            ])
            ->withCount('allocations')
            ->findOrFail($id);
    }

    /**
     * Posted sales invoices for this customer that still have an open balance, in any currency.
     *
     * @return list<array<string, mixed>>
     */
    public function openInvoices(string $customerId, int $currencyId): array
    {
        if ($customerId === '' || $currencyId <= 0) {
            $this->fail('Customer and currency are required.', 'CUSTOMER_RECEIPT_FILTER_REQUIRED');
        }

        $query = SalesInvoice::query()
            ->with('currency:id,code,name,symbol')
            ->where('customer_id', $customerId)
            ->where('status', SalesInvoiceStatus::Posted->value)
            ->where('net_to_pay', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('invoice_number');

        $this->warehouseService->applyVisibleDocumentConstraint($query);

        return $query->get()
            ->map(fn (SalesInvoice $invoice): array => CustomerReceiptResponseData::openInvoice($invoice))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): CustomerReceipt
    {
        $customer = SalesInvoiceRules::assertCustomer((string) $data['customer_id']);
        $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);

        return DB::transaction(function () use ($data, $userId, $customer): CustomerReceipt {
            $receipt = CustomerReceipt::query()->create([
                ...$this->headerAttributes($data, null),
                // Set only for receipts made by a sales invoice's payments (not accepted from the API).
                ...(isset($data['branch_id']) ? ['branch_id' => (int) $data['branch_id']] : []),
                ...(isset($data['sales_invoice_id']) ? ['sales_invoice_id' => (string) $data['sales_invoice_id']] : []),
                'customer_id' => $customer->id,
                'status' => CustomerReceiptStatus::Draft,
                'created_by' => $userId,
            ]);

            $this->replaceAllocations($receipt, $data['allocations'] ?? []);

            return $this->find($receipt->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(CustomerReceipt $receipt, array $data): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $data): CustomerReceipt {
            $locked = $this->lockDraft($receipt);
            $customer = SalesInvoiceRules::assertCustomer((string) $data['customer_id']);
            $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);

            $partyChanged = (string) $customer->id !== (string) $locked->customer_id
                || (int) $data['currency_id'] !== (int) $locked->currency_id;
            if ($partyChanged && $locked->allocations()->exists()) {
                $this->fail(
                    'Remove allocations before changing the customer or currency.',
                    'CUSTOMER_RECEIPT_HEADER_LOCKED',
                );
            }

            $locked->update([
                ...$this->headerAttributes($data, $locked),
                'customer_id' => $customer->id,
            ]);
            $locked->refresh();
            $this->refreshAppliedAmounts($locked);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $allocations
     */
    public function syncAllocations(CustomerReceipt $receipt, array $allocations): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $allocations): CustomerReceipt {
            $locked = $this->lockDraft($receipt);
            $this->replaceAllocations($locked, $allocations);

            return $this->find($locked->id);
        });
    }

    public function delete(CustomerReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $locked = $this->lockDraft($receipt);
            $locked->delete();
        });
    }

    public function post(CustomerReceipt $receipt, ?string $userId): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): CustomerReceipt {
            $locked = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            app(DocumentNumberService::class)->assign($locked, 'receipt_number', DocumentType::CustomerReceipt, $locked->branch_id !== null ? (int) $locked->branch_id : null, $locked->payment_date);
            if ($locked->status !== CustomerReceiptStatus::Draft) {
                $this->fail('Only a draft customer receipt can be posted.', 'CUSTOMER_RECEIPT_NOT_DRAFT');
            }

            if (! PaymentAllocation::isPositive($locked->amount)) {
                $this->fail('Receipt amount must be greater than zero.', 'CUSTOMER_RECEIPT_AMOUNT_INVALID');
            }

            if ($locked->sales_invoice_id === null) {
                $this->applyCashRounding($locked);
                $locked->refresh();
            }
            $this->refreshAppliedAmounts($locked);

            $allocations = CustomerReceiptAllocation::query()
                ->where('customer_receipt_id', $locked->id)
                ->orderBy('id')
                ->get();

            // Allocations may be less than the amount: the rest stays on the customer's account as credit.
            $allocatedSum = PaymentAllocation::sum($allocations->pluck('amount')->all());
            if (PaymentAllocation::exceeds($allocatedSum, $locked->amount)) {
                $this->fail('Allocations are more than the receipt amount.', 'CUSTOMER_RECEIPT_ALLOCATION_MISMATCH');
            }
            $unapplied = PaymentAllocation::subtract($locked->amount, $allocatedSum) ?? '0';

            $method = $this->assertPaymentMethod((int) $locked->payment_method_id, $locked->reference);
            $isCheque = $method->type === PaymentMethodType::Cheque;
            if ($isCheque) {
                if ($this->nullableString($locked->cheque_number) === null || $locked->cheque_due_date === null) {
                    $this->fail('Enter the cheque number and due date.', 'CUSTOMER_RECEIPT_CHEQUE_DETAILS_REQUIRED');
                }
                // The money waits in "Cheques to collect" until the cheque clears into a bank account.
                $locked->cash_bank_account_id = null;
            } else {
                $locked->cheque_number = null;
                $locked->cheque_bank = null;
                $locked->cheque_due_date = null;
                $locked->cash_bank_account_id = PaymentCashBankAccount::resolve(
                    $locked->cash_bank_account_id,
                    (int) $locked->payment_method_id,
                    $locked->branch_id !== null ? (int) $locked->branch_id : null,
                    (int) $locked->currency_id,
                    true,
                );
            }
            $locked->save();
            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
            if (PaymentAllocation::isPositive($unapplied) && $customer->is_system) {
                $this->fail('A walk-in customer cannot keep credit. Allocate the whole amount.', 'CUSTOMER_RECEIPT_WALK_IN_NO_CREDIT');
            }

            $invoiceIds = $allocations->pluck('sales_invoice_id')->map(fn ($id): string => (string) $id)->all();
            $invoices = SalesInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($invoices->count() !== count($invoiceIds)) {
                $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation->sales_invoice_id);
                if (! $invoice instanceof SalesInvoice) {
                    $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
                }

                $this->warehouseService->assertDocumentVisible($invoice);
                $this->assertInvoiceOpenFor($locked, $invoice, (string) $allocation->applied_amount);

                $paid = PaymentAllocation::add($invoice->paid_total, $allocation->applied_amount);
                $invoice->update([
                    'paid_total' => $paid,
                    'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid, $invoice->credited_total),
                ]);
            }

            foreach ($this->appliedByCurrency($allocations, $invoices) as $currencyId => $applied) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    $currencyId,
                    '0',
                    $applied,
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    $locked->payment_date->toDateString(),
                );
            }
            if (PaymentAllocation::isPositive($unapplied)) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    (int) $locked->currency_id,
                    '0',
                    $unapplied,
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    $locked->payment_date->toDateString(),
                );
            }

            $this->journalPostingService->customerReceipt($locked, $userId);

            $locked->update([
                'status' => CustomerReceiptStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            if ($isCheque) {
                CustomerCheque::query()->create([
                    'customer_receipt_id' => $locked->id,
                    'customer_id' => $locked->customer_id,
                    'branch_id' => $locked->branch_id,
                    'cheque_number' => (string) $locked->cheque_number,
                    'bank_name' => $locked->cheque_bank,
                    'due_date' => $locked->cheque_due_date,
                    'amount' => $locked->amount,
                    'currency_id' => $locked->currency_id,
                    'exchange_rate' => $locked->exchange_rate,
                    'status' => CustomerCheque::STATUS_IN_HAND,
                    'updated_by' => $userId,
                ]);
            }

            return $this->find($locked->id);
        });
    }

    /**
     * @param  bool  $fromInvoice  true when the sales invoice that made this receipt is being reversed
     */
    /**
     * @param  bool  $fromInvoice  true when the sales invoice that made this receipt is being reversed
     * @param  bool  $fromCheque  true when the receipt's cheque bounced (allowed for invoice receipts too)
     */
    public function reverse(CustomerReceipt $receipt, ?string $userId = null, bool $fromInvoice = false, bool $fromCheque = false): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $userId, $fromInvoice, $fromCheque): CustomerReceipt {
            $locked = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($locked->sales_invoice_id !== null && ! $fromInvoice && ! $fromCheque) {
                $this->fail('This receipt was taken with its sales invoice. Reverse the invoice instead.', 'CUSTOMER_RECEIPT_MANAGED_BY_INVOICE');
            }
            if ($locked->status !== CustomerReceiptStatus::Posted) {
                $this->fail('Only a posted customer receipt can be reversed.', 'CUSTOMER_RECEIPT_NOT_POSTED');
            }
            $cheque = CustomerCheque::query()->where('customer_receipt_id', $locked->id)->lockForUpdate()->first();
            if ($cheque !== null && $cheque->status === CustomerCheque::STATUS_CLEARED && ! $fromCheque) {
                $this->fail('This cheque has cleared. Mark it bounced instead.', 'CUSTOMER_RECEIPT_CHEQUE_CLEARED');
            }

            $allocations = CustomerReceiptAllocation::query()
                ->where('customer_receipt_id', $locked->id)
                ->orderBy('id')
                ->get();

            $invoiceIds = $allocations->pluck('sales_invoice_id')->map(fn ($id): string => (string) $id)->all();
            $invoices = SalesInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation->sales_invoice_id);
                if (! $invoice instanceof SalesInvoice || $invoice->status !== SalesInvoiceStatus::Posted) {
                    $this->fail('An allocated invoice is no longer open.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
                }

                $this->warehouseService->assertDocumentVisible($invoice);
                $paid = PaymentAllocation::subtract($invoice->paid_total, $allocation->applied_amount);
                if ($paid === null) {
                    $this->fail(
                        'This receipt cannot be reversed because an invoice paid total is lower than the allocation.',
                        'CUSTOMER_RECEIPT_PAID_TOTAL_UNDERFLOW',
                    );
                }

                $invoice->update([
                    'paid_total' => $paid,
                    'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid, $invoice->credited_total),
                ]);
            }

            $customer = Customer::query()->findOrFail($locked->customer_id);
            foreach ($this->appliedByCurrency($allocations, $invoices) as $currencyId => $applied) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    $currencyId,
                    $applied,
                    '0',
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }
            // Credit still on the receipt goes back off the customer's account.
            $unapplied = PaymentAllocation::subtract($locked->amount, PaymentAllocation::sum($allocations->pluck('amount')->all())) ?? '0';
            if (PaymentAllocation::isPositive($unapplied)) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    (int) $locked->currency_id,
                    $unapplied,
                    '0',
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }

            foreach ($allocations->where('applied_later', true) as $later) {
                $this->journalPostingService->reverse(JournalPostingService::CUSTOMER_CREDIT_APPLICATION, (string) $later->id, $userId);
            }
            $this->journalPostingService->reverse(CustomerReceipt::REFERENCE_TYPE, (string) $locked->id, $userId);

            $locked->update(['status' => CustomerReceiptStatus::Reversed]);
            if ($cheque !== null && $cheque->status === CustomerCheque::STATUS_IN_HAND && ! $fromCheque) {
                // Reversed before it cleared (e.g. the invoice was reversed): the cheque is given back.
                $cheque->update(['status' => CustomerCheque::STATUS_CANCELLED, 'updated_by' => $userId]);
            }

            return $this->find($locked->id);
        });
    }

    /**
     * Applies credit left on a posted receipt to open invoices of the same customer.
     * Each row: sales_invoice_id, amount (receipt currency).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function applyCredit(CustomerReceipt $receipt, array $rows, ?string $userId, ?string $date = null): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $rows, $userId, $date): CustomerReceipt {
            $locked = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CustomerReceiptStatus::Posted) {
                $this->fail('Only a posted receipt has credit to apply.', 'CUSTOMER_RECEIPT_NOT_POSTED');
            }
            $unapplied = $locked->unappliedAmount();
            $total = PaymentAllocation::sum(array_map(fn (array $r): string => PaymentAllocation::normalize($r['amount'] ?? 0), $rows));
            if ($rows === [] || ! PaymentAllocation::isPositive($total)) {
                $this->fail('Choose at least one invoice and an amount.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
            }
            if (PaymentAllocation::exceeds($total, $unapplied)) {
                $this->fail('That is more than the credit left on this receipt.', 'CUSTOMER_RECEIPT_CREDIT_EXCEEDED');
            }

            $customer = Customer::query()->findOrFail($locked->customer_id);
            $day = $date ?? now()->toDateString();
            foreach ($rows as $row) {
                $amount = PaymentAllocation::normalize($row['amount'] ?? 0);
                if (! PaymentAllocation::isPositive($amount)) {
                    continue;
                }
                $invoice = SalesInvoice::query()->whereKey((string) ($row['sales_invoice_id'] ?? ''))->lockForUpdate()->first();
                if (! $invoice instanceof SalesInvoice) {
                    $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
                }
                $this->warehouseService->assertDocumentVisible($invoice);
                $settlement = $this->appliedSettlement($locked, $invoice, $amount);
                $this->assertInvoiceOpenFor($locked, $invoice, $settlement['applied_amount']);

                $allocation = CustomerReceiptAllocation::query()->create([
                    'customer_receipt_id' => $locked->id,
                    'sales_invoice_id' => $invoice->id,
                    'amount' => $amount,
                    ...$settlement,
                    'applied_later' => true,
                    'applied_at' => now(),
                ]);

                $paid = PaymentAllocation::add($invoice->paid_total, $settlement['applied_amount']);
                $invoice->update([
                    'paid_total' => $paid,
                    'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid, $invoice->credited_total),
                ]);

                // The customer's balance moves from the receipt currency to the invoice currency.
                if ((int) $invoice->currency_id !== (int) $locked->currency_id || ! PaymentAllocation::same($amount, $settlement['applied_amount'])) {
                    $this->customerLedgerService->postEntry($customer, (int) $locked->currency_id, $amount, '0', LedgerReferenceType::Payment, (string) $locked->id, $day);
                    $this->customerLedgerService->postEntry($customer, (int) $invoice->currency_id, '0', $settlement['applied_amount'], LedgerReferenceType::Payment, (string) $locked->id, $day);
                }

                $this->journalPostingService->customerCreditApplication($allocation, $locked, $invoice, $userId, $day);
            }

            return $this->find($locked->id);
        });
    }

    /**
     * Takes back customer credit that was applied to an invoice (the invoice is being reversed): the amount
     * returns to the receipt as credit and the invoice is open again by that much.
     */
    public function unapplyCredit(CustomerReceiptAllocation $allocation, ?string $userId): void
    {
        DB::transaction(function () use ($allocation, $userId): void {
            $locked = CustomerReceiptAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if (! $locked->applied_later) {
                $this->fail('Only credit applied later can be taken back.', 'CUSTOMER_RECEIPT_ALLOCATION_MISMATCH');
            }
            $receipt = CustomerReceipt::query()->findOrFail($locked->customer_receipt_id);
            $invoice = SalesInvoice::query()->whereKey($locked->sales_invoice_id)->lockForUpdate()->firstOrFail();
            $day = now()->toDateString();

            $this->journalPostingService->reverse(JournalPostingService::CUSTOMER_CREDIT_APPLICATION, (string) $locked->id, $userId, $day);
            if ((int) $invoice->currency_id !== (int) $receipt->currency_id || ! PaymentAllocation::same($locked->amount, $locked->applied_amount)) {
                $customer = Customer::query()->findOrFail($receipt->customer_id);
                $this->customerLedgerService->postEntry($customer, (int) $invoice->currency_id, (string) $locked->applied_amount, '0', LedgerReferenceType::Payment, (string) $receipt->id, $day);
                $this->customerLedgerService->postEntry($customer, (int) $receipt->currency_id, '0', (string) $locked->amount, LedgerReferenceType::Payment, (string) $receipt->id, $day);
            }

            $paid = PaymentAllocation::subtract($invoice->paid_total, $locked->applied_amount) ?? '0';
            $invoice->update([
                'paid_total' => $paid,
                'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid, $invoice->credited_total),
            ]);
            $locked->delete();
        });
    }

    /**
     * Posted receipts of a customer with credit left, oldest first. `in_invoice_currency` is the credit's
     * value in `$currencyId` at today's rate (null when no currency is given).
     *
     * @return list<array<string, mixed>>
     */
    public function availableCredit(string $customerId, ?int $currencyId = null): array
    {
        $receipts = CustomerReceipt::query()
            ->with('currency:id,code,symbol')
            ->where('customer_id', $customerId)
            ->where('status', CustomerReceiptStatus::Posted->value)
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($receipts as $receipt) {
            $unapplied = $receipt->unappliedAmount();
            if (! PaymentAllocation::isPositive($unapplied)) {
                continue;
            }
            $rows[] = [
                'receipt_id' => (string) $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'payment_date' => $receipt->payment_date?->toDateString(),
                'currency_id' => (int) $receipt->currency_id,
                'currency_code' => $receipt->currency?->code,
                'unapplied_amount' => $unapplied,
                'in_invoice_currency' => $currencyId !== null ? $this->toCurrency($receipt, $unapplied, $currencyId) : null,
            ];
        }

        return $rows;
    }

    /**
     * Applies the customer's credit to an invoice, oldest receipts first, up to `$invoiceAmount` (invoice currency).
     * Returns what was applied, in the invoice currency.
     */
    public function applyCreditToInvoice(SalesInvoice $invoice, string $invoiceAmount, ?string $userId, ?string $date = null): string
    {
        $left = PaymentAllocation::normalize($invoiceAmount);
        $applied = '0';
        foreach ($this->availableCredit((string) $invoice->customer_id, (int) $invoice->currency_id) as $credit) {
            if (! PaymentAllocation::isPositive($left)) {
                break;
            }
            $receipt = CustomerReceipt::query()->findOrFail($credit['receipt_id']);
            $worth = (string) $credit['in_invoice_currency'];
            $take = PaymentAllocation::exceeds($worth, $left) ? $left : $worth;
            // Receipt-currency amount worth `$take` in the invoice currency (all of it when it covers the rest).
            $receiptAmount = PaymentAllocation::same($take, $worth)
                ? (string) $credit['unapplied_amount']
                : $this->fromCurrency($receipt, $take, (int) $invoice->currency_id);
            if (PaymentAllocation::exceeds($receiptAmount, (string) $credit['unapplied_amount'])) {
                $receiptAmount = (string) $credit['unapplied_amount'];
            }
            $this->applyCredit($receipt, [['sales_invoice_id' => (string) $invoice->id, 'amount' => $receiptAmount]], $userId, $date);
            $allocation = CustomerReceiptAllocation::query()->where('customer_receipt_id', $receipt->id)->where('sales_invoice_id', $invoice->id)->orderByDesc('id')->first();
            $done = (string) ($allocation?->applied_amount ?? $take);
            $applied = PaymentAllocation::add($applied, $done);
            $left = PaymentAllocation::subtract($left, $done) ?? '0';
        }

        return $applied;
    }

    /**
     * Receipt-currency amount in another currency (today's rate for that currency, receipt rate for its own).
     */
    private function toCurrency(CustomerReceipt $receipt, string $amount, int $currencyId): string
    {
        $same = $currencyId === (int) $receipt->currency_id;
        $targetRate = $same ? (string) $receipt->exchange_rate : ExchangeRateSnapshot::resolve($this->exchangeRateService, $currencyId, null, 'CUSTOMER_RECEIPT');

        return PaymentAllocation::toInvoiceCurrency($amount, (string) $receipt->exchange_rate, $targetRate, $same);
    }

    /**
     * Amount in `$currencyId` expressed in the receipt currency (inverse of toCurrency).
     */
    private function fromCurrency(CustomerReceipt $receipt, string $amount, int $currencyId): string
    {
        $same = $currencyId === (int) $receipt->currency_id;
        $sourceRate = $same ? (string) $receipt->exchange_rate : ExchangeRateSnapshot::resolve($this->exchangeRateService, $currencyId, null, 'CUSTOMER_RECEIPT');

        return PaymentAllocation::toInvoiceCurrency($amount, $sourceRate, (string) $receipt->exchange_rate, $same);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data, ?CustomerReceipt $existing): array
    {
        $currencyId = (int) $data['currency_id'];

        return [
            'currency_id' => $currencyId,
            'exchange_rate' => ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                $currencyId,
                $data['exchange_rate']
                    ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
                'CUSTOMER_RECEIPT',
            ),
            'payment_method_id' => (int) $data['payment_method_id'],
            'payment_date' => Carbon::parse((string) $data['payment_date'])->toDateString(),
            'amount' => PaymentAllocation::normalize($data['amount']),
            'reference' => $this->nullableString($data['reference'] ?? null),
            'cash_bank_account_id' => array_key_exists('cash_bank_account_id', $data)
                ? ($data['cash_bank_account_id'] !== null && $data['cash_bank_account_id'] !== '' ? (int) $data['cash_bank_account_id'] : null)
                : $existing?->cash_bank_account_id,
            'notes' => $this->nullableString($data['notes'] ?? null),
            'cheque_number' => $this->nullableString($data['cheque_number'] ?? null),
            'cheque_bank' => $this->nullableString($data['cheque_bank'] ?? null),
            'cheque_due_date' => isset($data['cheque_due_date']) && $data['cheque_due_date'] !== ''
                ? Carbon::parse((string) $data['cheque_due_date'])->toDateString()
                : null,
        ];
    }

    /**
     * A payment taken when a sales invoice is posted becomes a posted receipt allocated to that invoice,
     * in the invoice's branch, currency and rate.
     */
    public function postForInvoicePayment(SalesInvoice $invoice, SalesInvoicePayment $payment, ?string $userId, ?string $paymentDate = null, ?string $allocate = null): CustomerReceipt
    {
        // `$allocate` (payment currency) is how much goes to the invoice; the rest stays as customer credit.
        $allocation = $allocate ?? (string) $payment->amount;
        $receipt = $this->create([
            'customer_id' => (string) $invoice->customer_id,
            // The receipt is in the currency the money came in; allocation converts it to the invoice currency.
            'currency_id' => $payment->currency_id !== null ? (int) $payment->currency_id : (int) $invoice->currency_id,
            'exchange_rate' => $payment->currency_id !== null && $payment->exchange_rate !== null
                ? (string) $payment->exchange_rate
                : (string) $invoice->exchange_rate,
            'payment_method_id' => (int) $payment->payment_method_id,
            'cash_bank_account_id' => $payment->cash_bank_account_id,
            'payment_date' => $paymentDate ?? $invoice->invoice_date->toDateString(),
            'amount' => (string) $payment->amount,
            'reference' => $payment->reference,
            'cheque_number' => $payment->cheque_number,
            'cheque_bank' => $payment->cheque_bank,
            'cheque_due_date' => $payment->cheque_due_date?->toDateString(),
            'branch_id' => (int) $invoice->branch_id,
            'sales_invoice_id' => (string) $invoice->id,
            'allocations' => PaymentAllocation::isPositive($allocation)
                ? [['sales_invoice_id' => (string) $invoice->id, 'amount' => $allocation]]
                : [],
        ], $userId);

        return $this->post($receipt, $userId);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceAllocations(CustomerReceipt $receipt, array $rows): void
    {
        $seen = [];
        $payload = [];
        foreach ($rows as $row) {
            $invoiceId = (string) ($row['sales_invoice_id'] ?? '');
            if ($invoiceId === '' || isset($seen[$invoiceId])) {
                $this->fail('Each invoice can be allocated only once on a receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }
            $seen[$invoiceId] = true;
            $amount = PaymentAllocation::normalize($row['amount'] ?? 0);
            if (! PaymentAllocation::isPositive($amount)) {
                $this->fail('Each allocation must be greater than zero.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
            }
            $payload[] = ['sales_invoice_id' => $invoiceId, 'amount' => $amount];
        }

        $invoices = SalesInvoice::query()
            ->whereIn('id', array_keys($seen))
            ->get()
            ->keyBy('id');

        foreach ($payload as $index => $row) {
            $invoice = $invoices->get($row['sales_invoice_id']);
            if (! $invoice instanceof SalesInvoice) {
                $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }
            $this->assertInvoiceBelongs($receipt, $invoice);
            $this->warehouseService->assertDocumentVisible($invoice);
            $payload[$index] += $this->appliedSettlement($receipt, $invoice, $row['amount']);
        }

        $receipt->allocations()->delete();
        foreach ($payload as $row) {
            CustomerReceiptAllocation::query()->create([
                'customer_receipt_id' => $receipt->id,
                'sales_invoice_id' => $row['sales_invoice_id'],
                'amount' => $row['amount'],
                'applied_amount' => $row['applied_amount'],
                'applied_exchange_rate' => $row['applied_exchange_rate'],
                'exchange_difference' => $row['exchange_difference'],
            ]);
        }
        $receipt->touch();
    }

    private function refreshAppliedAmounts(CustomerReceipt $receipt): void
    {
        $allocations = CustomerReceiptAllocation::query()
            ->where('customer_receipt_id', $receipt->id)
            ->with('salesInvoice')
            ->get();

        foreach ($allocations as $allocation) {
            $invoice = $allocation->salesInvoice;
            if (! $invoice instanceof SalesInvoice) {
                $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }

            $settlement = $this->appliedSettlement($receipt, $invoice, (string) $allocation->amount);
            $allocation->update($settlement);
        }
    }

    private function applyCashRounding(CustomerReceipt $receipt): void
    {
        $currency = Currency::query()->find((int) $receipt->currency_id);
        $rounded = CurrencyCashRound::round(
            (string) $receipt->amount,
            $currency?->smallest_unit,
            $currency?->round_limit,
        );
        if (PaymentAllocation::same($rounded, $receipt->amount)) {
            return;
        }
        if (! PaymentAllocation::isPositive($rounded)) {
            $this->fail('Receipt amount must be greater than zero.', 'CUSTOMER_RECEIPT_AMOUNT_INVALID');
        }

        $allocations = CustomerReceiptAllocation::query()
            ->where('customer_receipt_id', $receipt->id)
            ->orderBy('id')
            ->get();
        // Part of the receipt stays as customer credit: only the header moves, as long as it still covers the allocations.
        $allocatedSum = PaymentAllocation::sum($allocations->pluck('amount')->all());
        if (! PaymentAllocation::same($allocatedSum, $receipt->amount)) {
            if (PaymentAllocation::exceeds($allocatedSum, $rounded)) {
                $this->fail('Cash rounding cannot be applied to these allocations.', 'CUSTOMER_RECEIPT_ALLOCATION_MISMATCH');
            }
            $receipt->update(['amount' => $rounded]);

            return;
        }
        $nextAmounts = PaymentAllocation::amountsForRoundedHeader(
            $allocations->map(fn (CustomerReceiptAllocation $row): string => (string) $row->amount)->all(),
            $rounded,
        );
        if ($nextAmounts === null) {
            $this->fail('Cash rounding cannot be applied to these allocations.', 'CUSTOMER_RECEIPT_ALLOCATION_MISMATCH');
        }

        foreach ($allocations->values() as $index => $allocation) {
            $allocation->update(['amount' => $nextAmounts[$index]]);
        }
        $receipt->update(['amount' => $rounded]);
    }

    /**
     * @return array{applied_amount: string, applied_exchange_rate: string, exchange_difference: string}
     */
    private function appliedSettlement(CustomerReceipt $receipt, SalesInvoice $invoice, string $amount): array
    {
        $sameCurrency = (int) $invoice->currency_id === (int) $receipt->currency_id;
        $invoiceRate = $sameCurrency
            ? (string) $receipt->exchange_rate
            : ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                (int) $invoice->currency_id,
                null,
                'CUSTOMER_RECEIPT',
            );
        $converted = PaymentAllocation::toInvoiceCurrency($amount, (string) $receipt->exchange_rate, $invoiceRate, $sameCurrency);
        $invoiceCurrency = Currency::query()->find((int) $invoice->currency_id);
        $settled = PaymentAllocation::settle(
            $converted,
            (string) $invoice->net_to_pay,
            $invoiceCurrency?->allowed_difference_in_receipt,
            'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN',
        );

        return [
            'applied_amount' => $settled['applied_amount'],
            'applied_exchange_rate' => $invoiceRate,
            'exchange_difference' => $settled['exchange_difference'],
        ];
    }

    /**
     * @param  Collection<int, CustomerReceiptAllocation>  $allocations
     * @param  Collection<string, SalesInvoice>  $invoices
     * @return array<int, string>
     */
    private function appliedByCurrency($allocations, $invoices): array
    {
        $totals = [];
        foreach ($allocations as $allocation) {
            $invoice = $invoices->get($allocation->sales_invoice_id);
            if (! $invoice instanceof SalesInvoice) {
                continue;
            }
            $currencyId = (int) $invoice->currency_id;
            $totals[$currencyId] = PaymentAllocation::add($totals[$currencyId] ?? '0', $allocation->applied_amount);
        }

        return $totals;
    }

    private function assertInvoiceBelongs(CustomerReceipt $receipt, SalesInvoice $invoice): void
    {
        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            $this->fail('Only a posted invoice can be allocated.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
        }

        if ((string) $invoice->customer_id !== (string) $receipt->customer_id) {
            $this->fail('Invoice customer does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
        }
    }

    private function assertInvoiceOpenFor(CustomerReceipt $receipt, SalesInvoice $invoice, string $amount): void
    {
        $this->assertInvoiceBelongs($receipt, $invoice);

        if (! PaymentAllocation::isPositive($amount) || PaymentAllocation::exceeds($amount, $invoice->net_to_pay)) {
            $this->fail('Allocation exceeds the invoice open amount.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
        }
    }

    private function assertPaymentMethod(int $paymentMethodId, mixed $reference): PaymentMethod
    {
        $method = PaymentMethod::query()->find($paymentMethodId);
        if (! $method instanceof PaymentMethod || ! $method->is_active || $method->type === PaymentMethodType::Credit) {
            $this->fail('Choose an active payment method that is not account credit.', 'PAYMENT_METHOD_NOT_ALLOWED');
        }

        if ($method->requires_reference && $this->nullableString($reference) === null) {
            $this->fail('A reference is required for this payment method.', 'PAYMENT_REFERENCE_REQUIRED');
        }

        return $method;
    }

    private function lockDraft(CustomerReceipt $receipt): CustomerReceipt
    {
        $locked = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== CustomerReceiptStatus::Draft) {
            $this->fail('Only a draft customer receipt can be changed.', 'CUSTOMER_RECEIPT_NOT_DRAFT');
        }

        return $locked;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function fail(string $message, string $code): never
    {
        abort(422, $message, ['X-Error-Code' => $code]);
    }
}
