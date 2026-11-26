<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\CustomerCheque\Models\CustomerCheque;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceiptAllocation;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptService;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoicePayment;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Treasury\Support\PaymentCashBankAccount;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\Decimal;
use App\Support\ExchangeRateSnapshot;
use App\Support\PaymentAllocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payments: the payment rows of an invoice, checked and settled (at the sale on the invoice itself, later as receipts).
 * Part of the sales invoice posting pipeline (see SalesInvoiceService).
 */
class SalesInvoicePaymentService
{
    public function __construct(
        private readonly SalesInvoiceQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly JournalPostingService $journalPostingService,
        private readonly SalesInvoiceDraftService $draft,
    ) {}

    /**
     * Payments taken at the moment of sale (draft only). Each row: payment_method_id, cash_bank_account_id
     * (default: the method's account for the invoice branch), amount, reference, tendered (cash).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncPayments(SalesInvoice $invoice, array $rows, bool $keepExtraAsCredit = false): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $rows, $keepExtraAsCredit): SalesInvoice {
            $locked = $this->draft->lockDraft($invoice);
            $this->assertPayments($locked, $rows, false, $keepExtraAsCredit);

            SalesInvoicePayment::query()->where('sales_invoice_id', $locked->id)->delete();
            foreach (array_values($rows) as $index => $row) {
                $this->createPaymentRow($locked, $row, $index + 1);
            }
            $locked->update(['keep_extra_as_credit' => $keepExtraAsCredit]);

            return $this->queryService->find($locked->id);
        });
    }

    /**
     * Money received later against a posted invoice. Each row becomes a posted receipt allocated to this
     * invoice (same rows as at sale), dated `$paymentDate` (default today).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function recordPayments(SalesInvoice $invoice, array $rows, ?string $paymentDate, ?string $userId, bool $keepExtraAsCredit = false): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $rows, $paymentDate, $userId, $keepExtraAsCredit): SalesInvoice {
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesInvoiceStatus::Posted) {
                abort(422, 'Only a posted sales invoice can take a payment.', ['X-Error-Code' => 'SALES_INVOICE_NOT_POSTED']);
            }
            $this->warehouseService->assertDocumentVisible($locked);
            if ($rows === []) {
                abort(422, 'Add at least one payment.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENT_AMOUNT_INVALID']);
            }

            $total = $this->assertPayments($locked, $rows, false, $keepExtraAsCredit);
            if (! $keepExtraAsCredit && $this->exceedsWithTolerance($locked, $total, (string) $locked->net_to_pay)) {
                abort(422, 'Payments are more than what is still open on the invoice.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENTS_EXCEED_TOTAL']);
            }

            $sort = (int) SalesInvoicePayment::query()->where('sales_invoice_id', $locked->id)->max('sort_order');
            $payments = new Collection;
            foreach (array_values($rows) as $row) {
                $payments->push($this->createPaymentRow($locked, $row, ++$sort));
            }
            $this->settleLater($locked, $payments, $userId, $paymentDate ?? now()->toDateString(), $keepExtraAsCredit);

            return $this->queryService->find($locked->id);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createPaymentRow(SalesInvoice $invoice, array $row, int $sortOrder): SalesInvoicePayment
    {
        $amount = Decimal::round($row['amount'], 4);
        if ($this->isCreditRow($row)) {
            // The customer's existing credit, in the invoice currency; applied from their receipts at post.
            return SalesInvoicePayment::query()->create([
                'sales_invoice_id' => $invoice->id,
                'kind' => SalesInvoicePayment::KIND_CREDIT,
                'payment_method_id' => null,
                'currency_id' => (int) $invoice->currency_id,
                'exchange_rate' => (string) $invoice->exchange_rate,
                'amount' => $amount,
                'sort_order' => $sortOrder,
            ]);
        }

        $method = PaymentMethod::query()->findOrFail((int) $row['payment_method_id']);
        $isCheque = $method->type === PaymentMethodType::Cheque;
        $currency = $this->paymentCurrency($invoice, $row);
        $tendered = $method->supports_change && isset($row['tendered']) && $row['tendered'] !== null && $row['tendered'] !== ''
            ? Decimal::round($row['tendered'], 4)
            : null;

        return SalesInvoicePayment::query()->create([
            'sales_invoice_id' => $invoice->id,
            'kind' => SalesInvoicePayment::KIND_PAYMENT,
            'payment_method_id' => $method->id,
            'cash_bank_account_id' => $isCheque ? null : $this->paymentAccountId($invoice, $method, $row['cash_bank_account_id'] ?? null, $currency['currency_id']),
            'currency_id' => $currency['currency_id'],
            'exchange_rate' => $currency['rate'],
            'amount' => $amount,
            'reference' => $this->draft->nullableString($row['reference'] ?? null),
            'tendered' => $tendered,
            'change_due' => $tendered !== null ? Decimal::round(Decimal::sub($tendered, $amount), 4) : null,
            ...$this->paymentChange($invoice, $method, $row, $currency, $amount, $tendered),
            'cheque_number' => $isCheque ? $this->draft->nullableString($row['cheque_number'] ?? null) : null,
            'cheque_bank' => $isCheque ? $this->draft->nullableString($row['cheque_bank'] ?? null) : null,
            'cheque_due_date' => $isCheque && ! empty($row['cheque_due_date']) ? Carbon::parse((string) $row['cheque_due_date'])->toDateString() : null,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isCreditRow(array $row): bool
    {
        return ($row['kind'] ?? SalesInvoicePayment::KIND_PAYMENT) === SalesInvoicePayment::KIND_CREDIT;
    }

    /**
     * Change handed back in another currency than the one paid (e.g. paid in USD, change in LBP).
     * Empty when there is no change or it is in the paid currency (then it is printed only, not posted).
     *
     * @param  array<string, mixed>  $row
     * @param  array{currency_id: int, rate: string, same: bool}  $currency
     * @return array<string, mixed>
     */
    private function paymentChange(SalesInvoice $invoice, PaymentMethod $method, array $row, array $currency, string $amount, ?string $tendered): array
    {
        $none = ['change_currency_id' => null, 'change_rate' => null, 'change_amount' => null, 'change_cash_bank_account_id' => null];
        $changeCurrencyId = isset($row['change_currency_id']) && $row['change_currency_id'] !== '' ? (int) $row['change_currency_id'] : null;
        if ($tendered === null || $changeCurrencyId === null || $changeCurrencyId === $currency['currency_id']) {
            return $none;
        }
        $due = Decimal::sub($tendered, $amount);
        if (! Decimal::isPositive($due)) {
            return $none;
        }
        $changeCurrency = Currency::query()->find($changeCurrencyId);
        if ($changeCurrency === null || ! $changeCurrency->is_active) {
            abort(422, 'Choose an active currency for the change.', ['X-Error-Code' => 'SALES_INVOICE_CHANGE_INVALID']);
        }
        $rate = ExchangeRateSnapshot::resolve($this->exchangeRateService, $changeCurrencyId, $row['change_rate'] ?? null, 'SALES_INVOICE_PAYMENT');
        $changeAmount = isset($row['change_amount']) && $row['change_amount'] !== '' && $row['change_amount'] !== null
            ? Decimal::round($row['change_amount'], 4)
            : Decimal::round(PaymentAllocation::toInvoiceCurrency($due, $currency['rate'], $rate, false), 4);

        return [
            'change_currency_id' => $changeCurrencyId,
            'change_rate' => $rate,
            'change_amount' => $changeAmount,
            'change_cash_bank_account_id' => PaymentCashBankAccount::resolve(
                $row['change_cash_bank_account_id'] ?? null,
                (int) $method->id,
                $invoice->branch_id !== null ? (int) $invoice->branch_id : null,
                $changeCurrencyId,
                false,
            ),
        ];
    }

    /**
     * A stored payment row as request-style data (to check it again at post).
     *
     * @return array<string, mixed>
     */
    public function paymentRowData(SalesInvoicePayment $payment): array
    {
        return [
            ...$payment->only([
                'kind', 'payment_method_id', 'cash_bank_account_id', 'currency_id', 'exchange_rate', 'amount', 'reference', 'tendered',
                'change_currency_id', 'change_rate', 'change_amount', 'change_cash_bank_account_id', 'cheque_number', 'cheque_bank',
            ]),
            'cheque_due_date' => $payment->cheque_due_date?->toDateString(),
        ];
    }

    /**
     * Payments taken at the counter, settled on the invoice itself (no receipts): customer credit first (it
     * was already paid), then money taken now, in row order. Each row keeps what it settled
     * (`applied_amount`) and, with `$keepExtra`, what it paid above the total (`extra_credit`, kept on the
     * invoice as customer credit). The cash side is posted in the invoice's own journal entry; a cheque
     * waits in "Cheques to collect" until it clears.
     *
     * @param  Collection<int, SalesInvoicePayment>  $payments
     */
    public function settleAtSale(SalesInvoice $invoice, Collection $payments, ?string $userId, bool $keepExtra): void
    {
        $day = $invoice->invoice_date->toDateString();
        $left = Decimal::isPositive((string) $invoice->grand_total) ? Decimal::round((string) $invoice->grand_total, 4) : '0';
        $left = $this->useCreditRows($invoice, $payments, $left, true, $userId, $day);

        $tolerance = $this->receiptTolerance($invoice);
        $branchId = $invoice->branch_id !== null ? (int) $invoice->branch_id : null;
        $money = $payments->reject(fn (SalesInvoicePayment $p): bool => $p->isCredit())->values();
        $settled = '0';
        $extra = '0';
        foreach ($money as $index => $payment) {
            $currency = $this->storedPaymentCurrency($invoice, $payment);
            $worth = $this->paymentInInvoiceCurrency($invoice, (string) $payment->amount, $currency);
            [$applied, $kept] = $this->splitPayment($worth, $left, $tolerance, $keepExtra, $index === $money->count() - 1);
            $left = Decimal::isPositive(Decimal::sub($left, $applied)) ? Decimal::round(Decimal::sub($left, $applied), 4) : '0';
            $settled = Decimal::add($settled, $applied);
            $extra = Decimal::add($extra, $kept);

            $method = PaymentMethod::query()->findOrFail((int) $payment->payment_method_id);
            $isCheque = $method->type === PaymentMethodType::Cheque;
            $payment->update([
                'at_sale' => true,
                'applied_amount' => $applied,
                'extra_credit' => Decimal::isPositive($kept) ? $kept : null,
                'cash_bank_account_id' => $isCheque
                    ? null
                    : PaymentCashBankAccount::resolve($payment->cash_bank_account_id, (int) $method->id, $branchId, $currency['currency_id'], true),
                'change_cash_bank_account_id' => $this->changeElsewhere($payment, $currency['currency_id'])
                    ? PaymentCashBankAccount::resolve($payment->change_cash_bank_account_id, (int) $method->id, $branchId, (int) $payment->change_currency_id, true)
                    : $payment->change_cash_bank_account_id,
            ]);
            if ($isCheque) {
                CustomerCheque::query()->create([
                    'customer_receipt_id' => null,
                    'sales_invoice_payment_id' => $payment->id,
                    'sales_invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'branch_id' => $invoice->branch_id,
                    'cheque_number' => (string) $payment->cheque_number,
                    'bank_name' => $payment->cheque_bank,
                    'due_date' => $payment->cheque_due_date,
                    'amount' => $payment->amount,
                    'currency_id' => $currency['currency_id'],
                    'exchange_rate' => $currency['rate'],
                    'status' => CustomerCheque::STATUS_IN_HAND,
                    'updated_by' => $userId,
                ]);
            }
        }

        $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        $paid = PaymentAllocation::add($locked->paid_total, $settled);
        $locked->update([
            'paid_total' => $paid,
            'net_to_pay' => PaymentAllocation::netToPay($locked->grand_total, $paid, $locked->credited_total),
            'extra_credit' => Decimal::round($extra, 4),
            'extra_credit_used' => '0',
        ]);

        // The customer's account: what the counter payments settled, plus any extra kept as credit
        // (not for walk-in: its sale was never written there either).
        $onAccount = Decimal::add($settled, $extra);
        $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
        if (! $customer->is_system && Decimal::isPositive($onAccount)) {
            $this->customerLedgerService->postEntry(
                $customer,
                (int) $locked->currency_id,
                '0',
                Decimal::round($onAccount, 4),
                LedgerReferenceType::SalePayment,
                (string) $locked->id,
                $day,
            );
        }
    }

    /**
     * Payments recorded later against a posted invoice: customer credit first, then each money row becomes a
     * posted receipt allocated to the invoice (dated `$date`). With `$keepExtra`, what is paid above what is
     * still open stays on the receipt as customer credit. Change in another currency is posted in the
     * receipt's own entry.
     *
     * @param  Collection<int, SalesInvoicePayment>  $payments
     */
    private function settleLater(SalesInvoice $invoice, Collection $payments, ?string $userId, string $date, bool $keepExtra): void
    {
        $receipts = app(CustomerReceiptService::class);
        $left = Decimal::isPositive((string) $invoice->net_to_pay) ? Decimal::round((string) $invoice->net_to_pay, 4) : '0';
        $left = $this->useCreditRows($invoice, $payments, $left, false, $userId, $date);

        $tolerance = $this->receiptTolerance($invoice);
        $branchId = $invoice->branch_id !== null ? (int) $invoice->branch_id : null;
        foreach ($payments->reject(fn (SalesInvoicePayment $p): bool => $p->isCredit()) as $payment) {
            $currency = $this->storedPaymentCurrency($invoice, $payment);
            $allocate = null;
            if ($keepExtra) {
                $worth = $this->paymentInInvoiceCurrency($invoice, (string) $payment->amount, $currency);
                if (Decimal::cmp(Decimal::sub($worth, $left), $tolerance) > 0) {
                    // Only part of this payment is needed; the rest stays on the receipt as customer credit.
                    $allocate = Decimal::isPositive($left)
                        ? Decimal::round(Decimal::div(Decimal::mul((string) $payment->amount, $left), $worth), 4)
                        : '0';
                }
                $left = Decimal::isPositive(Decimal::sub($left, $worth)) ? Decimal::round(Decimal::sub($left, $worth), 4) : '0';
            }
            if ($this->changeElsewhere($payment, $currency['currency_id'])) {
                $payment->update([
                    'change_cash_bank_account_id' => PaymentCashBankAccount::resolve(
                        $payment->change_cash_bank_account_id,
                        (int) $payment->payment_method_id,
                        $branchId,
                        (int) $payment->change_currency_id,
                        true,
                    ),
                ]);
            }

            $receipt = $receipts->postForInvoicePayment($invoice->fresh() ?? $invoice, $payment, $userId, $date, $allocate);
            $applied = CustomerReceiptAllocation::query()
                ->where('customer_receipt_id', $receipt->id)
                ->where('sales_invoice_id', $invoice->id)
                ->value('applied_amount');
            $payment->update([
                'at_sale' => false,
                'customer_receipt_id' => $receipt->id,
                'cash_bank_account_id' => $receipt->cash_bank_account_id,
                'applied_amount' => $applied !== null ? (string) $applied : '0',
            ]);
        }
    }

    /**
     * Applies the "customer credit" rows (invoice currency) up to `$left`; returns what is still open.
     *
     * @param  Collection<int, SalesInvoicePayment>  $payments
     */
    private function useCreditRows(SalesInvoice $invoice, Collection $payments, string $left, bool $atSale, ?string $userId, string $date): string
    {
        $credits = app(CustomerCreditService::class);
        foreach ($payments->filter(fn (SalesInvoicePayment $p): bool => $p->isCredit()) as $credit) {
            $want = Decimal::cmp($credit->amount, $left) > 0 ? $left : (string) $credit->amount;
            $applied = Decimal::isPositive($want)
                ? $credits->useOn($invoice->fresh() ?? $invoice, $want, $atSale, $userId, $date)
                : '0';
            $credit->update(['at_sale' => $atSale, 'applied_amount' => $applied]);
            $left = Decimal::isPositive(Decimal::sub($left, $applied)) ? Decimal::round(Decimal::sub($left, $applied), 4) : '0';
        }

        return $left;
    }

    /**
     * What a payment worth `$worth` (invoice currency) settles of `$left`, and what it keeps as customer
     * credit. The last payment settles the rest when the gap is within the currency's receipt difference.
     *
     * @return array{0: string, 1: string} [applied, kept as credit]
     */
    private function splitPayment(string $worth, string $left, string $tolerance, bool $keepExtra, bool $isLast): array
    {
        if (! Decimal::isPositive($left)) {
            return ['0', $keepExtra ? Decimal::round($worth, 4) : '0'];
        }
        $gap = Decimal::sub($worth, $left);
        if ($isLast && Decimal::cmp(Decimal::abs($gap), $tolerance) <= 0) {
            return [Decimal::round($left, 4), '0'];
        }
        if (! Decimal::isPositive($gap)) {
            return [Decimal::round($worth, 4), '0'];
        }

        return [Decimal::round($left, 4), $keepExtra ? Decimal::round($gap, 4) : '0'];
    }

    /** Change handed back in another currency than the payment's. */
    private function changeElsewhere(SalesInvoicePayment $payment, int $paymentCurrencyId): bool
    {
        return $payment->change_currency_id !== null
            && (int) $payment->change_currency_id !== $paymentCurrencyId
            && $payment->change_amount !== null
            && Decimal::isPositive((string) $payment->change_amount);
    }

    /**
     * A cheque taken with this invoice bounced: what it settled is open again, any credit it gave is taken
     * back, and the customer owes it. Called by the cheque service (its clearing, if any, is already undone).
     */
    public function bounceCounterCheque(CustomerCheque $cheque, ?string $userId, string $date): void
    {
        $payment = SalesInvoicePayment::query()->whereKey($cheque->sales_invoice_payment_id)->lockForUpdate()->firstOrFail();
        $invoice = SalesInvoice::query()->whereKey($payment->sales_invoice_id)->lockForUpdate()->firstOrFail();
        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            abort(422, 'The invoice of this cheque is not posted.', ['X-Error-Code' => 'CUSTOMER_CHEQUE_NOT_OPEN']);
        }
        $applied = (string) ($payment->applied_amount ?? '0');
        $kept = (string) ($payment->extra_credit ?? '0');
        if (Decimal::isPositive($kept) && Decimal::cmp($kept, app(CustomerCreditService::class)->creditLeft($invoice)) > 0) {
            abort(422, 'Credit from this cheque was already used on another invoice. Reverse that first.', ['X-Error-Code' => 'CUSTOMER_CHEQUE_CREDIT_USED']);
        }

        $paid = PaymentAllocation::subtract($invoice->paid_total, $applied) ?? '0';
        $invoice->update([
            'paid_total' => $paid,
            'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid, $invoice->credited_total),
            'extra_credit' => PaymentAllocation::subtract($invoice->extra_credit, $kept) ?? '0',
        ]);
        $payment->update(['bounced_on' => $date]);

        $owed = Decimal::add($applied, $kept);
        if (Decimal::isPositive($owed)) {
            $this->customerLedgerService->postEntry(
                SalesInvoiceRules::assertCustomer((string) $invoice->customer_id),
                (int) $invoice->currency_id,
                Decimal::round($owed, 4),
                '0',
                LedgerReferenceType::SalePayment,
                (string) $invoice->id,
                $date,
            );
        }
        $this->journalPostingService->saleChequeBounced($cheque, $invoice, $payment, $userId, $date);
    }

    /**
     * Currency and rate stored on a payment row.
     *
     * @return array{currency_id: int, rate: string, same: bool}
     */
    private function storedPaymentCurrency(SalesInvoice $invoice, SalesInvoicePayment $payment): array
    {
        $currencyId = $payment->currency_id !== null ? (int) $payment->currency_id : (int) $invoice->currency_id;
        $same = $currencyId === (int) $invoice->currency_id;

        return [
            'currency_id' => $currencyId,
            'rate' => $same ? (string) $invoice->exchange_rate : (string) $payment->exchange_rate,
            'same' => $same,
        ];
    }

    /**
     * Checks payment rows against the invoice and returns their total in the invoice currency
     * (rows in another currency are converted).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertPayments(SalesInvoice $invoice, array $rows, bool $atPost = true, bool $keepExtra = false): string
    {
        $total = '0';
        $creditTotal = '0';
        foreach ($rows as $row) {
            if ($this->isCreditRow($row)) {
                $amount = Decimal::round($row['amount'] ?? '0', 4);
                if (! Decimal::isPositive($amount)) {
                    abort(422, 'Each payment must be greater than zero.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENT_AMOUNT_INVALID']);
                }
                $creditTotal = Decimal::add($creditTotal, $amount);
                $total = Decimal::add($total, $amount);

                continue;
            }

            $method = PaymentMethod::query()->find((int) ($row['payment_method_id'] ?? 0));
            if ($method === null || ! $method->is_active || $method->type === PaymentMethodType::Credit) {
                abort(422, 'Choose an active payment method that takes money now.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENT_METHOD_INVALID']);
            }
            if ($method->requires_reference && trim((string) ($row['reference'] ?? '')) === '') {
                abort(422, 'This payment method needs a reference.', ['X-Error-Code' => 'PAYMENT_REFERENCE_REQUIRED']);
            }
            $amount = Decimal::round($row['amount'] ?? '0', 4);
            if (! Decimal::isPositive($amount)) {
                abort(422, 'Each payment must be greater than zero.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENT_AMOUNT_INVALID']);
            }
            if (isset($row['tendered']) && $row['tendered'] !== null && $row['tendered'] !== '' && Decimal::cmp($row['tendered'], $amount) < 0) {
                abort(422, 'Cash given cannot be less than the amount paid.', ['X-Error-Code' => 'SALES_INVOICE_TENDERED_TOO_LOW']);
            }
            $currency = $this->paymentCurrency($invoice, $row);
            if ($method->type === PaymentMethodType::Cheque) {
                // A cheque waits in "Cheques to collect": no cash box, but its number and due date are needed.
                if (trim((string) ($row['cheque_number'] ?? '')) === '' || empty($row['cheque_due_date'])) {
                    abort(422, 'Enter the cheque number and due date.', ['X-Error-Code' => 'SALES_INVOICE_CHEQUE_DETAILS_REQUIRED']);
                }
            } else {
                $this->paymentAccountId($invoice, $method, $row['cash_bank_account_id'] ?? null, $currency['currency_id'], $atPost);
            }
            $this->assertPaymentChange($invoice, $method, $row, $currency, $amount, $atPost);
            $total = Decimal::add($total, $this->paymentInInvoiceCurrency($invoice, $amount, $currency));
        }

        $customer = Customer::query()->find($invoice->customer_id);
        if (Decimal::isPositive($creditTotal)) {
            if ($customer === null || $customer->is_system) {
                abort(422, 'A walk-in customer has no credit to use.', ['X-Error-Code' => 'SALES_INVOICE_CREDIT_EXCEEDED']);
            }
            $available = '0';
            foreach (app(CustomerCreditService::class)->available((string) $invoice->customer_id, (int) $invoice->currency_id, (string) $invoice->id) as $credit) {
                $available = Decimal::add($available, (string) $credit['in_invoice_currency']);
            }
            if ($this->exceedsWithTolerance($invoice, $creditTotal, $available)) {
                abort(422, 'That is more than the customer\'s credit.', ['X-Error-Code' => 'SALES_INVOICE_CREDIT_EXCEEDED']);
            }
        }
        if ($keepExtra && ($customer === null || $customer->is_system) && $this->exceedsWithTolerance($invoice, $total, (string) $invoice->grand_total)) {
            abort(422, 'A walk-in customer cannot keep credit.', ['X-Error-Code' => 'SALES_INVOICE_WALK_IN_NO_CREDIT']);
        }

        // While editing, totals may still change; at post the payments must fit the final total
        // (unless the extra is kept as customer credit).
        if ($atPost && ! $keepExtra && $this->exceedsWithTolerance($invoice, $total, (string) $invoice->grand_total)) {
            abort(422, 'Payments are more than the invoice total.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENTS_EXCEED_TOTAL']);
        }

        return Decimal::round($total, 4);
    }

    /**
     * Change in another currency needs cash given above the amount, and (at post) a cash box in that currency.
     *
     * @param  array<string, mixed>  $row
     * @param  array{currency_id: int, rate: string, same: bool}  $currency
     */
    private function assertPaymentChange(SalesInvoice $invoice, PaymentMethod $method, array $row, array $currency, string $amount, bool $atPost): void
    {
        $changeCurrencyId = isset($row['change_currency_id']) && $row['change_currency_id'] !== '' && $row['change_currency_id'] !== null ? (int) $row['change_currency_id'] : null;
        if ($changeCurrencyId === null || $changeCurrencyId === $currency['currency_id']) {
            return;
        }
        $tendered = $row['tendered'] ?? null;
        if (! $method->supports_change || $tendered === null || $tendered === '' || Decimal::cmp($tendered, $amount) <= 0) {
            abort(422, 'Change in another currency needs cash given above the amount.', ['X-Error-Code' => 'SALES_INVOICE_CHANGE_INVALID']);
        }
        PaymentCashBankAccount::resolve(
            $row['change_cash_bank_account_id'] ?? null,
            (int) $method->id,
            $invoice->branch_id !== null ? (int) $invoice->branch_id : null,
            $changeCurrencyId,
            $atPost,
        );
    }

    /**
     * The cash/bank account a payment goes to: the one chosen, else the method's linked account for the
     * invoice branch and the payment currency. Required at post (the journal needs it).
     */
    private function paymentAccountId(SalesInvoice $invoice, PaymentMethod $method, mixed $chosen, int $currencyId, bool $required = false): ?int
    {
        return PaymentCashBankAccount::resolve(
            $chosen,
            (int) $method->id,
            $invoice->branch_id !== null ? (int) $invoice->branch_id : null,
            $currencyId,
            $required,
        );
    }

    /**
     * Currency and rate of a payment row: the invoice's own when empty or the same, else the chosen
     * active currency at the typed rate (or today's rate).
     *
     * @param  array<string, mixed>  $row
     * @return array{currency_id: int, rate: string, same: bool}
     */
    private function paymentCurrency(SalesInvoice $invoice, array $row): array
    {
        $invoiceCurrencyId = (int) $invoice->currency_id;
        $chosen = $row['currency_id'] ?? null;
        if ($chosen === null || $chosen === '' || (int) $chosen === $invoiceCurrencyId) {
            return ['currency_id' => $invoiceCurrencyId, 'rate' => (string) $invoice->exchange_rate, 'same' => true];
        }

        $currency = Currency::query()->find((int) $chosen);
        if ($currency === null || ! $currency->is_active) {
            abort(422, 'Choose an active currency for this payment.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENT_CURRENCY_INVALID']);
        }

        return [
            'currency_id' => (int) $currency->id,
            'rate' => ExchangeRateSnapshot::resolve($this->exchangeRateService, (int) $currency->id, $row['exchange_rate'] ?? null, 'SALES_INVOICE_PAYMENT'),
            'same' => false,
        ];
    }

    /**
     * Payment amount in the invoice currency. Across currencies the invoice side uses today's rate,
     * the same rule the receipt applies when it is allocated to the invoice.
     *
     * @param  array{currency_id: int, rate: string, same: bool}  $currency
     */
    private function paymentInInvoiceCurrency(SalesInvoice $invoice, string $amount, array $currency): string
    {
        if ($currency['same']) {
            return $amount;
        }
        $invoiceRate = ExchangeRateSnapshot::resolve($this->exchangeRateService, (int) $invoice->currency_id, null, 'SALES_INVOICE_PAYMENT');

        return PaymentAllocation::toInvoiceCurrency($amount, $currency['rate'], $invoiceRate, false);
    }

    /**
     * Allowed gap between what is paid and what is due (the invoice currency's receipt difference).
     */
    private function receiptTolerance(SalesInvoice $invoice): string
    {
        $allowed = Currency::query()->whereKey((int) $invoice->currency_id)->value('allowed_difference_in_receipt');

        return $allowed === null || $allowed === '' ? '0' : Decimal::round($allowed, 4);
    }

    private function exceedsWithTolerance(SalesInvoice $invoice, string $paid, string $due): bool
    {
        return Decimal::cmp(Decimal::sub($paid, $due), $this->receiptTolerance($invoice)) > 0;
    }

    public function withinReceiptTolerance(SalesInvoice $invoice, string $paid, string $due): bool
    {
        return Decimal::cmp(Decimal::abs(Decimal::sub($paid, $due)), $this->receiptTolerance($invoice)) <= 0;
    }
}
