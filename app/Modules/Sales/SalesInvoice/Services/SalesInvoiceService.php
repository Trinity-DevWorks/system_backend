<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Services\CreditLimitGuard;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\Stock\Services\StockReservationService;
use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\CustomerReceipt\Enums\CustomerReceiptStatus;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceiptAllocation;
use App\Modules\Sales\CustomerReceipt\Services\CustomerReceiptService;
use App\Modules\Sales\DeliveryNote\Services\DeliveryNoteInvoicing;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoicePayment;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceLineQuantity;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Sales\SalesOrder\Services\SalesOrderFulfillment;
use App\Modules\Sales\Support\CommercialLineBuilder;
use App\Modules\Sales\Support\DiscountGuard;
use App\Modules\Treasury\Enums\TreasuryDocumentStatus;
use App\Modules\Treasury\Models\TreasuryTransfer;
use App\Modules\Treasury\Services\TreasuryTransferService;
use App\Modules\Treasury\Support\PaymentCashBankAccount;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\Decimal;
use App\Support\DocumentNumberService;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use App\Support\DocumentType;
use App\Support\ExchangeRateSnapshot;
use App\Support\OpeningDocumentService;
use App\Support\PaymentAllocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesInvoiceService
{
    public function __construct(
        private readonly SalesInvoiceQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly StockMovementService $stockMovementService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly JournalPostingService $journalPostingService,
        private readonly SalesOrderFulfillment $salesOrderFulfillment,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   customer_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, SalesInvoice>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryService->paginate($filters, $perPage);
    }

    public function find(string $id): SalesInvoice
    {
        $invoice = SalesInvoice::query()
            ->withPostedCreditTotal()
            ->with([
                'customer',
                'warehouse',
                'currency',
                'salesman',
                'paymentMethod',
                'paymentTerm',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'salesOrder:id,order_number,status',
                'lines.item',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
                'lines.salesOrderLine',
                'payments.paymentMethod:id,code,name,type,supports_change',
                'payments.cashBankAccount:id,code,name',
                'payments.customerReceipt:id,receipt_number,status',
            ])
            ->findOrFail($id);

        $this->warehouseService->assertDocumentVisible($invoice);

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SalesInvoice
    {
        $customer = SalesInvoiceRules::assertCustomer((string) $data['customer_id']);
        SalesInvoiceRules::assertWarehouse((int) $data['warehouse_id']);

        return DB::transaction(function () use ($data, $userId, $customer): SalesInvoice {
            $header = $this->normalizeHeader($data, $customer, null);

            $invoice = SalesInvoice::query()->create([
                ...$header,
                'sales_order_id' => isset($data['sales_order_id']) && $data['sales_order_id'] !== ''
                    ? (string) $data['sales_order_id']
                    : null,
                'status' => SalesInvoiceStatus::Draft,
                'paid_total' => PriceMath::normalize(0),
                'created_by' => $userId,
            ]);

            $invoice->update([
            ]);

            if (! empty($data['lines'])) {
                $this->replaceLines($invoice, $data['lines']);
            } else {
                $this->recalculateTotals($invoice);
            }

            return $this->find($invoice->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SalesInvoice $invoice, array $data): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $data): SalesInvoice {
            $invoice = $this->lockDraft($invoice);
            $customerId = array_key_exists('customer_id', $data)
                ? (string) $data['customer_id']
                : (string) $invoice->customer_id;
            $customer = SalesInvoiceRules::assertCustomer($customerId);

            if (array_key_exists('warehouse_id', $data)) {
                SalesInvoiceRules::assertWarehouse((int) $data['warehouse_id']);
            }

            $merged = array_merge($invoice->only([
                'customer_id',
                'warehouse_id',
                'currency_id',
                'salesman_id',
                'payment_method_id',
                'payment_terms_id',
                'invoice_date',
                'due_on',
                'exchange_rate',
                'reference_2',
                'billing_address',
                'shipping_address',
                'adjustment',
                'header_discount_type',
                'header_discount_value',
                'notes',
            ]), $data);

            $header = $this->normalizeHeader($merged, $customer, $invoice);
            $invoice->update($header);
            $this->recalculateTotals($invoice->fresh() ?? $invoice);

            return $this->find($invoice->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(SalesInvoice $invoice, array $lines): Collection
    {
        return DB::transaction(function () use ($invoice, $lines): Collection {
            $invoice = $this->lockDraft($invoice);
            $this->replaceLines($invoice, $lines);

            return SalesInvoiceLine::query()
                ->where('sales_invoice_id', $invoice->id)
                ->with(['item', 'itemUom.uom', 'warehouse', 'lot'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(SalesInvoice $invoice): void
    {
        SalesInvoiceRules::assertDraft($invoice);
        $this->warehouseService->assertDocumentVisible($invoice);
        $invoice->delete();
    }

    /**
     * @param  array{email?: mixed, password?: mixed}|null  $discountApproval  manager login when the discount is above the poster's limit
     */
    public function post(SalesInvoice $invoice, ?string $userId, ?array $discountApproval = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId, $discountApproval): SalesInvoice {
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            app(DocumentNumberService::class)->assign($locked, 'invoice_number', DocumentType::SalesInvoice, $locked->branch_id !== null ? (int) $locked->branch_id : null, $locked->invoice_date);
            SalesInvoiceRules::assertPostable($locked);
            $this->warehouseService->assertDocumentVisible($locked);

            $this->recalculateTotals($locked);
            $locked->refresh();

            $approvedBy = app(DiscountGuard::class)->assert(
                $locked,
                SalesInvoiceLine::query()->where('sales_invoice_id', $locked->id)->get(),
                $discountApproval,
            );
            if ($approvedBy !== null) {
                $locked->update(['discount_approved_by' => $approvedBy, 'discount_approved_at' => now()]);
            }

            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
            $payments = SalesInvoicePayment::query()->where('sales_invoice_id', $locked->id)->orderBy('sort_order')->orderBy('id')->get();
            $keepExtra = (bool) $locked->keep_extra_as_credit;
            $paidNow = $this->assertPayments($locked, $payments->map(fn (SalesInvoicePayment $p): array => $this->paymentRowData($p))->all(), true, $keepExtra);
            if (Decimal::cmp($paidNow, (string) $locked->grand_total) > 0) {
                $paidNow = (string) $locked->grand_total;
            }
            if ($customer->is_system && ! $this->withinReceiptTolerance($locked, $paidNow, (string) $locked->grand_total)) {
                abort(422, 'A walk-in customer must pay the whole invoice at the counter.', ['X-Error-Code' => 'SALES_INVOICE_WALK_IN_MUST_PAY']);
            }
            // Only what stays on the customer's account counts against the credit limit.
            $openAfterPayments = Decimal::sub((string) $locked->grand_total, $paidNow);
            app(CreditLimitGuard::class)->assert($customer, (int) $locked->currency_id, Decimal::isPositive($openAfterPayments) ? Decimal::round($openAfterPayments, 4) : '0');
            $this->salesOrderFulfillment->applyInvoice($locked, false);
            app(DeliveryNoteInvoicing::class)->applyInvoice($locked, false);

            if (bccomp((string) $locked->grand_total, '0', 4) > 0) {
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

            $lines = SalesInvoiceLine::query()
                ->where('sales_invoice_id', $locked->id)
                ->with(['item.itemType', 'lot'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                // Lines from a delivery note bill goods that already left the warehouse.
                if ($line->delivery_note_line_id !== null) {
                    continue;
                }
                $this->postLineStock($locked, $line, $userId);
            }

            $this->journalPostingService->salesInvoice($locked, $userId);

            $locked->update([
                'status' => SalesInvoiceStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            // Each payment taken at the counter becomes a posted receipt allocated to this invoice.
            $this->settlePayments($locked, $payments, $userId, null, (string) $locked->grand_total, $keepExtra);

            return $this->find($locked->id);
        });
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
            // Change given in another currency was a cash transfer between boxes: undo it first.
            $transfers = app(TreasuryTransferService::class);
            foreach (SalesInvoicePayment::query()->where('sales_invoice_id', $locked->id)->whereNotNull('treasury_transfer_id')->get() as $payment) {
                $transfer = TreasuryTransfer::query()->find($payment->treasury_transfer_id);
                if ($transfer !== null && $transfer->status === TreasuryDocumentStatus::Posted) {
                    $transfers->reverse($transfer, $userId);
                }
            }
            // Customer credit used on this invoice goes back to the receipts it came from.
            $receiptService = app(CustomerReceiptService::class);
            foreach (CustomerReceiptAllocation::query()->where('sales_invoice_id', $locked->id)->where('applied_later', true)->get() as $credit) {
                $receiptService->unapplyCredit($credit, $userId);
            }
            $locked->refresh();
            // Payments taken with the invoice are reversed with it; other receipts still block.
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
            if (bccomp((string) $locked->paid_total, '0', 4) > 0) {
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

            if (bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);
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

            $this->journalPostingService->reverse(SalesInvoice::REFERENCE_TYPE, (string) $locked->id, $userId);
            $this->salesOrderFulfillment->applyInvoice($locked, true);
            app(DeliveryNoteInvoicing::class)->applyInvoice($locked, true);

            $locked->update(['status' => SalesInvoiceStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    /**
     * Warehouses (and lots) that currently hold the item — for line warehouse pickers.
     *
     * @return list<array<string, mixed>>
     */
    public function itemAvailability(string $itemId): array
    {
        Item::query()->findOrFail($itemId);

        $query = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('quantity', '>', 0)
            ->with([
                'warehouse:id,name,shortcut_name,is_active',
                'lot:id,lot_number,expiry_date,item_id',
            ]);

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        $rows = $query->get();
        $byWarehouse = [];
        foreach ($rows as $row) {
            $wid = (int) $row->warehouse_id;
            if (! isset($byWarehouse[$wid])) {
                $byWarehouse[$wid] = [
                    'warehouse_id' => $wid,
                    'on_hand' => '0',
                    'warehouse' => $row->warehouse ? [
                        'id' => $row->warehouse->id,
                        'name' => $row->warehouse->name,
                        'shortcut_name' => $row->warehouse->shortcut_name,
                        'is_active' => (bool) $row->warehouse->is_active,
                    ] : null,
                    'lots' => [],
                ];
            }
            $byWarehouse[$wid]['on_hand'] = bcadd($byWarehouse[$wid]['on_hand'], (string) $row->quantity, 6);
            if ($row->lot_id && $row->lot) {
                $byWarehouse[$wid]['lots'][] = [
                    'id' => $row->lot->id,
                    'lot_number' => $row->lot->lot_number,
                    'expiry_date' => $row->lot->expiry_date?->toDateString(),
                    'on_hand' => (string) $row->quantity,
                ];
            }
        }

        $reservations = app(StockReservationService::class);
        foreach ($byWarehouse as $wid => $row) {
            $reserved = $reservations->reservedFor($itemId, (int) $wid);
            $byWarehouse[$wid]['reserved'] = $reserved;
            $byWarehouse[$wid]['available'] = bcsub($row['on_hand'], $reserved, 6);
        }

        return array_values($byWarehouse);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $data, Customer $customer, ?SalesInvoice $existing): array
    {
        $invoiceDate = isset($data['invoice_date']) && $data['invoice_date'] !== ''
            ? Carbon::parse((string) $data['invoice_date'])->toDateString()
            : ($existing?->invoice_date?->toDateString() ?? now()->toDateString());

        $paymentTermsId = array_key_exists('payment_terms_id', $data)
            ? ($data['payment_terms_id'] !== null && $data['payment_terms_id'] !== '' ? (int) $data['payment_terms_id'] : null)
            : ($existing?->payment_terms_id !== null ? (int) $existing->payment_terms_id : $customer->payment_terms_id);

        $dueOn = isset($data['due_on']) && $data['due_on'] !== ''
            ? Carbon::parse((string) $data['due_on'])->toDateString()
            : $this->defaultDueOn($invoiceDate, $paymentTermsId);

        $currencyId = isset($data['currency_id']) && $data['currency_id'] !== null
            ? (int) $data['currency_id']
            : ($existing !== null ? (int) $existing->currency_id : (int) (Currency::getPrimary()?->id ?? 0));

        if ($currencyId <= 0) {
            abort(422, 'A currency is required.', ['X-Error-Code' => 'SALES_INVOICE_CURRENCY_REQUIRED']);
        }

        $exchangeRate = ExchangeRateSnapshot::resolve(
            $this->exchangeRateService,
            $currencyId,
            $data['exchange_rate']
                ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
            'SALES_INVOICE',
        );

        $salesmanId = array_key_exists('salesman_id', $data)
            ? ($data['salesman_id'] !== null && $data['salesman_id'] !== '' ? (string) $data['salesman_id'] : null)
            : ($existing?->salesman_id ?? $customer->salesman_id);

        $paymentMethodId = array_key_exists('payment_method_id', $data)
            ? ($data['payment_method_id'] !== null && $data['payment_method_id'] !== '' ? (int) $data['payment_method_id'] : null)
            : ($existing !== null ? $existing->payment_method_id : $customer->payment_method_id);

        $billingIncoming = $data['billing_address'] ?? null;
        if (isset($data['billing_address_id']) && $data['billing_address_id'] !== null && $data['billing_address_id'] !== '') {
            $billingIncoming = ['id' => (int) $data['billing_address_id']];
        }
        $shippingIncoming = $data['shipping_address'] ?? null;
        if (isset($data['shipping_address_id']) && $data['shipping_address_id'] !== null && $data['shipping_address_id'] !== '') {
            $shippingIncoming = ['id' => (int) $data['shipping_address_id']];
        }

        $billing = $this->resolveAddressSnapshot(
            $billingIncoming,
            $existing?->billing_address,
            $customer,
            'billing',
            $existing === null || (array_key_exists('customer_id', $data) && (string) $data['customer_id'] !== (string) $existing->customer_id),
        );
        $shipping = $this->resolveAddressSnapshot(
            $shippingIncoming,
            $existing?->shipping_address,
            $customer,
            'shipping',
            $existing === null || (array_key_exists('customer_id', $data) && (string) $data['customer_id'] !== (string) $existing->customer_id),
        );

        $adjustment = array_key_exists('adjustment', $data)
            ? PriceMath::normalize($data['adjustment'] ?? 0)
            : ($existing !== null ? (string) $existing->adjustment : PriceMath::normalize(0));

        return [
            'customer_id' => (string) $customer->id,
            'warehouse_id' => (int) ($data['warehouse_id'] ?? $existing?->warehouse_id),
            'currency_id' => $currencyId,
            'salesman_id' => $salesmanId,
            'payment_method_id' => $paymentMethodId,
            'payment_terms_id' => $paymentTermsId,
            'invoice_date' => $invoiceDate,
            'due_on' => $dueOn,
            'exchange_rate' => $exchangeRate,
            'reference_2' => $this->nullableString($data['reference_2'] ?? $existing?->reference_2),
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'adjustment' => $adjustment,
            ...CommercialLineBuilder::headerDiscount($data, $existing),
            'notes' => $this->nullableString($data['notes'] ?? $existing?->notes),
        ];
    }

    private function defaultDueOn(string $invoiceDate, ?int $paymentTermsId): string
    {
        $days = 0;
        if ($paymentTermsId !== null) {
            $term = PaymentTerm::query()->find($paymentTermsId);
            $days = (int) ($term?->due_days ?? 0);
        }

        return Carbon::parse($invoiceDate)->addDays($days)->toDateString();
    }

    /**
     * @param  array<string, mixed>|null  $incoming
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|null
     */
    private function resolveAddressSnapshot(
        mixed $incoming,
        mixed $existing,
        Customer $customer,
        string $type,
        bool $prefillFromCustomer,
    ): ?array {
        if (is_array($incoming) && $incoming !== []) {
            $line1 = trim((string) ($incoming['address_line_1'] ?? ''));
            $line2 = trim((string) ($incoming['address_line_2'] ?? ''));
            $phone = trim((string) ($incoming['phone'] ?? ''));
            $hasManual = $line1 !== '' || $line2 !== '' || $phone !== '';

            if ($hasManual) {
                return $this->normalizeAddressArray($incoming, $type);
            }

            $id = isset($incoming['id']) ? (int) $incoming['id'] : 0;
            if ($id > 0) {
                $row = $customer->addresses()
                    ->whereKey($id)
                    ->where('address_type', $type)
                    ->first();
                if ($row instanceof CustomerAddress) {
                    return $this->snapshotAddress($row);
                }
            }
        }

        if (! $prefillFromCustomer && is_array($existing)) {
            return $existing;
        }

        $row = $customer->addresses()
            ->where('address_type', $type)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        return $row instanceof CustomerAddress ? $this->snapshotAddress($row) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeAddressArray(array $row, string $type): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'address_type' => (string) ($row['address_type'] ?? $type),
            'address_line_1' => (string) ($row['address_line_1'] ?? ''),
            'address_line_2' => isset($row['address_line_2']) ? (string) $row['address_line_2'] : null,
            'city' => (string) ($row['city'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'phone' => isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotAddress(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
            'address_type' => (string) $address->address_type,
            'address_line_1' => $address->address_line_1,
            'address_line_2' => $address->address_line_2,
            'city' => $address->city,
            'state' => $address->state,
            'country' => $address->country,
            'phone' => $address->phone,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesInvoice $invoice, array $lines): void
    {
        $invoice->loadMissing('customer');
        $customer = $invoice->customer;
        $date = $invoice->invoice_date->toDateString();
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $headerWarehouseId = (int) $invoice->warehouse_id;

        SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->delete();

        foreach (array_values($lines) as $index => $row) {
            $item = Item::query()->findOrFail((string) $row['item_id']);
            SalesInvoiceRules::assertSellableItem($item);

            $qty = SalesInvoiceLineQuantity::resolve(
                $item,
                $row['quantity'],
                isset($row['item_uom_id']) && $row['item_uom_id'] !== null && $row['item_uom_id'] !== ''
                    ? (int) $row['item_uom_id']
                    : null,
            );

            $trackInventory = (bool) $item->track_inventory;
            $warehouseId = isset($row['warehouse_id']) && $row['warehouse_id'] !== null && $row['warehouse_id'] !== ''
                ? (int) $row['warehouse_id']
                : ($trackInventory ? $headerWarehouseId : null);

            if ($trackInventory) {
                if ($warehouseId === null) {
                    abort(422, 'Warehouse is required for stock items.', ['X-Error-Code' => 'SALES_INVOICE_LINE_WAREHOUSE_REQUIRED']);
                }
                SalesInvoiceRules::assertWarehouse($warehouseId);
            } elseif ($warehouseId !== null) {
                SalesInvoiceRules::assertWarehouse($warehouseId);
            }

            $lotId = isset($row['lot_id']) && $row['lot_id'] !== null && $row['lot_id'] !== ''
                ? (int) $row['lot_id']
                : null;
            if ($item->track_lots) {
                if ($lotId === null) {
                    abort(422, 'Select a lot for this item.', ['X-Error-Code' => 'STOCK_LOT_REQUIRED']);
                }
                $lot = InventoryLot::query()->findOrFail($lotId);
                if ((string) $lot->item_id !== (string) $item->id) {
                    abort(422, 'Lot does not belong to this item.', ['X-Error-Code' => 'SALES_INVOICE_LOT_ITEM_MISMATCH']);
                }
            } else {
                $lotId = null;
            }

            $unitPrice = PriceMath::normalize($row['unit_price'] ?? 0);
            $discountPercent = (string) ($row['discount_percent'] ?? 0);
            $taxRate = DocumentTaxContext::ratePercentForItem($item, $customer, $date);
            $math = DocumentTaxMath::line(
                $qty['quantity'],
                $unitPrice,
                $discountPercent,
                $taxRate,
                $includeTax,
            );

            $deliveryLineId = isset($row['delivery_note_line_id']) && $row['delivery_note_line_id'] !== null && $row['delivery_note_line_id'] !== ''
                ? (int) $row['delivery_note_line_id']
                : null;
            if ($deliveryLineId !== null) {
                app(DeliveryNoteInvoicing::class)->assertLinkable($deliveryLineId, (string) $invoice->customer_id, (string) $item->id, $qty['item_uom_id']);
            }

            SalesInvoiceLine::query()->create([
                'sales_invoice_id' => $invoice->id,
                'sales_order_line_id' => isset($row['sales_order_line_id']) && $row['sales_order_line_id'] !== null && $row['sales_order_line_id'] !== ''
                    ? (int) $row['sales_order_line_id']
                    : null,
                'delivery_note_line_id' => $deliveryLineId,
                'item_id' => $item->id,
                'item_uom_id' => $qty['item_uom_id'],
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'sort_order' => $index + 1,
                'quantity' => $qty['quantity'],
                'base_quantity' => $qty['base_quantity'],
                'conversion_factor' => $qty['conversion_factor'],
                'unit_price' => $unitPrice,
                'discount_percent' => PriceMath::normalize($discountPercent),
                'discount_amount' => $math['discount_amount'],
                'tax_rate' => $taxRate,
                'line_subtotal' => $math['line_subtotal'],
                'tax_amount' => $math['tax_amount'],
                'line_total' => $math['line_total'],
                'description' => $this->nullableString($row['description'] ?? $item->description ?? $item->name),
                'notes' => $this->nullableString($row['notes'] ?? null),
            ]);
        }

        $this->recalculateTotals($invoice);
    }

    private function recalculateTotals(SalesInvoice $invoice): void
    {
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $rows = SalesInvoiceLine::query()
            ->where('sales_invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        CommercialLineBuilder::applyHeaderDiscount($rows, $invoice->header_discount_type, $invoice->header_discount_value);

        $linePayload = [];
        foreach ($rows as $line) {
            $merchandise = bcmul((string) $line->quantity, (string) $line->unit_price, 8);
            $linePayload[] = [
                'merchandise' => $merchandise,
                'discount_amount' => (string) $line->discount_amount,
                'tax_amount' => (string) $line->tax_amount,
            ];
        }

        $totals = DocumentTaxMath::sumTotals(
            $linePayload,
            $invoice->adjustment,
            $includeTax,
        );

        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
            'paid_total' => PriceMath::normalize($invoice->paid_total ?? 0),
            'net_to_pay' => PaymentAllocation::netToPay($totals['grand_total'], $invoice->paid_total ?? 0, $invoice->credited_total ?? 0),
        ]);
    }

    private function postLineStock(SalesInvoice $invoice, SalesInvoiceLine $line, ?string $userId): void
    {
        $item = $line->item ?? Item::query()->findOrFail($line->item_id);

        if (SalesInvoiceRules::isBundleItem($item)) {
            $this->postBundleComponents($invoice, $line, $item, $userId);

            return;
        }

        if (! $item->track_inventory) {
            return;
        }

        $warehouseId = (int) ($line->warehouse_id ?? $invoice->warehouse_id);
        $note = 'SI '.$invoice->invoice_number;
        $outbound = bcmul((string) $line->base_quantity, '-1', 6);

        $this->stockMovementService->post(StockMovementData::forSale(
            itemId: (string) $line->item_id,
            warehouseId: $warehouseId,
            quantityDelta: $outbound,
            salesInvoiceId: (string) $invoice->id,
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: $line->notes ? $note.' — '.$line->notes : $note,
            userId: $userId,
            lotId: $line->lot_id ? (int) $line->lot_id : null,
        ));
    }

    private function postBundleComponents(
        SalesInvoice $invoice,
        SalesInvoiceLine $line,
        Item $bundle,
        ?string $userId,
    ): void {
        $components = BundleItem::query()
            ->where('bundle_item_id', $bundle->id)
            ->with('childItem.itemType')
            ->get();

        if ($components->isEmpty()) {
            abort(422, 'Bundle has no components.', ['X-Error-Code' => 'SALES_INVOICE_BUNDLE_EMPTY']);
        }

        $warehouseId = (int) ($line->warehouse_id ?? $invoice->warehouse_id);
        $note = 'SI '.$invoice->invoice_number.' bundle '.$bundle->item_code;

        foreach ($components as $component) {
            $child = $component->childItem;
            if (! $child instanceof Item || ! $child->track_inventory) {
                continue;
            }
            if ($child->track_lots) {
                abort(422, 'Lot-tracked bundle components cannot be sold on an invoice. Explode the bundle first.', [
                    'X-Error-Code' => 'SALES_INVOICE_BUNDLE_LOT_NOT_SUPPORTED',
                ]);
            }

            $baseQty = bcmul((string) $line->base_quantity, (string) $component->quantity, 6);
            $outbound = bcmul($baseQty, '-1', 6);

            $this->stockMovementService->post(StockMovementData::forSaleBundleComponent(
                itemId: (string) $child->id,
                warehouseId: $warehouseId,
                quantityDelta: $outbound,
                salesInvoiceId: (string) $invoice->id,
                notes: $note,
                userId: $userId,
            ));
        }
    }

    /**
     * Payments taken at the moment of sale (draft only). Each row: payment_method_id, cash_bank_account_id
     * (default: the method's account for the invoice branch), amount, reference, tendered (cash).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncPayments(SalesInvoice $invoice, array $rows, bool $keepExtraAsCredit = false): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $rows, $keepExtraAsCredit): SalesInvoice {
            $locked = $this->lockDraft($invoice);
            $this->assertPayments($locked, $rows, false, $keepExtraAsCredit);

            SalesInvoicePayment::query()->where('sales_invoice_id', $locked->id)->delete();
            foreach (array_values($rows) as $index => $row) {
                $this->createPaymentRow($locked, $row, $index + 1);
            }
            $locked->update(['keep_extra_as_credit' => $keepExtraAsCredit]);

            return $this->find($locked->id);
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
            $this->settlePayments($locked, $payments, $userId, $paymentDate ?? now()->toDateString(), (string) $locked->net_to_pay, $keepExtraAsCredit);

            return $this->find($locked->id);
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
            'reference' => $this->nullableString($row['reference'] ?? null),
            'tendered' => $tendered,
            'change_due' => $tendered !== null ? Decimal::round(Decimal::sub($tendered, $amount), 4) : null,
            ...$this->paymentChange($invoice, $method, $row, $currency, $amount, $tendered),
            'cheque_number' => $isCheque ? $this->nullableString($row['cheque_number'] ?? null) : null,
            'cheque_bank' => $isCheque ? $this->nullableString($row['cheque_bank'] ?? null) : null,
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
    private function paymentRowData(SalesInvoicePayment $payment): array
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
     * Turns payment rows into receipts allocated to the invoice: customer credit first (it was already paid),
     * then money taken now. With `$keepExtra`, what is paid beyond `$due` (invoice currency) stays on the
     * receipts as customer credit. Change in another currency is posted as a cash transfer.
     *
     * @param  Collection<int, SalesInvoicePayment>  $payments
     */
    private function settlePayments(SalesInvoice $invoice, Collection $payments, ?string $userId, ?string $date, string $due, bool $keepExtra): void
    {
        $receipts = app(CustomerReceiptService::class);
        $left = Decimal::isPositive($due) ? Decimal::round($due, 4) : '0';

        foreach ($payments->filter(fn (SalesInvoicePayment $p): bool => $p->isCredit()) as $credit) {
            $want = Decimal::cmp($credit->amount, $left) > 0 ? $left : (string) $credit->amount;
            if (! Decimal::isPositive($want)) {
                continue;
            }
            $applied = $receipts->applyCreditToInvoice($invoice->fresh() ?? $invoice, $want, $userId, $date);
            $left = Decimal::isPositive(Decimal::sub($left, $applied)) ? Decimal::round(Decimal::sub($left, $applied), 4) : '0';
        }

        $tolerance = $this->receiptTolerance($invoice);
        foreach ($payments->reject(fn (SalesInvoicePayment $p): bool => $p->isCredit()) as $payment) {
            $allocate = null;
            if ($keepExtra) {
                $worth = $this->paymentInInvoiceCurrency($invoice, (string) $payment->amount, $this->storedPaymentCurrency($invoice, $payment));
                if (Decimal::cmp(Decimal::sub($worth, $left), $tolerance) > 0) {
                    // Only part of this payment is needed; the rest stays on the receipt as customer credit.
                    $allocate = Decimal::isPositive($left)
                        ? Decimal::round(Decimal::div(Decimal::mul((string) $payment->amount, $left), $worth), 4)
                        : '0';
                }
                $left = Decimal::isPositive(Decimal::sub($left, $worth)) ? Decimal::round(Decimal::sub($left, $worth), 4) : '0';
            }

            $receipt = $receipts->postForInvoicePayment($invoice->fresh() ?? $invoice, $payment, $userId, $date, $allocate);
            $payment->update([
                'customer_receipt_id' => $receipt->id,
                'treasury_transfer_id' => $this->postChangeTransfer($invoice, $payment, $receipt, $userId, $date),
            ]);
        }
    }

    /**
     * Change given in another currency: the change leaves its cash box and the extra cash given stays in the
     * box the payment went to. Posted as a transfer (rate gap to exchange gain/loss). Null when not needed.
     */
    private function postChangeTransfer(SalesInvoice $invoice, SalesInvoicePayment $payment, CustomerReceipt $receipt, ?string $userId, ?string $date): ?string
    {
        if ($payment->change_currency_id === null || $payment->change_amount === null || $payment->tendered === null) {
            return null;
        }
        $received = Decimal::sub((string) $payment->tendered, (string) $payment->amount);
        if (! Decimal::isPositive($received) || $receipt->cash_bank_account_id === null) {
            return null;
        }
        $from = PaymentCashBankAccount::resolve(
            $payment->change_cash_bank_account_id,
            (int) $payment->payment_method_id,
            $invoice->branch_id !== null ? (int) $invoice->branch_id : null,
            (int) $payment->change_currency_id,
            true,
        );

        $transfers = app(TreasuryTransferService::class);
        $transfer = $transfers->create([
            'from_account_id' => $from,
            'to_account_id' => (int) $receipt->cash_bank_account_id,
            'amount_from' => (string) $payment->change_amount,
            'amount_to' => Decimal::round($received, 4),
            'exchange_rate' => (string) $payment->change_rate,
            'exchange_rate_to' => (string) $receipt->exchange_rate,
            'transfer_date' => $date ?? $invoice->invoice_date->toDateString(),
            'reference' => $invoice->invoice_number,
            'notes' => 'Change for sales invoice '.$invoice->invoice_number,
        ], $userId);

        return (string) $transfers->post($transfer, $userId)->id;
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
    private function assertPayments(SalesInvoice $invoice, array $rows, bool $atPost = true, bool $keepExtra = false): string
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
            foreach (app(CustomerReceiptService::class)->availableCredit((string) $invoice->customer_id, (int) $invoice->currency_id) as $credit) {
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

    private function withinReceiptTolerance(SalesInvoice $invoice, string $paid, string $due): bool
    {
        return Decimal::cmp(Decimal::abs(Decimal::sub($paid, $due)), $this->receiptTolerance($invoice)) <= 0;
    }

    private function lockDraft(SalesInvoice $invoice): SalesInvoice
    {
        $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        SalesInvoiceRules::assertDraft($locked);
        $this->warehouseService->assertDocumentVisible($locked);

        return $locked;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
