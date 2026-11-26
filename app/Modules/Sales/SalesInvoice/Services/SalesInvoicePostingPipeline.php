<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CreditLimitGuard;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Sales\CustomerCheque\Models\CustomerCheque;
use App\Modules\Sales\CustomerReceipt\Enums\CustomerReceiptStatus;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptService;
use App\Modules\Sales\DeliveryNote\Services\DeliveryNoteInvoicing;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoicePayment;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Sales\SalesOrder\Services\SalesOrderFulfillment;
use App\Modules\Sales\Support\DiscountGuard;
use App\Modules\Sales\Support\ItemPriceRules;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\Decimal;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\OpeningDocumentService;
use App\Support\PaymentAllocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posting a sales invoice, one step after another: price and tax → checks → customer account → stock → payments → journal. Reversal undoes them.
 * Part of the sales invoice posting pipeline (see SalesInvoiceService).
 */
class SalesInvoicePostingPipeline
{
    public function __construct(
        private readonly SalesInvoiceQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly StockMovementService $stockMovementService,
        private readonly JournalPostingService $journalPostingService,
        private readonly SalesOrderFulfillment $salesOrderFulfillment,
        private readonly SalesInvoiceDraftService $draft,
        private readonly SalesInvoicePaymentService $payments,
        private readonly SalesInvoiceStockPoster $stock,
    ) {}

    /**
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval  a manager's PIN when the discount or the credit limit needs approval
     */
    public function post(SalesInvoice $invoice, ?string $userId, ?array $approval = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId, $approval): SalesInvoice {
            $locked = $this->lockForPosting($invoice);

            // 1. Price and tax: final totals, and the discount approval when it is above the poster's limit.
            $this->priceAndTax($locked, $approval);
            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);

            // 2. Checks: payments fit the total, a walk-in pays in full, and the credit limit holds.
            $payments = $this->checkPayments($locked, $customer, $approval);

            // 3. Source documents: the sales order and delivery notes it bills.
            $this->salesOrderFulfillment->applyInvoice($locked, false);
            app(DeliveryNoteInvoicing::class)->applyInvoice($locked, false);

            // 4. Customer account (not for walk-in: it pays in full, nothing stays on its account).
            if (! $customer->is_system && bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    (int) $locked->currency_id,
                    (string) $locked->grand_total,
                    '0',
                    LedgerReferenceType::Invoice,
                    (string) $locked->id,
                    $locked->invoice_date->toDateString(),
                );
            }

            // 5. Stock.
            $this->stock->postInvoice($locked, $userId);

            $locked->update([
                'status' => SalesInvoiceStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            // 6. Payments taken at the counter: settled on the invoice itself (no receipts).
            $this->payments->settleAtSale($locked, $payments, $userId, (bool) $locked->keep_extra_as_credit);

            // 7. Number: taken last, so the branch's number series is locked only for the end of posting
            //    (other registers in the branch do not wait for this whole posting).
            $this->takeNumber($locked);

            // 8. Journal: one entry for the sale, its cost and its payments.
            $this->journalPostingService->salesInvoice($locked->fresh() ?? $locked, $userId);

            return $this->queryService->find($locked->id);
        });
    }

    private function lockForPosting(SalesInvoice $invoice): SalesInvoice
    {
        $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        SalesInvoiceRules::assertPostable($locked);
        $this->warehouseService->assertDocumentVisible($locked);

        return $locked;
    }

    private function takeNumber(SalesInvoice $locked): void
    {
        $number = app(DocumentNumberService::class)->assign($locked, 'invoice_number', DocumentType::SalesInvoice, $locked->branch_id !== null ? (int) $locked->branch_id : null, $locked->invoice_date);
        $this->stockMovementService->stampDocumentNumber(SalesInvoice::REFERENCE_TYPE, (string) $locked->id, 'SI', $number);
    }

    /**
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval
     */
    private function priceAndTax(SalesInvoice $locked, ?array $approval): void
    {
        $this->draft->recalculateTotals($locked);
        $locked->refresh();

        $lines = SalesInvoiceLine::query()->where('sales_invoice_id', $locked->id)->get();
        ItemPriceRules::assert(
            $locked,
            $lines,
            (string) $locked->customer_id,
            (int) $locked->currency_id,
            $locked->exchange_rate,
            $locked->invoice_date->toDateString(),
            $locked->branch_id !== null ? (int) $locked->branch_id : null,
            'SALES_INVOICE',
        );
        $approvedBy = app(DiscountGuard::class)->assert($locked, $lines, $approval);
        if ($approvedBy !== null) {
            $locked->update(['discount_approved_by' => $approvedBy, 'discount_approved_at' => now()]);
        }
    }

    /**
     * @return Collection<int, SalesInvoicePayment>
     */
    /**
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval
     */
    private function checkPayments(SalesInvoice $locked, Customer $customer, ?array $approval): Collection
    {
        $payments = SalesInvoicePayment::query()->where('sales_invoice_id', $locked->id)->orderBy('sort_order')->orderBy('id')->get();
        $paidNow = $this->payments->assertPayments($locked, $payments->map(fn (SalesInvoicePayment $p): array => $this->payments->paymentRowData($p))->all(), true, (bool) $locked->keep_extra_as_credit);
        if (Decimal::cmp($paidNow, (string) $locked->grand_total) > 0) {
            $paidNow = (string) $locked->grand_total;
        }
        if ($customer->is_system && ! $this->payments->withinReceiptTolerance($locked, $paidNow, (string) $locked->grand_total)) {
            abort(422, 'A walk-in customer must pay the whole invoice at the counter.', ['X-Error-Code' => 'SALES_INVOICE_WALK_IN_MUST_PAY']);
        }
        // Only what stays on the customer's account counts against the credit limit.
        $openAfterPayments = Decimal::sub((string) $locked->grand_total, $paidNow);
        app(CreditLimitGuard::class)->assert(
            $customer,
            (int) $locked->currency_id,
            Decimal::isPositive($openAfterPayments) ? Decimal::round($openAfterPayments, 4) : '0',
            null,
            $approval,
            $locked->branch_id !== null ? (int) $locked->branch_id : null,
            SalesInvoice::REFERENCE_TYPE,
            (string) $locked->id,
        );

        return $payments;
    }

    public function reverse(SalesInvoice $invoice, ?string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            OpeningDocumentService::assertNotOpening($locked);
            if ($locked->status !== SalesInvoiceStatus::Posted) {
                abort(422, 'Only a posted sales invoice can be reversed.', [
                    'X-Error-Code' => 'SALES_INVOICE_NOT_POSTED',
                ]);
            }
            $credits = app(CustomerCreditService::class);
            // Credit this invoice kept and gave to other invoices comes back (they are open again by that much).
            $credits->takeBackGivenFrom($locked, $userId);
            // Customer credit used on this invoice goes back to where it came from.
            $credits->giveBackUsedOn($locked, $userId);
            $locked->refresh();

            // Counter payments come off with the invoice's own entry. A cheque that already cleared cannot.
            $counterPayments = SalesInvoicePayment::query()
                ->where('sales_invoice_id', $locked->id)
                ->where('at_sale', true)
                ->where('kind', SalesInvoicePayment::KIND_PAYMENT)
                ->get();
            $cheques = CustomerCheque::query()->whereIn('sales_invoice_payment_id', $counterPayments->pluck('id')->all())->lockForUpdate()->get();
            if ($cheques->contains(fn (CustomerCheque $cheque): bool => $cheque->status === CustomerCheque::STATUS_CLEARED)) {
                abort(422, 'A cheque taken with this invoice has cleared. Mark it bounced first.', ['X-Error-Code' => 'SALES_INVOICE_CHEQUE_CLEARED']);
            }
            $standing = $counterPayments->filter(fn (SalesInvoicePayment $p): bool => $p->bounced_on === null);
            $settledAtSale = PaymentAllocation::sum($standing->map(fn (SalesInvoicePayment $p): string => (string) ($p->applied_amount ?? '0'))->all());
            $extraAtSale = PaymentAllocation::sum($standing->map(fn (SalesInvoicePayment $p): string => (string) ($p->extra_credit ?? '0'))->all());

            // Payments recorded later have their own receipts: reversed with the invoice; other receipts still block.
            $ownReceipts = CustomerReceipt::query()
                ->where('sales_invoice_id', $locked->id)
                ->where('status', CustomerReceiptStatus::Posted->value)
                ->orderBy('id')
                ->get();
            if ($ownReceipts->isNotEmpty()) {
                $receipts = app(CustomerReceiptService::class);
                foreach ($ownReceipts as $receipt) {
                    $receipts->reverse($receipt, $userId, true);
                }
                $locked->refresh();
            }
            if (bccomp(Decimal::sub((string) $locked->paid_total, $settledAtSale), '0', 4) > 0) {
                abort(422, 'This invoice has payments and cannot be reversed.', [
                    'X-Error-Code' => 'SALES_INVOICE_HAS_PAYMENTS',
                ]);
            }
            if (bccomp((string) $locked->credited_total, '0', 4) > 0) {
                abort(422, 'This invoice has credit notes and cannot be reversed.', [
                    'X-Error-Code' => 'SALES_INVOICE_HAS_CREDIT_NOTES',
                ]);
            }
            $this->warehouseService->assertDocumentVisible($locked);

            $this->stockMovementService->reverseReference(SalesInvoice::REFERENCE_TYPE, (string) $locked->id, $userId);

            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
            $paidAtSale = Decimal::add($settledAtSale, $extraAtSale);
            if ($customer->is_system) {
                // Walk-in: the sale never reached its account; only bounced cheques did, so only they come off.
                $bounced = $counterPayments->filter(fn (SalesInvoicePayment $p): bool => $p->bounced_on !== null);
                $owed = PaymentAllocation::sum($bounced->map(fn (SalesInvoicePayment $p): string => Decimal::add((string) ($p->applied_amount ?? '0'), (string) ($p->extra_credit ?? '0')))->all());
                if (Decimal::isPositive($owed)) {
                    $this->customerLedgerService->postEntry($customer, (int) $locked->currency_id, '0', $owed, LedgerReferenceType::SalePayment, (string) $locked->id, now()->toDateString());
                }
            } elseif (bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    (int) $locked->currency_id,
                    '0',
                    (string) $locked->grand_total,
                    LedgerReferenceType::Invoice,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }
            if (! $customer->is_system && Decimal::isPositive($paidAtSale)) {
                $this->customerLedgerService->postEntry(
                    SalesInvoiceRules::assertCustomer((string) $locked->customer_id),
                    (int) $locked->currency_id,
                    $paidAtSale,
                    '0',
                    LedgerReferenceType::SalePayment,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }

            $this->journalPostingService->reverse(SalesInvoice::REFERENCE_TYPE, (string) $locked->id, $userId);
            foreach ($cheques as $cheque) {
                if ($cheque->status === CustomerCheque::STATUS_BOUNCED) {
                    $this->journalPostingService->reverse(CustomerCheque::BOUNCE_REFERENCE_TYPE, (string) $cheque->id, $userId);
                } elseif ($cheque->status === CustomerCheque::STATUS_IN_HAND) {
                    // Reversed before it cleared: the cheque is given back.
                    $cheque->update(['status' => CustomerCheque::STATUS_CANCELLED, 'updated_by' => $userId]);
                }
            }
            $this->salesOrderFulfillment->applyInvoice($locked, true);
            app(DeliveryNoteInvoicing::class)->applyInvoice($locked, true);

            $locked->update([
                'status' => SalesInvoiceStatus::Reversed,
                'paid_total' => '0',
                'net_to_pay' => PaymentAllocation::netToPay($locked->grand_total, '0', $locked->credited_total),
            ]);

            return $this->queryService->find($locked->id);
        });
    }
}
