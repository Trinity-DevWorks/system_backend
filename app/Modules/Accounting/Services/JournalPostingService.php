<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\JournalBuilder;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Branch\Models\Branch;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\LandedCost\Models\LandedCost;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteType;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNoteLine;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPaymentAllocation;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefund;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefundAllocation;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Enums\StockTransferClosureOutcome;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Models\StockRevaluation;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferClosure;
use App\Modules\Sales\CustomerCheque\Models\CustomerCheque;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceiptAllocation;
use App\Modules\Sales\CustomerRefund\Models\CustomerRefund;
use App\Modules\Sales\CustomerRefund\Models\CustomerRefundAllocation;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteType;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Models\TreasuryTransfer;
use App\Services\ModuleEntitlementService;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Writes the automatic journal entries for documents. Called inside each document's
 * post / reverse transaction, after its stock movements exist.
 */
class JournalPostingService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly AccountMappingService $accountMappingService,
        private readonly ModuleEntitlementService $moduleEntitlementService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    public function enabled(): bool
    {
        $tenantId = tenant('id');

        return $tenantId !== null && $this->moduleEntitlementService->tenantHas((string) $tenantId, 'accounting');
    }

    public function hasEntry(string $sourceType, string $sourceId, string $event = JournalEntry::EVENT_POST): bool
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_event', $event)
            ->exists();
    }

    public function salesInvoice(SalesInvoice $invoice, ?string $userId, ?string $date = null): void
    {
        $id = (string) $invoice->id;
        if (! $this->shouldPost(SalesInvoice::REFERENCE_TYPE, $id)) {
            return;
        }

        if ($invoice->is_opening) {
            $this->openingDocument(SalesInvoice::REFERENCE_TYPE, $id, 'Opening invoice '.$invoice->invoice_number, (string) $invoice->grand_total, $invoice, AccountRole::Receivable, JournalEntryLine::PARTY_CUSTOMER, (string) $invoice->customer_id, $date ?? $invoice->invoice_date->toDateString(), $userId);

            return;
        }

        $currencyId = (int) $invoice->currency_id;
        $rate = (string) $invoice->exchange_rate;
        $grand = (string) $invoice->grand_total;
        $tax = (string) $invoice->tax_total;
        $adjustment = (string) $invoice->adjustment;
        $revenue = JournalMath::sub(JournalMath::sub($grand, $tax), $adjustment);
        $label = 'Sales invoice '.$invoice->invoice_number;

        $builder = $this->builder()
            ->foreign($this->account(AccountRole::Receivable), $grand, $currencyId, $rate, JournalEntryLine::PARTY_CUSTOMER, (string) $invoice->customer_id, $label)
            ->foreign($this->account(AccountRole::VatOutput), JournalMath::neg($tax), $currencyId, $rate, null, null, $label)
            ->foreign($this->account(AccountRole::SalesRevenue), JournalMath::neg($revenue), $currencyId, $rate, null, null, $label)
            ->foreign($this->account(AccountRole::Rounding), JournalMath::neg($adjustment), $currencyId, $rate, null, null, $label);

        $value = $this->stockValue(SalesInvoice::REFERENCE_TYPE, $id);
        $builder
            ->primary($this->account(AccountRole::CostOfGoodsSold), JournalMath::neg($value), null, null, $label)
            ->primary($this->account(AccountRole::Inventory), $value, null, null, $label);

        $this->create(SalesInvoice::REFERENCE_TYPE, $id, $date ?? $invoice->invoice_date->toDateString(), $label, $this->documentBranch($invoice), $builder, $userId);
    }

    /**
     * Delivery note: the stock leaves here, so the cost of sales is booked here (Dr COGS / Cr Inventory).
     * The invoice made from it books only the revenue (its delivery lines have no stock movements).
     */
    public function deliveryNote(DeliveryNote $note, ?string $userId, ?string $date = null): void
    {
        $id = (string) $note->id;
        if (! $this->shouldPost(DeliveryNote::REFERENCE_TYPE, $id)) {
            return;
        }

        $label = 'Delivery note '.$note->delivery_number;
        $value = $this->stockValue(DeliveryNote::REFERENCE_TYPE, $id);
        $builder = $this->builder()
            ->primary($this->account(AccountRole::CostOfGoodsSold), JournalMath::neg($value), null, null, $label)
            ->primary($this->account(AccountRole::Inventory), $value, null, null, $label);

        $this->create(DeliveryNote::REFERENCE_TYPE, $id, $date ?? $note->delivery_date->toDateString(), $label, $this->documentBranch($note), $builder, $userId);
    }

    public function goodsReceipt(GoodsReceipt $receipt, ?string $userId, ?string $date = null): void
    {
        $id = (string) $receipt->id;
        if (! $this->shouldPost(GoodsReceipt::REFERENCE_TYPE, $id)) {
            return;
        }

        $label = 'Goods receipt '.$receipt->grn_number;
        $value = $this->stockValue(GoodsReceipt::REFERENCE_TYPE, $id);
        $builder = $this->builder()
            ->primary($this->account(AccountRole::Inventory), $value, null, null, $label)
            ->primary($this->account(AccountRole::GoodsReceivedNotInvoiced), JournalMath::neg($value), null, null, $label);

        $this->create(GoodsReceipt::REFERENCE_TYPE, $id, $date ?? $receipt->received_date->toDateString(), $label, $this->documentBranch($receipt), $builder, $userId);
    }

    public function purchaseInvoice(PurchaseInvoice $invoice, ?string $userId, ?string $date = null): void
    {
        $id = (string) $invoice->id;
        if (! $this->shouldPost(PurchaseInvoice::REFERENCE_TYPE, $id)) {
            return;
        }

        if ($invoice->is_opening) {
            $this->openingDocument(PurchaseInvoice::REFERENCE_TYPE, $id, 'Opening invoice '.$invoice->invoice_number, JournalMath::neg((string) $invoice->grand_total), $invoice, AccountRole::Payable, JournalEntryLine::PARTY_SUPPLIER, (string) $invoice->supplier_id, $date ?? $invoice->invoice_date->toDateString(), $userId);

            return;
        }

        $currencyId = (int) $invoice->currency_id;
        $rate = (string) $invoice->exchange_rate;
        $label = 'Purchase invoice '.$invoice->invoice_number;

        $builder = $this->builder()
            ->foreign($this->account(AccountRole::Payable), JournalMath::neg($invoice->grand_total), $currencyId, $rate, JournalEntryLine::PARTY_SUPPLIER, (string) $invoice->supplier_id, $label)
            ->foreign($this->account(AccountRole::VatInput), $invoice->tax_total, $currencyId, $rate, null, null, $label);

        $lines = PurchaseInvoiceLine::query()->where('purchase_invoice_id', $id)->orderBy('id')->get();
        $lineItems = Item::query()->whereIn('id', $lines->pluck('item_id')->unique()->all())->get(['id', 'track_inventory', 'is_landed_cost'])->keyBy('id');

        $nonStock = '0';
        $landed = '0';
        foreach ($lines as $line) {
            $lineItem = $lineItems->get((string) $line->item_id);
            if ($lineItem === null || $lineItem->track_inventory) {
                continue;
            }
            // Freight / customs bills wait on the clearing account until a landed cost puts them on stock.
            if ($lineItem->is_landed_cost) {
                $landed = JournalMath::add($landed, $line->line_subtotal);
            } else {
                $nonStock = JournalMath::add($nonStock, $line->line_subtotal);
            }
        }
        $builder->foreign($this->account(AccountRole::NonStockPurchase), $nonStock, $currencyId, $rate, null, null, $label);
        if (JournalMath::isPositive($landed)) {
            $builder->foreign($this->account(AccountRole::LandedCostClearing), $landed, $currencyId, $rate, null, null, $label);
        }

        if ($invoice->goods_receipt_id === null) {
            $builder->primary($this->account(AccountRole::Inventory), $this->stockValue(PurchaseInvoice::REFERENCE_TYPE, $id), null, null, $label);
        } else {
            $builder->primary(
                $this->account(AccountRole::GoodsReceivedNotInvoiced),
                $this->matchedReceiptValue((string) $invoice->goods_receipt_id, $lines->pluck('goods_receipt_line_id')->filter()->map(fn ($v): int => (int) $v)->unique()->values()->all()),
                null,
                null,
                $label,
            );
        }

        $builder->balanceTo($this->account(AccountRole::PurchasePriceDifference), $label);

        $this->create(PurchaseInvoice::REFERENCE_TYPE, $id, $date ?? $invoice->invoice_date->toDateString(), $label, $this->documentBranch($invoice), $builder, $userId);
    }

    /**
     * Landed cost: Dr Inventory (share still in stock), Dr COGS (share already sold),
     * Dr Purchase price difference (standard-costed items), Cr Landed cost clearing (total).
     * The reversal is built from the recomputed split, not mirrored, and posted as the reverse event.
     *
     * @param  array{inventory: string, cogs: string, variance: string}  $totals
     */
    public function landedCost(LandedCost $document, array $totals, ?string $userId, bool $reverse): void
    {
        $id = (string) $document->id;
        $type = LandedCost::REFERENCE_TYPE;
        $event = $reverse ? JournalEntry::EVENT_REVERSE : JournalEntry::EVENT_POST;
        if (! $this->enabled() || $this->hasEntry($type, $id, $event)) {
            return;
        }

        $sign = fn (string $v): string => $reverse ? JournalMath::neg($v) : $v;
        $total = JournalMath::add(JournalMath::add($totals['inventory'], $totals['cogs']), $totals['variance']);
        $label = 'Landed cost '.$document->landed_cost_number.($reverse ? ' (reversal)' : '');

        $builder = $this->builder()
            ->primary($this->account(AccountRole::Inventory), $sign($totals['inventory']), null, null, $label)
            ->primary($this->account(AccountRole::CostOfGoodsSold), $sign($totals['cogs']), null, null, $label)
            ->primary($this->account(AccountRole::PurchasePriceDifference), $sign($totals['variance']), null, null, $label)
            ->primary($this->account(AccountRole::LandedCostClearing), JournalMath::neg($sign($total)), null, null, $label);

        $date = $reverse ? now()->toDateString() : $document->cost_date->toDateString();
        $this->create($type, $id, $date, $label, $this->documentBranch($document), $builder, $userId, $event);
    }

    public function customerReceipt(CustomerReceipt $receipt, ?string $userId, ?string $date = null): void
    {
        $id = (string) $receipt->id;
        if (! $this->shouldPost(CustomerReceipt::REFERENCE_TYPE, $id)) {
            return;
        }

        $label = 'Customer receipt '.$receipt->receipt_number;
        // A cheque waits in "Cheques to collect" until it clears into a bank account.
        $moneyLedger = $receipt->cheque_number !== null && $receipt->cheque_number !== ''
            ? $this->account(AccountRole::ChequesToCollect)
            : $this->cashBankLedger($receipt->cash_bank_account_id);
        $builder = $this->builder()->foreign(
            $moneyLedger,
            $receipt->amount,
            (int) $receipt->currency_id,
            (string) $receipt->exchange_rate,
            null,
            null,
            $label,
        );

        // Allocations made later (customer credit applied to other invoices) have their own entries.
        $allocations = CustomerReceiptAllocation::query()->where('customer_receipt_id', $id)->where('applied_later', false)->orderBy('id')->get();
        $invoices = SalesInvoice::query()->whereIn('id', $allocations->pluck('sales_invoice_id')->all())->get()->keyBy('id');
        $allocated = '0';
        foreach ($allocations as $allocation) {
            $allocated = JournalMath::add($allocated, $allocation->amount);
            $invoice = $invoices->get($allocation->sales_invoice_id);
            if ($invoice === null) {
                continue;
            }
            $builder->foreign(
                $this->account(AccountRole::Receivable),
                JournalMath::neg($allocation->applied_amount),
                (int) $invoice->currency_id,
                (string) $invoice->exchange_rate,
                JournalEntryLine::PARTY_CUSTOMER,
                (string) $receipt->customer_id,
                $label.' — '.$invoice->invoice_number,
            );
        }

        // What is not allocated stays on the customer's account as credit, in the receipt currency.
        $unapplied = JournalMath::sub($receipt->amount, $allocated);
        if (JournalMath::isPositive($unapplied)) {
            $builder->foreign(
                $this->account(AccountRole::Receivable),
                JournalMath::neg($unapplied),
                (int) $receipt->currency_id,
                (string) $receipt->exchange_rate,
                JournalEntryLine::PARTY_CUSTOMER,
                (string) $receipt->customer_id,
                $label.' — customer credit',
            );
        }

        $this->exchangeDifference($builder, $label);
        $this->create(CustomerReceipt::REFERENCE_TYPE, $id, $date ?? $receipt->payment_date->toDateString(), $label, $this->documentBranch($receipt), $builder, $userId);
    }

    /**
     * Customer credit (unapplied receipt money) applied to an invoice later: moves the amount from the
     * receipt's currency and rate to the invoice's on the customer's account. A rate gap is exchange gain/loss.
     */
    public function customerCreditApplication(CustomerReceiptAllocation $allocation, CustomerReceipt $receipt, SalesInvoice $invoice, ?string $userId, ?string $date = null): void
    {
        $id = (string) $allocation->id;
        if (! $this->shouldPost(self::CUSTOMER_CREDIT_APPLICATION, $id)) {
            return;
        }

        $label = 'Customer credit '.$receipt->receipt_number.' — '.$invoice->invoice_number;
        $builder = $this->builder()
            ->foreign(
                $this->account(AccountRole::Receivable),
                $allocation->amount,
                (int) $receipt->currency_id,
                (string) $receipt->exchange_rate,
                JournalEntryLine::PARTY_CUSTOMER,
                (string) $receipt->customer_id,
                $label,
            )
            ->foreign(
                $this->account(AccountRole::Receivable),
                JournalMath::neg($allocation->applied_amount),
                (int) $invoice->currency_id,
                (string) $invoice->exchange_rate,
                JournalEntryLine::PARTY_CUSTOMER,
                (string) $receipt->customer_id,
                $label,
            );

        $this->exchangeDifference($builder, $label);
        $this->create(self::CUSTOMER_CREDIT_APPLICATION, $id, $date ?? now()->toDateString(), $label, $this->documentBranch($receipt), $builder, $userId);
    }

    /**
     * A customer cheque clears: Dr the bank account / Cr Cheques to collect, in the cheque currency.
     */
    public function customerChequeCleared(CustomerCheque $cheque, CashBankAccount $bank, ?string $userId, string $date): void
    {
        $id = (string) $cheque->id;
        if (! $this->shouldPost(CustomerCheque::REFERENCE_TYPE, $id)) {
            return;
        }

        $bank->loadMissing('account');
        if ($bank->account === null) {
            abort(422, 'The bank account needs a ledger account.', ['X-Error-Code' => 'TREASURY_ACCOUNT_REQUIRED']);
        }

        $label = 'Cheque '.$cheque->cheque_number.' cleared';
        $builder = $this->builder()
            ->foreign($bank->account, $cheque->amount, (int) $cheque->currency_id, (string) $cheque->exchange_rate, null, null, $label)
            ->foreign($this->account(AccountRole::ChequesToCollect), JournalMath::neg($cheque->amount), (int) $cheque->currency_id, (string) $cheque->exchange_rate, null, null, $label);

        $this->create(CustomerCheque::REFERENCE_TYPE, $id, $date, $label, $cheque->branch_id !== null ? (int) $cheque->branch_id : null, $builder, $userId);
    }

    public const CUSTOMER_CREDIT_APPLICATION = 'customer_credit_application';

    /**
     * Cash/bank transfer: Dr destination account / Cr source account. When the two accounts use
     * different currencies, the primary-currency gap goes to exchange gain or loss.
     */
    public function treasuryTransfer(TreasuryTransfer $transfer, ?string $userId, ?string $date = null): void
    {
        $id = (string) $transfer->id;
        if (! $this->shouldPost(TreasuryTransfer::REFERENCE_TYPE, $id)) {
            return;
        }

        $transfer->loadMissing(['fromAccount.account', 'toAccount.account']);
        $from = $transfer->fromAccount;
        $to = $transfer->toAccount;
        if ($from?->account === null || $to?->account === null) {
            abort(422, 'Both cash/bank accounts need a ledger account.', ['X-Error-Code' => 'TREASURY_ACCOUNT_REQUIRED']);
        }

        $label = 'Cash/bank transfer '.$transfer->transfer_number;
        $builder = $this->builder()
            ->foreign($to->account, $transfer->amount_to, (int) $to->currency_id, (string) $transfer->exchange_rate_to, null, null, $label)
            ->foreign($from->account, JournalMath::neg($transfer->amount_from), (int) $from->currency_id, (string) $transfer->exchange_rate, null, null, $label);

        $this->exchangeDifference($builder, $label);
        $this->create(TreasuryTransfer::REFERENCE_TYPE, $id, $date ?? $transfer->transfer_date->toDateString(), $label, $this->documentBranch($transfer), $builder, $userId);
    }

    /**
     * Expense voucher: Dr each expense account (net) + VAT input / Cr the cash/bank account (total).
     */
    public function expenseVoucher(ExpenseVoucher $voucher, ?string $userId, ?string $date = null): void
    {
        $id = (string) $voucher->id;
        if (! $this->shouldPost(ExpenseVoucher::REFERENCE_TYPE, $id)) {
            return;
        }

        $voucher->loadMissing(['cashBankAccount.account', 'lines.account']);
        $cash = $voucher->cashBankAccount;
        if ($cash?->account === null) {
            abort(422, 'The cash/bank account needs a ledger account.', ['X-Error-Code' => 'TREASURY_ACCOUNT_REQUIRED']);
        }

        $label = 'Expense voucher '.$voucher->voucher_number;
        $currencyId = (int) $voucher->currency_id;
        $rate = (string) $voucher->exchange_rate;
        $builder = $this->builder();
        $tax = '0';
        foreach ($voucher->lines as $line) {
            if ($line->account === null) {
                continue;
            }
            $builder->foreign($line->account, $line->amount, $currencyId, $rate, null, null, $line->description ?: $label);
            $tax = JournalMath::add($tax, $line->tax_amount);
        }
        if (JournalMath::isPositive($tax)) {
            $builder->foreign($this->account(AccountRole::VatInput), $tax, $currencyId, $rate, null, null, $label.' — VAT');
        }
        $builder->foreign($cash->account, JournalMath::neg($voucher->total), $currencyId, $rate, null, null, $label);

        $this->exchangeDifference($builder, $label);
        $this->create(ExpenseVoucher::REFERENCE_TYPE, $id, $date ?? $voucher->voucher_date->toDateString(), $label, $this->documentBranch($voucher), $builder, $userId);
    }

    public function supplierPayment(SupplierPayment $payment, ?string $userId, ?string $date = null): void
    {
        $id = (string) $payment->id;
        if (! $this->shouldPost(SupplierPayment::REFERENCE_TYPE, $id)) {
            return;
        }

        $label = 'Supplier payment '.$payment->payment_number;
        $builder = $this->builder()->foreign(
            $this->cashBankLedger($payment->cash_bank_account_id),
            JournalMath::neg($payment->amount),
            (int) $payment->currency_id,
            (string) $payment->exchange_rate,
            null,
            null,
            $label,
        );

        $allocations = SupplierPaymentAllocation::query()->where('supplier_payment_id', $id)->orderBy('id')->get();
        $invoices = PurchaseInvoice::query()->whereIn('id', $allocations->pluck('purchase_invoice_id')->all())->get()->keyBy('id');
        foreach ($allocations as $allocation) {
            $invoice = $invoices->get($allocation->purchase_invoice_id);
            if ($invoice === null) {
                continue;
            }
            $builder->foreign(
                $this->account(AccountRole::Payable),
                $allocation->applied_amount,
                (int) $invoice->currency_id,
                (string) $invoice->exchange_rate,
                JournalEntryLine::PARTY_SUPPLIER,
                (string) $payment->supplier_id,
                $label.' — '.$invoice->invoice_number,
            );
        }

        $this->exchangeDifference($builder, $label);
        $this->create(SupplierPayment::REFERENCE_TYPE, $id, $date ?? $payment->payment_date->toDateString(), $label, $this->documentBranch($payment), $builder, $userId);
    }

    public function salesCreditNote(SalesCreditNote $note, ?string $userId, ?string $date = null): void
    {
        $id = (string) $note->id;
        if (! $this->shouldPost(SalesCreditNote::REFERENCE_TYPE, $id)) {
            return;
        }

        if ($note->is_opening) {
            $this->openingDocument(SalesCreditNote::REFERENCE_TYPE, $id, 'Opening credit '.$note->credit_note_number, JournalMath::neg((string) $note->grand_total), $note, AccountRole::Receivable, JournalEntryLine::PARTY_CUSTOMER, (string) $note->customer_id, $date ?? $note->credit_date->toDateString(), $userId);

            return;
        }

        $currencyId = (int) $note->currency_id;
        $rate = (string) $note->exchange_rate;
        $grand = (string) $note->grand_total;
        $tax = (string) $note->tax_total;
        $adjustment = (string) $note->adjustment;
        $net = JournalMath::sub(JournalMath::sub($grand, $tax), $adjustment);
        $label = 'Sales credit note '.$note->credit_note_number;
        $contra = $note->type === SalesCreditNoteType::Allowance ? AccountRole::SalesDiscount : AccountRole::SalesReturns;

        $builder = $this->builder()
            ->foreign($this->account(AccountRole::Receivable), JournalMath::neg($grand), $currencyId, $rate, JournalEntryLine::PARTY_CUSTOMER, (string) $note->customer_id, $label)
            ->foreign($this->account(AccountRole::VatOutput), $tax, $currencyId, $rate, null, null, $label)
            ->foreign($this->account($contra), $net, $currencyId, $rate, null, null, $label)
            ->foreign($this->account(AccountRole::Rounding), $adjustment, $currencyId, $rate, null, null, $label);

        $value = $this->stockValue(SalesCreditNote::REFERENCE_TYPE, $id);
        $builder
            ->primary($this->account(AccountRole::Inventory), $value, null, null, $label)
            ->primary($this->account(AccountRole::CostOfGoodsSold), JournalMath::neg($value), null, null, $label);

        $this->create(SalesCreditNote::REFERENCE_TYPE, $id, $date ?? $note->credit_date->toDateString(), $label, $this->documentBranch($note), $builder, $userId);
    }

    public function creditApplication(SalesCreditNote $note, SalesInvoice $invoice, string $amount, ?string $userId, ?string $date = null): void
    {
        $this->rateApplication(
            'sales_credit_note_apply',
            $note->credit_note_number,
            (string) $note->id,
            (string) $note->exchange_rate,
            $invoice->invoice_number,
            (string) $invoice->exchange_rate,
            (int) $note->currency_id,
            $amount,
            AccountRole::Receivable,
            JournalEntryLine::PARTY_CUSTOMER,
            (string) $note->customer_id,
            $userId,
            $date,
            'Credit application',
            $this->documentBranch($note),
        );
    }

    public function reverseCreditApplications(string $creditNoteId, ?string $userId): void
    {
        $this->reverseApplications('sales_credit_note_apply', $creditNoteId, $userId);
    }

    /**
     * When accounting was off at post time, the later backfill also writes the rate difference
     * for allocations that already exist. A live post writes those itself and is skipped here.
     */
    public function backfillSalesCreditNote(SalesCreditNote $note, ?string $userId, ?string $date = null): void
    {
        $id = (string) $note->id;
        $had = $this->hasEntry(SalesCreditNote::REFERENCE_TYPE, $id);
        $this->salesCreditNote($note, $userId, $date);
        if ($had || ! $this->enabled()) {
            return;
        }

        $note->loadMissing('allocations.salesInvoice');
        foreach ($note->allocations as $allocation) {
            if ($allocation->salesInvoice instanceof SalesInvoice) {
                $this->creditApplication($note, $allocation->salesInvoice, (string) $allocation->amount, $userId, $date);
            }
        }
    }

    public function customerRefund(CustomerRefund $refund, ?string $userId, ?string $date = null): void
    {
        $id = (string) $refund->id;
        if (! $this->shouldPost(CustomerRefund::REFERENCE_TYPE, $id)) {
            return;
        }

        $label = 'Customer refund '.$refund->refund_number;
        $builder = $this->builder()->foreign(
            $this->cashBankLedger($refund->cash_bank_account_id),
            JournalMath::neg($refund->amount),
            (int) $refund->currency_id,
            (string) $refund->exchange_rate,
            null,
            null,
            $label,
        );

        $allocations = CustomerRefundAllocation::query()->where('customer_refund_id', $id)->orderBy('id')->get();
        $notes = SalesCreditNote::query()->whereIn('id', $allocations->pluck('sales_credit_note_id')->all())->get()->keyBy('id');
        foreach ($allocations as $allocation) {
            $note = $notes->get($allocation->sales_credit_note_id);
            if (! $note instanceof SalesCreditNote) {
                continue;
            }
            $builder->foreign(
                $this->account(AccountRole::Receivable),
                $allocation->amount,
                (int) $note->currency_id,
                (string) $note->exchange_rate,
                JournalEntryLine::PARTY_CUSTOMER,
                (string) $refund->customer_id,
                $label.' — '.$note->credit_note_number,
            );
        }

        $this->exchangeDifference($builder, $label);
        $this->create(CustomerRefund::REFERENCE_TYPE, $id, $date ?? $refund->refund_date->toDateString(), $label, $this->documentBranch($refund), $builder, $userId);
    }

    public function purchaseDebitNote(PurchaseDebitNote $note, ?string $userId, ?string $date = null): void
    {
        $id = (string) $note->id;
        if (! $this->shouldPost(PurchaseDebitNote::REFERENCE_TYPE, $id)) {
            return;
        }

        if ($note->is_opening) {
            $this->openingDocument(PurchaseDebitNote::REFERENCE_TYPE, $id, 'Opening credit '.$note->debit_note_number, (string) $note->grand_total, $note, AccountRole::Payable, JournalEntryLine::PARTY_SUPPLIER, (string) $note->supplier_id, $date ?? $note->debit_date->toDateString(), $userId);

            return;
        }

        $currencyId = (int) $note->currency_id;
        $rate = (string) $note->exchange_rate;
        $label = 'Purchase debit note '.$note->debit_note_number;
        $builder = $this->builder()
            ->foreign($this->account(AccountRole::Payable), $note->grand_total, $currencyId, $rate, JournalEntryLine::PARTY_SUPPLIER, (string) $note->supplier_id, $label)
            ->foreign($this->account(AccountRole::VatInput), JournalMath::neg($note->tax_total), $currencyId, $rate, null, null, $label);

        $lines = PurchaseDebitNoteLine::query()->where('purchase_debit_note_id', $id)->orderBy('id')->get();
        $items = Item::query()->with('itemType:id,code')->whereIn('id', $lines->pluck('item_id')->filter()->unique()->all())->get()->keyBy('id');

        if ($note->type === PurchaseDebitNoteType::Allowance) {
            $net = '0';
            foreach ($lines as $line) {
                $net = JournalMath::add($net, $line->line_subtotal);
            }
            $builder->foreign($this->account(AccountRole::PurchaseDiscount), JournalMath::neg($net), $currencyId, $rate, null, null, $label);
        } else {
            $nonStock = '0';
            foreach ($lines as $line) {
                $item = $items->get((string) $line->item_id);
                $isBundle = $item !== null && strtoupper((string) $item->itemType?->code) === 'BUNDLE';
                if ($item === null || (! $item->track_inventory && ! $isBundle)) {
                    $nonStock = JournalMath::add($nonStock, $line->line_subtotal);
                }
            }
            $builder->foreign($this->account(AccountRole::NonStockPurchase), JournalMath::neg($nonStock), $currencyId, $rate, null, null, $label);
            $builder->primary($this->account(AccountRole::Inventory), $this->stockValue(PurchaseDebitNote::REFERENCE_TYPE, $id), null, null, $label);
        }

        $builder->balanceTo($this->account(AccountRole::PurchasePriceDifference), $label);
        $this->create(PurchaseDebitNote::REFERENCE_TYPE, $id, $date ?? $note->debit_date->toDateString(), $label, $this->documentBranch($note), $builder, $userId);
    }

    public function debitApplication(PurchaseDebitNote $note, PurchaseInvoice $invoice, string $amount, ?string $userId, ?string $date = null): void
    {
        $this->rateApplication(
            'purchase_debit_note_apply',
            $note->debit_note_number,
            (string) $note->id,
            (string) $note->exchange_rate,
            $invoice->invoice_number,
            (string) $invoice->exchange_rate,
            (int) $note->currency_id,
            $amount,
            AccountRole::Payable,
            JournalEntryLine::PARTY_SUPPLIER,
            (string) $note->supplier_id,
            $userId,
            $date,
            'Debit application',
            $this->documentBranch($note),
        );
    }

    public function reverseDebitApplications(string $debitNoteId, ?string $userId): void
    {
        $this->reverseApplications('purchase_debit_note_apply', $debitNoteId, $userId);
    }

    public function backfillPurchaseDebitNote(PurchaseDebitNote $note, ?string $userId, ?string $date = null): void
    {
        $id = (string) $note->id;
        $had = $this->hasEntry(PurchaseDebitNote::REFERENCE_TYPE, $id);
        $this->purchaseDebitNote($note, $userId, $date);
        if ($had || ! $this->enabled()) {
            return;
        }

        $note->loadMissing('allocations.purchaseInvoice');
        foreach ($note->allocations as $allocation) {
            if ($allocation->purchaseInvoice instanceof PurchaseInvoice) {
                $this->debitApplication($note, $allocation->purchaseInvoice, (string) $allocation->amount, $userId, $date);
            }
        }
    }

    public function supplierRefund(SupplierRefund $refund, ?string $userId, ?string $date = null): void
    {
        $id = (string) $refund->id;
        if (! $this->shouldPost(SupplierRefund::REFERENCE_TYPE, $id)) {
            return;
        }

        $label = 'Supplier refund '.$refund->refund_number;
        $builder = $this->builder()->foreign(
            $this->cashBankLedger($refund->cash_bank_account_id),
            $refund->amount,
            (int) $refund->currency_id,
            (string) $refund->exchange_rate,
            null,
            null,
            $label,
        );

        $allocations = SupplierRefundAllocation::query()->where('supplier_refund_id', $id)->orderBy('id')->get();
        $notes = PurchaseDebitNote::query()->whereIn('id', $allocations->pluck('purchase_debit_note_id')->all())->get()->keyBy('id');
        foreach ($allocations as $allocation) {
            $note = $notes->get($allocation->purchase_debit_note_id);
            if (! $note instanceof PurchaseDebitNote) {
                continue;
            }
            $builder->foreign(
                $this->account(AccountRole::Payable),
                JournalMath::neg($allocation->amount),
                (int) $note->currency_id,
                (string) $note->exchange_rate,
                JournalEntryLine::PARTY_SUPPLIER,
                (string) $refund->supplier_id,
                $label.' — '.$note->debit_note_number,
            );
        }

        $this->exchangeDifference($builder, $label);
        $this->create(SupplierRefund::REFERENCE_TYPE, $id, $date ?? $refund->refund_date->toDateString(), $label, $this->documentBranch($refund), $builder, $userId);
    }

    public function stockAdjustment(StockAdjustment $document, ?string $userId, ?string $date = null): void
    {
        $this->stockVariance(
            StockAdjustment::REFERENCE_TYPE,
            (string) $document->id,
            'Stock adjustment '.$document->adj_number,
            $date ?? $document->adjustment_date->toDateString(),
            $this->documentBranch($document),
            $userId,
            false,
        );
    }

    public function stockCount(StockCount $document, ?string $userId, ?string $date = null): void
    {
        $this->stockVariance(
            StockCount::REFERENCE_TYPE,
            (string) $document->id,
            'Stock count '.$document->cnt_number,
            $date ?? $document->count_date->toDateString(),
            $this->documentBranch($document),
            $userId,
            false,
        );
    }

    public function stockRevaluation(StockRevaluation $document, ?string $userId, ?string $date = null): void
    {
        $this->stockVariance(
            StockRevaluation::REFERENCE_TYPE,
            (string) $document->id,
            'Stock revaluation '.$document->srv_number,
            $date ?? $document->revaluation_date->toDateString(),
            $this->documentBranch($document),
            $userId,
            false,
        );
    }

    public function production(Production $document, ?string $userId, ?string $date = null): void
    {
        $this->stockVariance(
            Production::REFERENCE_TYPE,
            (string) $document->id,
            'Production '.$document->prd_number,
            $date ?? $document->production_date->toDateString(),
            $this->documentBranch($document),
            $userId,
            true,
        );
    }

    /**
     * A write-off never returns the quantity transfer-out already removed, so the inventory
     * account has to give that value up. A return puts the stock back and posts nothing here.
     */
    public function stockTransferClosure(StockTransferClosure $closure, ?string $userId, ?string $date = null): void
    {
        $id = (string) $closure->id;
        if (! $this->shouldPost(StockTransferClosure::REFERENCE_TYPE, $id)) {
            return;
        }

        $closure->loadMissing('lines');
        $costs = $this->transferOutUnitCosts((string) $closure->stock_transfer_id);
        $total = '0';
        foreach ($closure->lines as $line) {
            if ($line->outcome !== StockTransferClosureOutcome::WriteOff) {
                continue;
            }
            $key = $line->item_id.'|'.($line->lot_id ?? '');
            $value = JournalMath::mul((string) $line->base_quantity, $costs[$key] ?? '0');
            $total = JournalMath::add($total, $value);
        }
        if (JournalMath::isZero($total)) {
            return;
        }

        $label = 'Transfer write-off '.($closure->closure_number ?? $id);
        $builder = $this->builder()
            ->primary($this->account(AccountRole::InventoryShortage), $total, null, null, $label)
            ->primary($this->account(AccountRole::Inventory), JournalMath::neg($total), null, null, $label);

        $closedOn = $closure->closed_at?->toDateString() ?? Carbon::today()->toDateString();
        $this->create(
            StockTransferClosure::REFERENCE_TYPE,
            $id,
            $date ?? $closedOn,
            $label,
            $this->documentBranch($closure),
            $builder,
            $userId,
        );
    }

    public function openingStock(OpeningStock $document, ?string $userId, ?string $date = null): void
    {
        $id = (string) $document->id;
        if (! $this->shouldPost(OpeningStock::REFERENCE_TYPE, $id)) {
            return;
        }

        $label = 'Opening stock '.$document->os_number;
        $value = $this->stockValue(OpeningStock::REFERENCE_TYPE, $id);
        $builder = $this->builder()
            ->primary($this->account(AccountRole::Inventory), $value, null, null, $label)
            ->primary($this->account(AccountRole::OpeningBalanceEquity), JournalMath::neg($value), null, null, $label);

        $this->create(OpeningStock::REFERENCE_TYPE, $id, $date ?? $document->opening_date->toDateString(), $label, $this->documentBranch($document), $builder, $userId);
    }

    /**
     * Opening documents are edited in place while nothing uses them: drop their entry so it can be posted again.
     */
    public function deleteDocumentEntries(string $sourceType, string $sourceId): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->journalEntryService->deleteAutomatic($sourceType, $sourceId);
    }

    /**
     * Money already in a cash box / bank account when it is added: Dr its ledger account / Cr opening balance equity.
     */
    public function cashBankOpeningBalance(CashBankAccount $account, ?string $userId = null): void
    {
        $id = (string) $account->id;
        $amount = (string) $account->opening_balance;
        if (JournalMath::isZero($amount) || ! $this->shouldPost(SourceDocuments::CASH_BANK_OPENING_BALANCE, $id)) {
            return;
        }

        $account->loadMissing('account');
        if ($account->account === null) {
            abort(422, 'The cash/bank account needs a ledger account.', ['X-Error-Code' => 'TREASURY_ACCOUNT_REQUIRED']);
        }

        $currencyId = (int) $account->currency_id;
        $rate = ExchangeRateSnapshot::resolve($this->exchangeRateService, $currencyId, null, 'ACCOUNTING');
        $label = 'Opening balance '.$account->code;
        $builder = $this->builder()
            ->foreign($account->account, $amount, $currencyId, $rate, null, null, $label)
            ->foreign($this->account(AccountRole::OpeningBalanceEquity), JournalMath::neg($amount), $currencyId, $rate, null, null, $label);

        $date = $account->opening_date?->toDateString() ?? Carbon::today()->toDateString();
        $this->create(SourceDocuments::CASH_BANK_OPENING_BALANCE, $id, $date, $label, $this->branchOrDefault($account->branch_id), $builder, $userId);
    }

    public function replaceCashBankOpeningBalance(CashBankAccount $account, ?string $userId = null): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->journalEntryService->deleteAutomatic(SourceDocuments::CASH_BANK_OPENING_BALANCE, (string) $account->id);
        $this->cashBankOpeningBalance($account, $userId);
    }

    /**
     * Mirror the document's posting entry. Does nothing when there is none
     * (module off, or the document was posted before accounting existed).
     */
    public function reverse(string $sourceType, string $sourceId, ?string $userId, ?string $date = null): void
    {
        if (! $this->enabled() || $this->hasEntry($sourceType, $sourceId, JournalEntry::EVENT_REVERSE)) {
            return;
        }

        $original = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_event', JournalEntry::EVENT_POST)
            ->where('status', JournalEntryStatus::Posted)
            ->lockForUpdate()
            ->first();
        if ($original === null) {
            return;
        }

        $this->journalEntryService->reverseEntry($original, $userId, $date);
    }

    private function stockVariance(string $type, string $id, string $label, string $date, ?int $branchId, ?string $userId, bool $netOnly): void
    {
        if (! $this->shouldPost($type, $id)) {
            return;
        }

        $increase = '0';
        $decrease = '0';
        foreach ($this->originalMovements($type, $id) as $movement) {
            $value = (string) $movement->value_delta;
            if (JournalMath::isPositive($value, JournalMath::STORAGE_SCALE)) {
                $increase = JournalMath::add($increase, $value);
            } else {
                $decrease = JournalMath::add($decrease, $value);
            }
        }
        if ($netOnly) {
            $net = JournalMath::add($increase, $decrease);
            $increase = JournalMath::isPositive($net, JournalMath::STORAGE_SCALE) ? $net : '0';
            $decrease = JournalMath::isNegative($net, JournalMath::STORAGE_SCALE) ? $net : '0';
        }

        $builder = $this->builder();
        if (JournalMath::isPositive($increase)) {
            $builder
                ->primary($this->account(AccountRole::Inventory), $increase, null, null, $label)
                ->primary($this->account(AccountRole::InventorySurplus), JournalMath::neg($increase), null, null, $label);
        }
        if (JournalMath::isNegative($decrease)) {
            $builder
                ->primary($this->account(AccountRole::InventoryShortage), JournalMath::neg($decrease), null, null, $label)
                ->primary($this->account(AccountRole::Inventory), $decrease, null, null, $label);
        }

        $this->create($type, $id, $date, $label, $branchId, $builder, $userId);
    }

    /**
     * Opening invoice / opening credit: an old open balance brought in from before the system.
     * Receivable or payable (signed, positive = debit) against Opening Balance Equity. No revenue, VAT or stock.
     */
    private function openingDocument(
        string $type,
        string $id,
        string $label,
        string $signedAmount,
        Model $document,
        AccountRole $partyRole,
        string $partyType,
        string $partyId,
        string $date,
        ?string $userId,
    ): void {
        if (JournalMath::isZero($signedAmount)) {
            return;
        }

        $currencyId = (int) $document->getAttribute('currency_id');
        $rate = (string) $document->getAttribute('exchange_rate');
        $builder = $this->builder()
            ->foreign($this->account($partyRole), $signedAmount, $currencyId, $rate, $partyType, $partyId, $label)
            ->foreign($this->account(AccountRole::OpeningBalanceEquity), JournalMath::neg($signedAmount), $currencyId, $rate, null, null, $label);

        $this->create($type, $id, $date, $label, $this->documentBranch($document), $builder, $userId);
    }

    private function rateApplication(
        string $sourceType,
        string $noteNumber,
        string $noteId,
        string $noteRate,
        ?string $invoiceNumber,
        string $invoiceRate,
        int $currencyId,
        string $amount,
        AccountRole $partyRole,
        string $partyType,
        string $partyId,
        ?string $userId,
        ?string $date,
        string $kind = 'Credit application',
        ?int $branchId = null,
    ): void {
        if (! $this->enabled() || $this->sameRate($noteRate, $invoiceRate)) {
            return;
        }

        $label = $kind.' '.$noteNumber.($invoiceNumber ? ' — '.$invoiceNumber : '');
        $builder = $this->builder()
            ->foreign($this->account($partyRole), $amount, $currencyId, $noteRate, $partyType, $partyId, $label)
            ->foreign($this->account($partyRole), JournalMath::neg($amount), $currencyId, $invoiceRate, $partyType, $partyId, $label);
        $this->exchangeDifference($builder, $label);
        $this->create($sourceType, $noteId.':'.(string) Str::ulid(), $date ?? Carbon::today()->toDateString(), $label, $branchId, $builder, $userId);
    }

    private function reverseApplications(string $sourceType, string $noteId, ?string $userId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $entries = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', 'like', $noteId.':%')
            ->where('source_event', JournalEntry::EVENT_POST)
            ->where('status', JournalEntryStatus::Posted)
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            if ($this->hasEntry($entry->source_type, (string) $entry->source_id, JournalEntry::EVENT_REVERSE)) {
                continue;
            }
            $this->journalEntryService->reverseEntry($entry, $userId);
        }
    }

    /**
     * The branch stored on the document when it was issued (docs/printing-plan.md D3). Never looked up
     * from the warehouse, so moving a warehouse to another branch does not move posted entries.
     */
    /**
     * Opening balances have no document branch. Branch-filtered reports skip entries without one,
     * so fall back to the company's default branch (or the first branch).
     */
    private function branchOrDefault(mixed $branchId): ?int
    {
        if ($branchId !== null && $branchId !== '') {
            return (int) $branchId;
        }

        return Branch::defaultId();
    }

    private function documentBranch(Model $document): ?int
    {
        $branchId = $document->getAttribute('branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }

    private function sameRate(mixed $left, mixed $right): bool
    {
        return bccomp(bcadd((string) $left, '0', ExchangeRateSnapshot::SCALE), bcadd((string) $right, '0', ExchangeRateSnapshot::SCALE), ExchangeRateSnapshot::SCALE) === 0;
    }

    private function exchangeDifference(JournalBuilder $builder, string $label): void
    {
        $net = $builder->net();
        if (JournalMath::isPositive($net)) {
            $builder->primary($this->account(AccountRole::ExchangeGain), JournalMath::neg($net), null, null, $label);
        } elseif (JournalMath::isNegative($net)) {
            $builder->primary($this->account(AccountRole::ExchangeLoss), JournalMath::neg($net), null, null, $label);
        }
    }

    private function shouldPost(string $sourceType, string $sourceId): bool
    {
        return $this->enabled() && ! $this->hasEntry($sourceType, $sourceId);
    }

    private function create(string $sourceType, string $sourceId, string $date, string $description, ?int $branchId, JournalBuilder $builder, ?string $userId, string $event = JournalEntry::EVENT_POST): void
    {
        $lines = $builder->lines($this->account(AccountRole::Rounding));
        if ($lines === []) {
            return;
        }

        $this->journalEntryService->createPosted([
            'entry_date' => $date,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_event' => $event,
            'branch_id' => $branchId,
            'description' => $description,
        ], $lines, $userId);
    }

    private function builder(): JournalBuilder
    {
        $primary = Currency::getPrimary();
        if ($primary === null) {
            abort(422, 'A primary currency is required.', ['X-Error-Code' => 'ACCOUNTING_PRIMARY_CURRENCY_REQUIRED']);
        }

        return new JournalBuilder((int) $primary->id);
    }

    private function account(AccountRole $role): Account
    {
        return $this->accountMappingService->accountFor($role);
    }

    /**
     * Ledger account of the cash box / bank account the money moved through.
     */
    private function cashBankLedger(?int $cashBankAccountId): Account
    {
        $account = $cashBankAccountId !== null ? CashBankAccount::query()->with('account')->find($cashBankAccountId)?->account : null;
        if (! $account instanceof Account) {
            abort(422, 'Choose the cash box or bank account for this payment.', ['X-Error-Code' => 'PAYMENT_CASH_BANK_ACCOUNT_REQUIRED']);
        }

        return $account;
    }

    /**
     * Unit cost of each transfer-out movement, keyed by item and lot. Same costs a return uses.
     *
     * @return array<string, string>
     */
    private function transferOutUnitCosts(string $transferId): array
    {
        $costs = [];
        $movements = StockMovement::query()
            ->where('reference_type', StockTransfer::REFERENCE_TYPE)
            ->where('reference_id', $transferId)
            ->where('type', StockMovementType::TransferOut)
            ->get(['item_id', 'lot_id', 'unit_cost']);
        foreach ($movements as $movement) {
            $costs[$movement->item_id.'|'.($movement->lot_id ?? '')] = (string) $movement->unit_cost;
        }

        return $costs;
    }

    private function stockValue(string $referenceType, string $referenceId): string
    {
        $total = '0';
        foreach ($this->originalMovements($referenceType, $referenceId) as $movement) {
            $total = JournalMath::add($total, $movement->value_delta);
        }

        return $total;
    }

    /**
     * Movements a document posted, without the mirror rows its reversal added later.
     * Reversal rows share the reference and come after every original, starting with a "Reverse" note.
     *
     * @return Collection<int, StockMovement>
     */
    private function originalMovements(string $referenceType, string $referenceId): Collection
    {
        $movements = StockMovement::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->orderBy('id')
            ->get(['id', 'item_id', 'lot_id', 'quantity_delta', 'value_delta', 'notes']);

        $firstReverse = $movements->first(fn (StockMovement $m): bool => str_starts_with((string) $m->notes, 'Reverse'));
        if ($firstReverse === null) {
            return $movements;
        }

        return $movements->filter(fn (StockMovement $m): bool => $m->id < $firstReverse->id)->values();
    }

    /**
     * Value the receipt put into stock for the receipt lines an invoice matched.
     *
     * @param  list<int>  $receiptLineIds
     */
    private function matchedReceiptValue(string $goodsReceiptId, array $receiptLineIds): string
    {
        $value = '0';
        if ($receiptLineIds === []) {
            return $value;
        }

        $byKey = [];
        foreach ($this->originalMovements(GoodsReceipt::REFERENCE_TYPE, $goodsReceiptId) as $movement) {
            $key = $movement->item_id.'|'.($movement->lot_id ?? '');
            $byKey[$key] = JournalMath::add($byKey[$key] ?? '0', $movement->value_delta);
        }

        $lines = GoodsReceiptLine::query()
            ->where('goods_receipt_id', $goodsReceiptId)
            ->whereIn('id', $receiptLineIds)
            ->get(['id', 'item_id', 'lot_id']);
        foreach ($lines as $line) {
            $key = $line->item_id.'|'.($line->lot_id ?? '');
            if (isset($byKey[$key])) {
                $value = JournalMath::add($value, $byKey[$key]);
                unset($byKey[$key]);
            }
        }

        return $value;
    }
}
