<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderLineQuantity;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderMaxStockGuard;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderRules;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use App\Support\ExchangeRateSnapshot;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderQueryService $purchaseOrderQueryService,
        private readonly WarehouseService $warehouseService,
        private readonly DomainNotificationPublisher $notifications,
        private readonly PurchaseOrderMaxStockGuard $maxStockGuard,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   supplier_id?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->purchaseOrderQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): PurchaseOrder
    {
        $order = PurchaseOrder::query()
            ->with([
                'supplier',
                'warehouse',
                'currency',
                'branch',
                'createdByUser',
                'confirmedByUser',
                'sentByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
            ])
            ->findOrFail($id);

        if ($order->warehouse !== null) {
            $this->warehouseService->assertVisible($order->warehouse);
        } else {
            $this->warehouseService->assertVisibleById((int) $order->warehouse_id);
        }

        return $order;
    }

    /**
     * @param  array{
     *   supplier_id:string,
     *   warehouse_id:int,
     *   currency_id?:?int,
     *   exchange_rate?:?numeric,
     *   order_date?:string,
     *   expected_date?:?string,
     *   notes?:?string,
     *   lines?:list<array{
     *     item_id:string,
     *     quantity:numeric,
     *     item_uom_id?:?int,
     *     unit_price?:?numeric,
     *     discount_percent?:?numeric,
     *     notes?:?string
     *   }>
     * }  $data
     */
    public function create(array $data, ?string $userId): PurchaseOrder
    {
        PurchaseOrderRules::assertSupplier((string) $data['supplier_id']);
        PurchaseOrderRules::assertWarehouse((int) $data['warehouse_id']);

        return DB::transaction(function () use ($data, $userId): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                'supplier_id' => (string) $data['supplier_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                ...$this->resolveCurrency($data, null),
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $this->normalizeDate($data['expected_date'] ?? null),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
                'po_number' => SequentialCodeGenerator::next(PurchaseOrder::class, 'po_number', 'PO-'),
            ]);

            if (! empty($data['lines'])) {
                $this->replaceLines($order, $data['lines']);
            }

            return $this->find($order->id);
        });
    }

    /**
     * @param  array{
     *   supplier_id?:string,
     *   warehouse_id?:int,
     *   currency_id?:?int,
     *   exchange_rate?:?numeric,
     *   order_date?:string,
     *   expected_date?:?string,
     *   notes?:?string
     * }  $data
     */
    public function updateHeader(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $order = $this->lockDraftOrder($order);

            if (array_key_exists('supplier_id', $data)) {
                PurchaseOrderRules::assertSupplier((string) $data['supplier_id']);
            }

            if (array_key_exists('warehouse_id', $data)) {
                PurchaseOrderRules::assertWarehouse((int) $data['warehouse_id']);
            }

            $nextWarehouseId = array_key_exists('warehouse_id', $data)
                ? (int) $data['warehouse_id']
                : (int) $order->warehouse_id;
            $warehouseChanged = $nextWarehouseId !== (int) $order->warehouse_id;

            $order->update([
                'supplier_id' => array_key_exists('supplier_id', $data)
                    ? (string) $data['supplier_id']
                    : $order->supplier_id,
                'warehouse_id' => $nextWarehouseId,
                ...$this->resolveCurrency($data, $order),
                'order_date' => array_key_exists('order_date', $data)
                    ? (string) $data['order_date']
                    : $order->order_date,
                'expected_date' => array_key_exists('expected_date', $data)
                    ? $this->normalizeDate($data['expected_date'])
                    : $order->expected_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $order->notes,
            ]);

            if ($warehouseChanged) {
                $this->maxStockGuard->assertOrder($order);
            }

            // Supplier exemption and the order date drive the tax on each line.
            $this->recalculateLines($order);

            return $this->find($order->id);
        });
    }

    /**
     * @param  list<array{
     *   item_id:string,
     *   quantity:numeric,
     *   item_uom_id?:?int,
     *   unit_price?:?numeric,
     *   discount_percent?:?numeric,
     *   notes?:?string
     * }>  $lines
     */
    public function syncLines(PurchaseOrder $order, array $lines): Collection
    {
        return DB::transaction(function () use ($order, $lines): Collection {
            $order = $this->lockDraftOrder($order);
            $this->replaceLines($order, $lines);

            return PurchaseOrderLine::query()
                ->where('purchase_order_id', $order->id)
                ->with(['item', 'itemUom.uom'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = $this->lockDraftOrder($order);
            $order->delete();
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        $cancelled = DB::transaction(function () use ($order): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertOrderWarehouseVisible($locked);
            PurchaseOrderRules::assertCancellable($locked);
            $locked->update(['status' => PurchaseOrderStatus::Cancelled]);

            return $this->find($locked->id);
        });

        $actorId = auth()->id() ? (string) auth()->id() : null;
        $this->notifications->purchaseOrderCancelled($cancelled, $actorId);

        return $cancelled;
    }

    public function confirm(PurchaseOrder $order, ?string $userId): PurchaseOrder
    {
        $confirmed = DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $order = $this->lockDraftOrder($order);

            $lineCount = PurchaseOrderLine::query()
                ->where('purchase_order_id', $order->id)
                ->count();

            if ($lineCount === 0) {
                abort(422, 'Cannot confirm a purchase order without lines.', ['X-Error-Code' => 'PURCHASE_ORDER_NO_LINES']);
            }

            $this->maxStockGuard->assertOrder($order);

            $order->update([
                'status' => PurchaseOrderStatus::Confirmed,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return $this->find($order->id);
        });

        $this->notifications->purchaseOrderConfirmed($confirmed, $userId);

        return $confirmed;
    }

    public function markAsSent(PurchaseOrder $order, ?string $userId): PurchaseOrder
    {
        $sent = DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertOrderWarehouseVisible($locked);
            PurchaseOrderRules::assertMarkAsSent($locked);

            $locked->update([
                'status' => PurchaseOrderStatus::Sent,
                'sent_at' => now(),
                'sent_by' => $userId,
            ]);

            return $this->find($locked->id);
        });

        $this->notifications->purchaseOrderSent($sent, $userId);

        return $sent;
    }

    /**
     * @param  list<array{
     *   item_id:string,
     *   quantity:numeric,
     *   item_uom_id?:?int,
     *   unit_price?:?numeric,
     *   discount_percent?:?numeric,
     *   notes?:?string
     * }>  $lines
     */
    private function replaceLines(PurchaseOrder $order, array $lines): void
    {
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = (string) $row['item_id'];
            if (isset($normalized[$itemId])) {
                abort(422, 'Duplicate items are not allowed on a purchase order.', ['X-Error-Code' => 'PURCHASE_ORDER_DUPLICATE_ITEM']);
            }

            $item = Item::query()->findOrFail($itemId);
            PurchaseOrderRules::assertPurchasableItem($item);

            $resolved = PurchaseOrderLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                isset($row['item_uom_id']) ? (int) $row['item_uom_id'] : null
            );

            $normalized[$itemId] = [
                ...$resolved,
                'item' => $item,
                'unit_price' => $this->normalizeUnitPrice($row['unit_price'] ?? null),
                'discount_percent' => PriceMath::normalize($row['discount_percent'] ?? 0),
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        $baseQtyByItem = [];
        foreach ($normalized as $itemId => $line) {
            $baseQtyByItem[(string) $itemId] = (string) $line['base_quantity'];
        }
        $this->maxStockGuard->assertWarehouseLines((int) $order->warehouse_id, $baseQtyByItem);

        PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->delete();

        $taxContext = $this->taxContext($order);
        foreach ($normalized as $itemId => $line) {
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $order->id,
                'item_id' => $itemId,
                'quantity' => $line['quantity'],
                'base_quantity' => $line['base_quantity'],
                'received_quantity' => 0,
                'received_base_quantity' => 0,
                'item_uom_id' => $line['item_uom_id'],
                'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'],
                ...$this->lineMoney($line['item'], $line['quantity'], $line['unit_price'], $line['discount_percent'], $taxContext),
                'notes' => $line['notes'],
            ]);
        }

        $this->recalculateTotals($order);
    }

    /**
     * Recompute every line's discount and tax with the current supplier exemption and order date.
     */
    private function recalculateLines(PurchaseOrder $order): void
    {
        $taxContext = $this->taxContext($order);
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->with('item')->get();
        foreach ($lines as $line) {
            $item = $line->item ?? Item::query()->findOrFail($line->item_id);
            $line->update($this->lineMoney(
                $item,
                (string) $line->quantity,
                $line->unit_price !== null ? (string) $line->unit_price : null,
                (string) $line->discount_percent,
                $taxContext,
            ));
        }

        $this->recalculateTotals($order);
    }

    /**
     * Totals are stored on the order (never recomputed at print or list time), docs/printing-plan.md D2.
     */
    private function recalculateTotals(PurchaseOrder $order): void
    {
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $payload = [];
        foreach (PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->get() as $line) {
            $payload[] = [
                'merchandise' => bcmul((string) $line->quantity, (string) ($line->unit_price ?? '0'), 8),
                'discount_amount' => (string) $line->discount_amount,
                'tax_amount' => (string) $line->tax_amount,
            ];
        }

        $totals = DocumentTaxMath::sumTotals($payload, 0, $includeTax);
        $order->update([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ]);
    }

    /**
     * @return array{exempt:bool,date:string,include_tax:bool}
     */
    private function taxContext(PurchaseOrder $order): array
    {
        $order->loadMissing('supplier');
        $date = $order->order_date?->toDateString() ?? now()->toDateString();
        $supplier = $order->supplier instanceof Supplier ? $order->supplier : null;

        return [
            'exempt' => DocumentTaxContext::supplierIsExemptOnDate($supplier, $date),
            'date' => $date,
            'include_tax' => DocumentTaxContext::pricesIncludeTax(),
        ];
    }

    /**
     * Same line math as the purchase invoice, so a PO converts to an invoice with identical amounts.
     *
     * @param  array{exempt:bool,date:string,include_tax:bool}  $context
     * @return array{discount_amount:string,tax_rate:string,line_subtotal:string,tax_amount:string,line_total:string}
     */
    private function lineMoney(Item $item, string $quantity, ?string $unitPrice, string $discountPercent, array $context): array
    {
        $taxRate = $context['exempt'] ? '0.0000' : DocumentTaxContext::ratePercentForItem($item, null, $context['date']);
        $math = DocumentTaxMath::line($quantity, $unitPrice ?? '0', $discountPercent, $taxRate, $context['include_tax']);

        return [
            'discount_amount' => $math['discount_amount'],
            'tax_rate' => $taxRate,
            'line_subtotal' => $math['line_subtotal'],
            'tax_amount' => $math['tax_amount'],
            'line_total' => $math['line_total'],
        ];
    }

    /**
     * Currency defaults to the primary currency; the rate snapshot follows ExchangeRateSnapshot like every document.
     *
     * @param  array<string, mixed>  $data
     * @return array{currency_id?:int,exchange_rate?:string}
     */
    private function resolveCurrency(array $data, ?PurchaseOrder $existing): array
    {
        $currencyGiven = array_key_exists('currency_id', $data) && $data['currency_id'] !== null && $data['currency_id'] !== '';
        $rateGiven = array_key_exists('exchange_rate', $data) && $data['exchange_rate'] !== null && $data['exchange_rate'] !== '';
        if ($existing !== null && ! $currencyGiven && ! $rateGiven) {
            return [];
        }

        $currencyId = $currencyGiven
            ? (int) $data['currency_id']
            : ($existing !== null ? (int) $existing->currency_id : (int) (Currency::getPrimary()?->id ?? 0));

        $keepRate = $existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null;

        return [
            'currency_id' => $currencyId,
            'exchange_rate' => ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                $currencyId,
                $rateGiven ? $data['exchange_rate'] : $keepRate,
                'PURCHASE_ORDER',
            ),
        ];
    }

    private function lockDraftOrder(PurchaseOrder $order): PurchaseOrder
    {
        $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->assertOrderWarehouseVisible($locked);
        PurchaseOrderRules::assertDraft($locked);

        return $locked;
    }

    private function assertOrderWarehouseVisible(PurchaseOrder $order): void
    {
        $this->warehouseService->assertVisibleById((int) $order->warehouse_id);
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function normalizeUnitPrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $price = PriceMath::normalize($value);

        if (bccomp($price, '0', PriceMath::scale()) < 0) {
            abort(422, 'Unit price cannot be negative.', ['X-Error-Code' => 'PURCHASE_ORDER_LINE_INVALID_UNIT_PRICE']);
        }

        return $price;
    }
}
