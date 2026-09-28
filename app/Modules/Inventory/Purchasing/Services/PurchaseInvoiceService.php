<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Purchasing\Support\GoodsReceiptRules;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceLineQuantity;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceRules;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Services\InventoryLotService;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierItemService;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function __construct(
        private readonly PurchaseInvoiceQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly SupplierLedgerService $supplierLedgerService,
        private readonly StockMovementService $stockMovementService,
        private readonly InventoryLotService $inventoryLotService,
        private readonly SupplierItemService $supplierItemService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PurchaseInvoice>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryService->paginate($filters, $perPage);
    }

    public function find(string $id): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::query()
            ->with([
                'supplier',
                'goodsReceipt',
                'purchaseOrder',
                'warehouse',
                'currency',
                'paymentMethod',
                'paymentTerm',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
                'lines.goodsReceiptLine',
                'lines.purchaseOrderLine',
            ])
            ->findOrFail($id);

        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): PurchaseInvoice
    {
        $supplier = PurchaseInvoiceRules::assertSupplier((string) $data['supplier_id']);
        $receiptId = isset($data['goods_receipt_id']) && $data['goods_receipt_id'] !== ''
            ? (string) $data['goods_receipt_id']
            : null;
        $orderId = isset($data['purchase_order_id']) && $data['purchase_order_id'] !== ''
            ? (string) $data['purchase_order_id']
            : null;
        PurchaseInvoiceRules::assertExclusiveSource($receiptId, $orderId);
        $receipt = PurchaseInvoiceRules::assertPostedGoodsReceipt($receiptId, (string) $supplier->id);

        return DB::transaction(function () use ($data, $userId, $supplier, $receipt, $orderId): PurchaseInvoice {
            PurchaseInvoiceRules::assertGoodsReceiptAvailable($receipt);
            $order = $receipt === null
                ? PurchaseInvoiceRules::assertDirectPurchaseOrder($orderId, (string) $supplier->id)
                : null;
            $warehouseId = $order !== null
                ? (int) $order->warehouse_id
                : (int) $data['warehouse_id'];
            PurchaseInvoiceRules::assertWarehouse($warehouseId);

            $header = $this->normalizeHeader($data, $supplier, $receipt, $order, null);

            $invoice = PurchaseInvoice::query()->create([
                ...$header,
                'status' => PurchaseInvoiceStatus::Draft,
                'paid_total' => PriceMath::normalize(0),
                'created_by' => $userId,
            ]);

            $invoice->update([
                'invoice_number' => SequentialCodeGenerator::next(PurchaseInvoice::class, 'invoice_number', 'PI-'),
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
    public function updateHeader(PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data): PurchaseInvoice {
            $invoice = $this->lockDraft($invoice);
            $supplierId = array_key_exists('supplier_id', $data)
                ? (string) $data['supplier_id']
                : (string) $invoice->supplier_id;
            $supplier = PurchaseInvoiceRules::assertSupplier($supplierId);

            $receiptId = array_key_exists('goods_receipt_id', $data)
                ? ($data['goods_receipt_id'] !== null && $data['goods_receipt_id'] !== '' ? (string) $data['goods_receipt_id'] : null)
                : ($invoice->goods_receipt_id !== null ? (string) $invoice->goods_receipt_id : null);
            $orderId = array_key_exists('purchase_order_id', $data)
                ? ($data['purchase_order_id'] !== null && $data['purchase_order_id'] !== '' ? (string) $data['purchase_order_id'] : null)
                : ($invoice->purchase_order_id !== null ? (string) $invoice->purchase_order_id : null);
            PurchaseInvoiceRules::assertExclusiveSource($receiptId, $orderId);
            $receipt = PurchaseInvoiceRules::assertPostedGoodsReceipt($receiptId, (string) $supplier->id);
            PurchaseInvoiceRules::assertGoodsReceiptAvailable($receipt, (string) $invoice->id);
            $order = $receipt === null
                ? PurchaseInvoiceRules::assertDirectPurchaseOrder($orderId, (string) $supplier->id, (string) $invoice->id)
                : null;

            $warehouseId = $order !== null
                ? (int) $order->warehouse_id
                : ($receipt !== null
                    ? (int) $receipt->warehouse_id
                    : (int) ($data['warehouse_id'] ?? $invoice->warehouse_id));
            PurchaseInvoiceRules::assertWarehouse($warehouseId);

            $merged = array_merge($invoice->only([
                'supplier_id',
                'goods_receipt_id',
                'purchase_order_id',
                'warehouse_id',
                'currency_id',
                'payment_method_id',
                'payment_terms_id',
                'invoice_date',
                'due_on',
                'exchange_rate',
                'reference_2',
                'adjustment',
                'notes',
            ]), $data);

            $header = $this->normalizeHeader($merged, $supplier, $receipt, $order, $invoice);
            $invoice->update($header);
            $this->recalculateTotals($invoice->fresh() ?? $invoice);

            return $this->find($invoice->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(PurchaseInvoice $invoice, array $lines): Collection
    {
        return DB::transaction(function () use ($invoice, $lines): Collection {
            $invoice = $this->lockDraft($invoice);
            $this->replaceLines($invoice, $lines);

            return PurchaseInvoiceLine::query()
                ->where('purchase_invoice_id', $invoice->id)
                ->with(['item', 'itemUom.uom', 'warehouse', 'lot', 'goodsReceiptLine', 'purchaseOrderLine'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(PurchaseInvoice $invoice): void
    {
        PurchaseInvoiceRules::assertDraft($invoice);
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        $invoice->delete();
    }

    public function post(PurchaseInvoice $invoice, ?string $userId): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): PurchaseInvoice {
            $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            PurchaseInvoiceRules::assertPostable($locked);
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

            $this->recalculateTotals($locked);
            $locked->refresh();

            $supplier = PurchaseInvoiceRules::assertSupplier((string) $locked->supplier_id);
            $affectsStock = $locked->goods_receipt_id === null;
            $order = null;
            if ($locked->purchase_order_id !== null) {
                $order = PurchaseInvoiceRules::assertDirectPurchaseOrder(
                    (string) $locked->purchase_order_id,
                    (string) $locked->supplier_id,
                    (string) $locked->id,
                );
            }

            if (bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $this->supplierLedgerService->postEntry(
                    $supplier,
                    (int) $locked->currency_id,
                    '0',
                    (string) $locked->grand_total,
                    LedgerReferenceType::PurchaseInvoice,
                    (string) $locked->id,
                    $locked->invoice_date->toDateString(),
                );
            }

            $lines = PurchaseInvoiceLine::query()
                ->where('purchase_invoice_id', $locked->id)
                ->with(['item'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var array<string, string> $lastPurchasePrices */
            $lastPurchasePrices = [];

            foreach ($lines as $line) {
                if ($affectsStock) {
                    $this->postLineStock($locked, $line, $userId);
                }
                if ($order !== null && $line->purchase_order_line_id) {
                    $poLine = PurchaseOrderLine::query()
                        ->where('purchase_order_id', $order->id)
                        ->whereKey((int) $line->purchase_order_line_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $poLine->update([
                        'received_quantity' => bcadd((string) $poLine->received_quantity, (string) $line->quantity, 6),
                        'received_base_quantity' => bcadd((string) $poLine->received_base_quantity, (string) $line->base_quantity, 6),
                    ]);
                }
                $item = $line->item ?? Item::query()->findOrFail($line->item_id);
                $unitCost = $this->baseUnitCost((string) $line->unit_price, (string) $line->conversion_factor);
                if ($unitCost !== null) {
                    $lastPurchasePrices[(string) $item->id] = $unitCost;
                }
            }

            if ($order !== null) {
                $order->update(['status' => PurchaseOrderStatus::Closed]);
            }

            foreach ($lastPurchasePrices as $itemId => $price) {
                $this->supplierItemService->rememberLastPurchasePrice((string) $supplier->id, $itemId, $price);
            }

            $locked->update([
                'status' => PurchaseInvoiceStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function reverse(PurchaseInvoice $invoice, ?string $userId): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): PurchaseInvoice {
            $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseInvoiceStatus::Posted) {
                abort(422, 'Only a posted purchase invoice can be reversed.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_NOT_POSTED',
                ]);
            }
            if (bccomp((string) $locked->paid_total, '0', 4) > 0) {
                abort(422, 'This invoice has payments and cannot be reversed.', [
                    'X-Error-Code' => 'PURCHASE_INVOICE_HAS_PAYMENTS',
                ]);
            }
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

            if ($locked->goods_receipt_id === null) {
                $this->stockMovementService->reverseReference(PurchaseInvoice::REFERENCE_TYPE, (string) $locked->id, $userId);
                $this->undoDirectPurchaseOrderReceipt($locked);
            }

            if (bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $supplier = PurchaseInvoiceRules::assertSupplier((string) $locked->supplier_id);
                $this->supplierLedgerService->postEntry(
                    $supplier,
                    (int) $locked->currency_id,
                    (string) $locked->grand_total,
                    '0',
                    LedgerReferenceType::PurchaseInvoice,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }

            $locked->update(['status' => PurchaseInvoiceStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    private function undoDirectPurchaseOrderReceipt(PurchaseInvoice $invoice): void
    {
        if ($invoice->purchase_order_id === null) {
            return;
        }

        $lines = PurchaseInvoiceLine::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }
            $poLine = PurchaseOrderLine::query()->whereKey((int) $line->purchase_order_line_id)->lockForUpdate()->first();
            if ($poLine === null) {
                continue;
            }
            $received = bcsub((string) $poLine->received_quantity, (string) $line->quantity, 6);
            $receivedBase = bcsub((string) $poLine->received_base_quantity, (string) $line->base_quantity, 6);
            $poLine->update([
                'received_quantity' => bccomp($received, '0', 6) < 0 ? '0' : $received,
                'received_base_quantity' => bccomp($receivedBase, '0', 6) < 0 ? '0' : $receivedBase,
            ]);
        }

        $order = PurchaseOrder::query()->whereKey($invoice->purchase_order_id)->lockForUpdate()->first();
        if ($order === null || $order->status !== PurchaseOrderStatus::Closed) {
            return;
        }

        $order->setRelation('lines', PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->get());
        if (GoodsReceiptRules::isFullyReceived($order)) {
            return;
        }

        $order->update([
            'status' => $order->sent_at !== null
                ? PurchaseOrderStatus::Sent
                : PurchaseOrderStatus::Confirmed,
        ]);
    }

    private function lockDraft(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        PurchaseInvoiceRules::assertDraft($locked);
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $data, Supplier $supplier, ?GoodsReceipt $receipt, ?PurchaseOrder $order, ?PurchaseInvoice $existing): array
    {
        $currencyId = isset($data['currency_id']) && $data['currency_id'] !== '' && $data['currency_id'] !== null
            ? (int) $data['currency_id']
            : (int) ($existing?->currency_id ?? Currency::getPrimary()?->id);

        if ($currencyId <= 0) {
            abort(422, 'A primary currency is required.', ['X-Error-Code' => 'PURCHASE_INVOICE_PRIMARY_CURRENCY_REQUIRED']);
        }

        $invoiceDate = isset($data['invoice_date']) && $data['invoice_date']
            ? Carbon::parse((string) $data['invoice_date'])->toDateString()
            : ($existing?->invoice_date?->toDateString() ?? now()->toDateString());

        $paymentTermsId = array_key_exists('payment_terms_id', $data)
            ? ($data['payment_terms_id'] !== null && $data['payment_terms_id'] !== '' ? (int) $data['payment_terms_id'] : null)
            : ($existing?->payment_terms_id !== null ? (int) $existing->payment_terms_id : ($supplier->payment_terms_id !== null ? (int) $supplier->payment_terms_id : null));

        $paymentMethodId = array_key_exists('payment_method_id', $data)
            ? ($data['payment_method_id'] !== null && $data['payment_method_id'] !== '' ? (int) $data['payment_method_id'] : null)
            : ($existing?->payment_method_id !== null ? (int) $existing->payment_method_id : ($supplier->payment_method_id !== null ? (int) $supplier->payment_method_id : null));

        $dueOn = isset($data['due_on']) && $data['due_on']
            ? Carbon::parse((string) $data['due_on'])->toDateString()
            : $this->defaultDueOn($invoiceDate, $paymentTermsId);

        $warehouseId = $receipt !== null
            ? (int) $receipt->warehouse_id
            : ($order !== null
                ? (int) $order->warehouse_id
                : (int) ($data['warehouse_id'] ?? $existing?->warehouse_id));

        return [
            'supplier_id' => (string) $supplier->id,
            'goods_receipt_id' => $receipt?->id,
            'purchase_order_id' => $order?->id,
            'warehouse_id' => $warehouseId,
            'currency_id' => $currencyId,
            'payment_method_id' => $paymentMethodId,
            'payment_terms_id' => $paymentTermsId,
            'invoice_date' => $invoiceDate,
            'due_on' => $dueOn,
            'exchange_rate' => $this->resolveExchangeRate($currencyId, $data['exchange_rate'] ?? $existing?->exchange_rate),
            'reference_2' => $this->nullableString($data['reference_2'] ?? $existing?->reference_2),
            'adjustment' => array_key_exists('adjustment', $data)
                ? PriceMath::normalize($data['adjustment'] ?? 0)
                : ($existing !== null ? (string) $existing->adjustment : PriceMath::normalize(0)),
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

    private function resolveExchangeRate(int $currencyId, mixed $provided): string
    {
        $primary = Currency::getPrimary();
        if ($primary === null) {
            abort(422, 'A primary currency is required.', ['X-Error-Code' => 'PURCHASE_INVOICE_PRIMARY_CURRENCY_REQUIRED']);
        }

        if ($currencyId === (int) $primary->id) {
            return number_format(1, 6, '.', '');
        }

        if ($provided !== null && $provided !== '') {
            $rate = (float) $provided;
            if ($rate <= 0) {
                abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => 'PURCHASE_INVOICE_EXCHANGE_RATE_INVALID']);
            }

            return number_format($rate, 6, '.', '');
        }

        try {
            $rate = $this->exchangeRateService->getRateById($currencyId, (int) $primary->id);
        } catch (\InvalidArgumentException) {
            abort(422, 'Enter an exchange rate for this currency.', ['X-Error-Code' => 'PURCHASE_INVOICE_EXCHANGE_RATE_REQUIRED']);
        }

        if ($rate <= 0) {
            abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => 'PURCHASE_INVOICE_EXCHANGE_RATE_INVALID']);
        }

        return number_format($rate, 6, '.', '');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(PurchaseInvoice $invoice, array $lines): void
    {
        $invoice->loadMissing('supplier');
        $supplier = $invoice->supplier;
        $date = $invoice->invoice_date->toDateString();
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $receipt = $invoice->goods_receipt_id
            ? GoodsReceipt::query()->with('lines')->find($invoice->goods_receipt_id)
            : null;
        $order = $invoice->purchase_order_id
            ? PurchaseOrder::query()->find($invoice->purchase_order_id)
            : null;
        $exempt = $this->supplierIsExempt($supplier, $date);

        PurchaseInvoiceLine::query()->where('purchase_invoice_id', $invoice->id)->delete();

        foreach (array_values($lines) as $index => $row) {
            $item = Item::query()->findOrFail((string) $row['item_id']);
            PurchaseInvoiceRules::assertPurchasableItem($item);

            $grnLine = null;
            $poLine = null;
            if (! empty($row['goods_receipt_line_id'])) {
                if ($receipt === null) {
                    abort(422, 'Line is linked to a goods receipt but the invoice is not.', ['X-Error-Code' => 'PURCHASE_INVOICE_LINE_GRN_UNEXPECTED']);
                }
                $grnLine = GoodsReceiptLine::query()
                    ->where('goods_receipt_id', $receipt->id)
                    ->whereKey((int) $row['goods_receipt_line_id'])
                    ->first();
                if ($grnLine === null || (string) $grnLine->item_id !== (string) $item->id) {
                    abort(422, 'Goods receipt line does not match this item.', ['X-Error-Code' => 'PURCHASE_INVOICE_GRN_LINE_MISMATCH']);
                }
            }

            if (! empty($row['purchase_order_line_id'])) {
                if ($order === null) {
                    abort(422, 'Line is linked to a purchase order but the invoice is not.', ['X-Error-Code' => 'PURCHASE_INVOICE_LINE_PO_UNEXPECTED']);
                }
                $poLine = PurchaseOrderLine::query()
                    ->where('purchase_order_id', $order->id)
                    ->whereKey((int) $row['purchase_order_line_id'])
                    ->first();
                if ($poLine === null || (string) $poLine->item_id !== (string) $item->id) {
                    abort(422, 'Purchase order line does not match this item.', ['X-Error-Code' => 'PURCHASE_INVOICE_PO_LINE_MISMATCH']);
                }
            }

            $linkedUomId = $grnLine?->item_uom_id ?? $poLine?->item_uom_id;
            $qty = PurchaseInvoiceLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                isset($row['item_uom_id']) && $row['item_uom_id'] !== null && $row['item_uom_id'] !== ''
                    ? (int) $row['item_uom_id']
                    : ($linkedUomId !== null ? (int) $linkedUomId : null),
            );

            if ($grnLine !== null) {
                $warehouseId = (int) $receipt->warehouse_id;
                $lotId = $grnLine->lot_id !== null ? (int) $grnLine->lot_id : null;
            } elseif ($poLine !== null) {
                $warehouseId = (int) $order->warehouse_id;
                $lot = $this->inventoryLotService->resolve(
                    $item,
                    isset($row['lot_id']) && $row['lot_id'] !== '' && $row['lot_id'] !== null ? (int) $row['lot_id'] : null,
                    isset($row['lot_number']) ? (string) $row['lot_number'] : null,
                    isset($row['expiry_date']) ? (string) $row['expiry_date'] : null,
                    inbound: true,
                );
                $lotId = $lot?->id;
            } else {
                $warehouseId = isset($row['warehouse_id']) && $row['warehouse_id'] !== null && $row['warehouse_id'] !== ''
                    ? (int) $row['warehouse_id']
                    : ((bool) $item->track_inventory ? (int) $invoice->warehouse_id : null);
                $lot = $this->inventoryLotService->resolve(
                    $item,
                    isset($row['lot_id']) && $row['lot_id'] !== '' && $row['lot_id'] !== null ? (int) $row['lot_id'] : null,
                    isset($row['lot_number']) ? (string) $row['lot_number'] : null,
                    isset($row['expiry_date']) ? (string) $row['expiry_date'] : null,
                    inbound: true,
                );
                $lotId = $lot?->id;
            }

            if ($item->track_inventory) {
                if ($warehouseId === null) {
                    abort(422, 'Warehouse is required for stock items.', ['X-Error-Code' => 'PURCHASE_INVOICE_LINE_WAREHOUSE_REQUIRED']);
                }
                PurchaseInvoiceRules::assertWarehouse($warehouseId);
            }

            if ($item->track_lots && $lotId === null && $invoice->goods_receipt_id === null) {
                abort(422, 'Select a lot for this item.', ['X-Error-Code' => 'STOCK_LOT_REQUIRED']);
            }

            $unitPrice = PriceMath::normalize($row['unit_price'] ?? 0);
            $discountPercent = (string) ($row['discount_percent'] ?? 0);
            $taxRate = $exempt ? '0.0000' : DocumentTaxContext::ratePercentForItem($item, null, $date);
            $math = DocumentTaxMath::line($qty['quantity'], $unitPrice, $discountPercent, $taxRate, $includeTax);

            PurchaseInvoiceLine::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'goods_receipt_line_id' => $grnLine?->id,
                'purchase_order_line_id' => $poLine?->id,
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

    private function recalculateTotals(PurchaseInvoice $invoice): void
    {
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $rows = PurchaseInvoiceLine::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $linePayload = [];
        foreach ($rows as $line) {
            $linePayload[] = [
                'merchandise' => bcmul((string) $line->quantity, (string) $line->unit_price, 8),
                'discount_amount' => (string) $line->discount_amount,
                'tax_amount' => (string) $line->tax_amount,
            ];
        }

        $totals = DocumentTaxMath::sumTotals($linePayload, $invoice->adjustment, $includeTax);

        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
            'paid_total' => PriceMath::normalize($invoice->paid_total ?? 0),
            'net_to_pay' => $totals['net_to_pay'],
        ]);
    }

    private function postLineStock(PurchaseInvoice $invoice, PurchaseInvoiceLine $line, ?string $userId): void
    {
        $item = $line->item ?? Item::query()->findOrFail($line->item_id);
        if (! $item->track_inventory) {
            return;
        }

        $note = 'PI '.$invoice->invoice_number;
        $this->stockMovementService->post(StockMovementData::forPurchaseInvoice(
            itemId: (string) $line->item_id,
            warehouseId: (int) ($line->warehouse_id ?? $invoice->warehouse_id),
            quantityDelta: (string) $line->base_quantity,
            purchaseInvoiceId: (string) $invoice->id,
            unitCost: $this->baseUnitCost((string) $line->unit_price, (string) $line->conversion_factor),
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: $line->notes ? $note.' — '.$line->notes : $note,
            userId: $userId,
            lotId: $line->lot_id ? (int) $line->lot_id : null,
        ));
    }

    private function supplierIsExempt(?Supplier $supplier, string $documentDate): bool
    {
        if ($supplier === null || ! $supplier->is_exempted) {
            return false;
        }

        $date = Carbon::parse($documentDate)->startOfDay();
        if ($supplier->exempted_from !== null && $date->lt(Carbon::parse($supplier->exempted_from)->startOfDay())) {
            return false;
        }
        if ($supplier->exempted_to !== null && $date->gt(Carbon::parse($supplier->exempted_to)->startOfDay())) {
            return false;
        }

        return true;
    }

    private function baseUnitCost(string $unitPrice, string $conversionFactor): ?string
    {
        if (bccomp($conversionFactor, '0', 6) <= 0) {
            return null;
        }

        return bcdiv($unitPrice, $conversionFactor, 4);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
