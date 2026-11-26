<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceiptAllocation;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptService;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceCreditUse;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoicePayment;
use App\Support\ExchangeRateSnapshot;
use App\Support\PaymentAllocation;
use Illuminate\Support\Facades\DB;

/**
 * A customer's credit, wherever it sits: money left on posted receipts, and money paid above an invoice's
 * total that was kept on that invoice. Used oldest first on new invoices, and taken back when either side
 * is reversed.
 */
class CustomerCreditService
{
    public const SOURCE_RECEIPT = 'receipt';

    public const SOURCE_INVOICE = 'invoice';

    public function __construct(
        private readonly CustomerReceiptService $receipts,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly JournalPostingService $journalPostingService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * Credit left for the customer, oldest first. `in_invoice_currency` is its value in `$currencyId` at
     * today's rate (null when no currency is given). `$exceptInvoiceId` leaves out that invoice's own credit.
     *
     * @return list<array<string, mixed>>
     */
    public function available(string $customerId, ?int $currencyId = null, ?string $exceptInvoiceId = null): array
    {
        $rows = [];
        foreach ($this->receipts->availableCredit($customerId, $currencyId) as $credit) {
            $rows[] = [
                ...$credit,
                'source_type' => self::SOURCE_RECEIPT,
                'source_id' => (string) $credit['receipt_id'],
                'source_number' => $credit['receipt_number'],
            ];
        }

        $invoices = SalesInvoice::query()
            ->with('currency:id,code,symbol')
            ->where('customer_id', $customerId)
            ->where('status', SalesInvoiceStatus::Posted->value)
            ->whereColumn('extra_credit', '>', 'extra_credit_used')
            ->when($exceptInvoiceId !== null, fn ($q) => $q->whereKeyNot($exceptInvoiceId))
            ->orderBy('invoice_date')
            ->orderBy('created_at')
            ->get();
        foreach ($invoices as $invoice) {
            $left = $this->creditLeft($invoice);
            if (! PaymentAllocation::isPositive($left)) {
                continue;
            }
            $rows[] = [
                'receipt_id' => null,
                'receipt_number' => $invoice->invoice_number,
                'payment_date' => $invoice->invoice_date?->toDateString(),
                'currency_id' => (int) $invoice->currency_id,
                'currency_code' => $invoice->currency?->code,
                'unapplied_amount' => $left,
                'in_invoice_currency' => $currencyId !== null ? $this->invoiceCreditIn($invoice, $left, $currencyId) : null,
                'source_type' => self::SOURCE_INVOICE,
                'source_id' => (string) $invoice->id,
                'source_number' => $invoice->invoice_number,
            ];
        }

        usort($rows, fn (array $a, array $b): int => (string) $a['payment_date'] <=> (string) $b['payment_date']);

        return $rows;
    }

    /**
     * Uses the customer's credit on an invoice, oldest first, up to `$invoiceAmount` (invoice currency).
     * `$atSale`: while posting the invoice, so the moves go in its own journal entry.
     * Returns what was applied, in the invoice currency.
     */
    public function useOn(SalesInvoice $invoice, string $invoiceAmount, bool $atSale, ?string $userId, ?string $date = null): string
    {
        $left = PaymentAllocation::normalize($invoiceAmount);
        $applied = '0';
        foreach ($this->available((string) $invoice->customer_id, (int) $invoice->currency_id, (string) $invoice->id) as $credit) {
            if (! PaymentAllocation::isPositive($left)) {
                break;
            }
            $worth = (string) $credit['in_invoice_currency'];
            $take = PaymentAllocation::exceeds($worth, $left) ? $left : $worth;
            $target = SalesInvoice::query()->findOrFail($invoice->id);
            if ($credit['source_type'] === self::SOURCE_RECEIPT) {
                $receipt = CustomerReceipt::query()->findOrFail($credit['source_id']);
                $done = $this->receipts->useCreditOn($receipt, $target, $take, $worth, (string) $credit['unapplied_amount'], $userId, $date, $atSale);
            } else {
                $source = SalesInvoice::query()->findOrFail($credit['source_id']);
                $sourceAmount = PaymentAllocation::same($take, $worth)
                    ? (string) $credit['unapplied_amount']
                    : $this->invoiceCreditFrom($source, $take, (int) $target->currency_id);
                $done = (string) $this->applyInvoiceCredit($source, $target, $sourceAmount, $atSale, $userId, $date ?? now()->toDateString())->applied_amount;
            }
            $applied = PaymentAllocation::add($applied, $done);
            $left = PaymentAllocation::subtract($left, $done) ?? '0';
        }

        return $applied;
    }

    /**
     * The invoice is being reversed: credit used on it goes back to where it came from (receipts and
     * other invoices). Moves made while posting it are undone by the reversal of its own entry.
     */
    public function giveBackUsedOn(SalesInvoice $invoice, ?string $userId): void
    {
        foreach (CustomerReceiptAllocation::query()->where('sales_invoice_id', $invoice->id)->where('applied_later', true)->get() as $allocation) {
            $this->receipts->unapplyCredit($allocation, $userId);
        }
        foreach (SalesInvoiceCreditUse::query()->where('sales_invoice_id', $invoice->id)->orderBy('id')->get() as $use) {
            $this->undoUse($use, $userId, ! $use->at_sale, false);
        }
    }

    /**
     * Credit taken back from an invoice that stays posted: its "customer credit" rows now settle that much
     * less (newest first), so the invoice shows what is really applied.
     */
    public static function reduceCreditRows(string $invoiceId, string $amount): void
    {
        $left = PaymentAllocation::normalize($amount);
        $rows = SalesInvoicePayment::query()
            ->where('sales_invoice_id', $invoiceId)
            ->where('kind', SalesInvoicePayment::KIND_CREDIT)
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get();
        foreach ($rows as $row) {
            if (! PaymentAllocation::isPositive($left)) {
                break;
            }
            $applied = PaymentAllocation::normalize($row->applied_amount ?? '0');
            $take = PaymentAllocation::exceeds($applied, $left) ? $left : $applied;
            if (! PaymentAllocation::isPositive($take)) {
                continue;
            }
            $row->update(['applied_amount' => PaymentAllocation::subtract($applied, $take) ?? '0']);
            $left = PaymentAllocation::subtract($left, $take) ?? '0';
        }
    }

    /**
     * The invoice that holds the credit is being reversed: credit it gave to other invoices is taken back,
     * so those invoices are open again by that much.
     */
    public function takeBackGivenFrom(SalesInvoice $source, ?string $userId): void
    {
        foreach (SalesInvoiceCreditUse::query()->where('source_invoice_id', $source->id)->orderBy('id')->get() as $use) {
            $this->undoUse($use, $userId, true, true);
        }
    }

    /** Credit kept on the invoice and not used yet, in the invoice currency. */
    public function creditLeft(SalesInvoice $invoice): string
    {
        return PaymentAllocation::subtract($invoice->extra_credit ?? '0', $invoice->extra_credit_used ?? '0') ?? '0';
    }

    private function applyInvoiceCredit(SalesInvoice $source, SalesInvoice $target, string $amount, bool $atSale, ?string $userId, string $day): SalesInvoiceCreditUse
    {
        return DB::transaction(function () use ($source, $target, $amount, $atSale, $userId, $day): SalesInvoiceCreditUse {
            $lockedSource = SalesInvoice::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = SalesInvoice::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($lockedSource->status !== SalesInvoiceStatus::Posted || $lockedTarget->status !== SalesInvoiceStatus::Posted) {
                abort(422, 'Only posted invoices can give or take customer credit.', ['X-Error-Code' => 'SALES_INVOICE_CREDIT_EXCEEDED']);
            }
            if ((string) $lockedSource->customer_id !== (string) $lockedTarget->customer_id) {
                abort(422, 'The credit belongs to another customer.', ['X-Error-Code' => 'SALES_INVOICE_CREDIT_EXCEEDED']);
            }
            if (PaymentAllocation::exceeds($amount, $this->creditLeft($lockedSource))) {
                abort(422, 'That is more than the customer\'s credit.', ['X-Error-Code' => 'SALES_INVOICE_CREDIT_EXCEEDED']);
            }

            $same = (int) $lockedSource->currency_id === (int) $lockedTarget->currency_id;
            $targetRate = $same
                ? (string) $lockedSource->exchange_rate
                : ExchangeRateSnapshot::resolve($this->exchangeRateService, (int) $lockedTarget->currency_id, null, 'SALES_INVOICE_PAYMENT');
            $converted = PaymentAllocation::toInvoiceCurrency($amount, (string) $lockedSource->exchange_rate, $targetRate, $same);
            $settled = PaymentAllocation::settle(
                $converted,
                (string) $lockedTarget->net_to_pay,
                Currency::query()->whereKey((int) $lockedTarget->currency_id)->value('allowed_difference_in_receipt'),
                'SALES_INVOICE_CREDIT_EXCEEDED',
            );
            if (PaymentAllocation::exceeds($settled['applied_amount'], $lockedTarget->net_to_pay)) {
                abort(422, 'That is more than what is still open on the invoice.', ['X-Error-Code' => 'SALES_INVOICE_PAYMENTS_EXCEED_TOTAL']);
            }

            $use = SalesInvoiceCreditUse::query()->create([
                'source_invoice_id' => $lockedSource->id,
                'sales_invoice_id' => $lockedTarget->id,
                'amount' => $amount,
                'applied_amount' => $settled['applied_amount'],
                'applied_exchange_rate' => $targetRate,
                'exchange_difference' => $settled['exchange_difference'],
                'at_sale' => $atSale,
                'applied_at' => now(),
            ]);

            $lockedSource->update(['extra_credit_used' => PaymentAllocation::add($lockedSource->extra_credit_used, $amount)]);
            $paid = PaymentAllocation::add($lockedTarget->paid_total, $settled['applied_amount']);
            $lockedTarget->update([
                'paid_total' => $paid,
                'net_to_pay' => PaymentAllocation::netToPay($lockedTarget->grand_total, $paid, $lockedTarget->credited_total),
            ]);

            $this->moveOnLedger($lockedSource, $lockedTarget, $amount, (string) $settled['applied_amount'], $day, false);
            if (! $atSale) {
                $this->journalPostingService->salesInvoiceCreditUse($use, $lockedSource, $lockedTarget, $userId, $day);
            }

            return $use;
        });
    }

    /**
     * Takes one use back: the source gets its credit again and the target is open again by that much.
     * `$postUndo`: post the journal side too (a use made later has its own entry to reverse; a use made at
     * the target's sale gets an opposite entry, unless the target's whole entry is being reversed).
     * `$targetStays`: the target stays posted, so its credit rows are reduced too.
     */
    private function undoUse(SalesInvoiceCreditUse $use, ?string $userId, bool $postUndo, bool $targetStays): void
    {
        DB::transaction(function () use ($use, $userId, $postUndo, $targetStays): void {
            $locked = SalesInvoiceCreditUse::query()->whereKey($use->id)->lockForUpdate()->firstOrFail();
            $source = SalesInvoice::query()->whereKey($locked->source_invoice_id)->lockForUpdate()->firstOrFail();
            $target = SalesInvoice::query()->whereKey($locked->sales_invoice_id)->lockForUpdate()->firstOrFail();
            $day = now()->toDateString();

            if ($postUndo) {
                if ($locked->at_sale) {
                    $this->journalPostingService->salesInvoiceCreditUse($locked, $source, $target, $userId, $day, true);
                } else {
                    $this->journalPostingService->reverse(SalesInvoiceCreditUse::REFERENCE_TYPE, (string) $locked->id, $userId, $day);
                }
            }
            $this->moveOnLedger($source, $target, (string) $locked->amount, (string) $locked->applied_amount, $day, true);

            $source->update(['extra_credit_used' => PaymentAllocation::subtract($source->extra_credit_used, $locked->amount) ?? '0']);
            $paid = PaymentAllocation::subtract($target->paid_total, $locked->applied_amount) ?? '0';
            $target->update([
                'paid_total' => $paid,
                'net_to_pay' => PaymentAllocation::netToPay($target->grand_total, $paid, $target->credited_total),
            ]);
            if ($targetStays) {
                self::reduceCreditRows((string) $target->id, (string) $locked->applied_amount);
            }
            $locked->delete();
        });
    }

    /**
     * The customer's balance moves from the source invoice's currency to the target's (nothing to post when
     * both are the same currency and amount).
     */
    private function moveOnLedger(SalesInvoice $source, SalesInvoice $target, string $amount, string $applied, string $day, bool $undo): void
    {
        if ((int) $source->currency_id === (int) $target->currency_id && PaymentAllocation::same($amount, $applied)) {
            return;
        }
        $customer = Customer::query()->findOrFail($source->customer_id);
        if ($undo) {
            $this->customerLedgerService->postEntry($customer, (int) $target->currency_id, $applied, '0', LedgerReferenceType::SalePayment, (string) $target->id, $day);
            $this->customerLedgerService->postEntry($customer, (int) $source->currency_id, '0', $amount, LedgerReferenceType::SalePayment, (string) $source->id, $day);

            return;
        }
        $this->customerLedgerService->postEntry($customer, (int) $source->currency_id, $amount, '0', LedgerReferenceType::SalePayment, (string) $source->id, $day);
        $this->customerLedgerService->postEntry($customer, (int) $target->currency_id, '0', $applied, LedgerReferenceType::SalePayment, (string) $target->id, $day);
    }

    /** Invoice-currency credit worth in `$currencyId` (today's rate for that currency, the invoice rate for its own). */
    private function invoiceCreditIn(SalesInvoice $invoice, string $amount, int $currencyId): string
    {
        $same = $currencyId === (int) $invoice->currency_id;
        $targetRate = $same ? (string) $invoice->exchange_rate : ExchangeRateSnapshot::resolve($this->exchangeRateService, $currencyId, null, 'SALES_INVOICE_PAYMENT');

        return PaymentAllocation::toInvoiceCurrency($amount, (string) $invoice->exchange_rate, $targetRate, $same);
    }

    /** Amount in `$currencyId` expressed in the source invoice's currency (inverse of invoiceCreditIn). */
    private function invoiceCreditFrom(SalesInvoice $source, string $amount, int $currencyId): string
    {
        $same = $currencyId === (int) $source->currency_id;
        $fromRate = $same ? (string) $source->exchange_rate : ExchangeRateSnapshot::resolve($this->exchangeRateService, $currencyId, null, 'SALES_INVOICE_PAYMENT');

        return PaymentAllocation::toInvoiceCurrency($amount, $fromRate, (string) $source->exchange_rate, $same);
    }
}
