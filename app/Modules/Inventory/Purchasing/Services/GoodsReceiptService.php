<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Purchasing\Support\GoodsReceiptRules;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderLineQuantity;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderRules;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Services\InventoryLotService;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\Stock\Support\StockAdjustmentQuantity;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Modules\Supplier\Services\SupplierItemService;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GoodsReceiptService
{
    public function __construct(
        private readonly GoodsReceiptQueryService $goodsReceiptQueryService,
        private readonly WarehouseService $warehouseService,
        private readonly StockMovementService $stockMovementService,
        private readonly InventoryLotService $inventoryLotService,
        private readonly SupplierItemService $supplierItemService,
        private readonly DomainNotificationPublisher $notifications,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   purchase_order_id?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, GoodsReceipt>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->goodsReceiptQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()
            ->with([
                'purchaseOrder.supplier',
                'supplier',
                'warehouse',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
                'lines.lot',
                'lines.purchaseOrderLine',
            ])
            ->findOrFail($id);

        $this->warehouseService->assertVisibleById((int) $receipt->warehouse_id);

        return $receipt;
    }

    /**
     * @param  array{
     *   purchase_order_id?:?string,
     *   warehouse_id?:int,
     *   supplier_id?:?string,
     *   received_date?:string,
     *   notes?:?string,
     *   lines?:list<array<string, mixed>>
     * }  $data
     */
    public function create(array $data, ?string $userId): GoodsReceipt
    {
        $purchaseOrderId = isset($data['purchase_order_id']) && $data['purchase_order_id'] !== ''
            ? (string) $data['purchase_order_id']
            : null;

        if ($purchaseOrderId !== null) {
            return $this->createAgainstPurchaseOrder($data, $purchaseOrderId, $userId);
        }

        return $this->createDirect($data, $userId);
    }

    /**
     * @param  array{warehouse_id?:int, supplier_id?:?string, received_date?:string, notes?:?string}  $data
     */
    public function updateHeader(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data): GoodsReceipt {
            $receipt = $this->lockDraft($receipt);

            $updates = [
                'received_date' => array_key_exists('received_date', $data)
                    ? (string) $data['received_date']
                    : $receipt->received_date,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $receipt->notes,
            ];

            if ($receipt->purchase_order_id === null) {
                if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null) {
                    $warehouseId = (int) $data['warehouse_id'];
                    PurchaseOrderRules::assertWarehouse($warehouseId);
                    $updates['warehouse_id'] = $warehouseId;
                }

                if (array_key_exists('supplier_id', $data)) {
                    $supplierId = $data['supplier_id'] !== null && $data['supplier_id'] !== ''
                        ? (string) $data['supplier_id']
                        : null;
                    if ($supplierId !== null) {
                        PurchaseOrderRules::assertSupplier($supplierId);
                    }
                    $updates['supplier_id'] = $supplierId;
                }
            }

            $receipt->update($updates);

            return $this->find($receipt->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(GoodsReceipt $receipt, array $lines): Collection
    {
        return DB::transaction(function () use ($receipt, $lines): Collection {
            $receipt = $this->lockDraft($receipt);
            $this->replaceLines($receipt, $lines);

            return GoodsReceiptLine::query()
                ->where('goods_receipt_id', $receipt->id)
                ->with(['item', 'itemUom.uom', 'lot', 'purchaseOrderLine'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(GoodsReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $receipt = $this->lockDraft($receipt);
            $receipt->delete();
        });
    }

    public function post(GoodsReceipt $receipt, ?string $userId): GoodsReceipt
    {
        $posted = DB::transaction(function () use ($receipt, $userId): GoodsReceipt {
            $locked = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
            GoodsReceiptRules::assertPostable($locked);
            PurchaseOrderRules::assertWarehouse((int) $locked->warehouse_id);

            $order = null;
            if ($locked->purchase_order_id !== null) {
                $order = PurchaseOrder::query()->whereKey($locked->purchase_order_id)->lockForUpdate()->firstOrFail();
                GoodsReceiptRules::assertReceivablePurchaseOrder($order);
            }

            $lines = GoodsReceiptLine::query()
                ->where('goods_receipt_id', $locked->id)
                ->with(['item', 'purchaseOrderLine'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($order !== null) {
                $this->assertQuantitiesWithinOpen($lines);
            }

            $referenceNote = $order
                ? 'GRN '.$locked->grn_number.' — PO '.$order->po_number
                : 'GRN '.$locked->grn_number;
            /** @var array<string, string> $lastPurchasePrices */
            $lastPurchasePrices = [];

            foreach ($lines as $line) {
                $poLine = null;
                if ($line->purchase_order_line_id) {
                    $poLine = PurchaseOrderLine::query()
                        ->whereKey($line->purchase_order_line_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $item = $line->item ?? Item::query()->findOrFail($line->item_id);
                $itemUomId = $line->item_uom_id ? (int) $line->item_uom_id : null;
                $unitCost = $this->resolveBaseUnitCost($item, $line, $poLine, $itemUomId);
                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;

                if ($item->track_lots && ! $line->lot_id) {
                    abort(422, 'Enter a lot number when adding stock for a lot-tracked item.', [
                        'X-Error-Code' => 'STOCK_LOT_REQUIRED',
                    ]);
                }

                if ($item->track_inventory) {
                    $this->stockMovementService->post(StockMovementData::forPurchase(
                        itemId: (string) $line->item_id,
                        warehouseId: (int) $locked->warehouse_id,
                        quantityDelta: (string) $line->base_quantity,
                        purchaseOrderId: $order?->id,
                        unitCost: $unitCost,
                        itemUomId: $itemUomId,
                        notes: $lineNote,
                        userId: $userId,
                        lotId: $line->lot_id ? (int) $line->lot_id : null,
                        goodsReceiptId: (string) $locked->id,
                    ));
                }

                if ($poLine !== null) {
                    $poLine->update([
                        'received_quantity' => bcadd((string) $poLine->received_quantity, (string) $line->quantity, 6),
                        'received_base_quantity' => bcadd((string) $poLine->received_base_quantity, (string) $line->base_quantity, 6),
                    ]);
                }

                if ($unitCost !== null && $locked->supplier_id) {
                    $lastPurchasePrices[(string) $line->item_id] = $unitCost;
                }
            }

            if ($order !== null) {
                $this->closePurchaseOrderIfFullyReceived($order);
            }

            foreach ($lastPurchasePrices as $itemId => $price) {
                $this->supplierItemService->rememberLastPurchasePrice(
                    (string) $locked->supplier_id,
                    $itemId,
                    $price
                );
            }

            $locked->update([
                'status' => GoodsReceiptStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });

        $this->notifications->goodsReceiptPosted($posted, $userId);

        $order = $posted->purchaseOrder;
        if ($order !== null && $order->status === PurchaseOrderStatus::Closed) {
            $this->notifications->purchaseOrderClosed($order, $userId);
        }

        return $posted;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAgainstPurchaseOrder(array $data, string $purchaseOrderId, ?string $userId): GoodsReceipt
    {
        $order = PurchaseOrder::query()
            ->with(['lines.item'])
            ->findOrFail($purchaseOrderId);

        GoodsReceiptRules::assertReceivablePurchaseOrder($order);
        $this->warehouseService->assertVisibleById((int) $order->warehouse_id);

        return DB::transaction(function () use ($data, $order, $userId): GoodsReceipt {
            $receipt = GoodsReceipt::query()->create([
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => (int) $order->warehouse_id,
                'status' => GoodsReceiptStatus::Draft,
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $receipt->update(['grn_number' => $this->formatGrnNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($receipt, $data['lines']);
            } else {
                $this->seedLinesFromPurchaseOrder($receipt, $order);
            }

            return $this->find($receipt->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createDirect(array $data, ?string $userId): GoodsReceipt
    {
        if (! isset($data['warehouse_id']) || $data['warehouse_id'] === null || $data['warehouse_id'] === '') {
            abort(422, 'Select a warehouse for a goods receipt without a purchase order.', [
                'X-Error-Code' => 'GOODS_RECEIPT_WAREHOUSE_REQUIRED',
            ]);
        }

        $warehouseId = (int) $data['warehouse_id'];
        PurchaseOrderRules::assertWarehouse($warehouseId);

        $supplierId = isset($data['supplier_id']) && $data['supplier_id'] !== ''
            ? (string) $data['supplier_id']
            : null;
        if ($supplierId !== null) {
            PurchaseOrderRules::assertSupplier($supplierId);
        }

        return DB::transaction(function () use ($data, $warehouseId, $supplierId, $userId): GoodsReceipt {
            $receipt = GoodsReceipt::query()->create([
                'purchase_order_id' => null,
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'status' => GoodsReceiptStatus::Draft,
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $receipt->update(['grn_number' => $this->formatGrnNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($receipt, $data['lines']);
            }

            return $this->find($receipt->id);
        });
    }

    private function seedLinesFromPurchaseOrder(GoodsReceipt $receipt, PurchaseOrder $order): void
    {
        $seeded = [];

        foreach ($order->lines as $poLine) {
            $open = GoodsReceiptRules::openQuantity($poLine);
            if (bccomp($open, '0', 6) <= 0) {
                continue;
            }

            $seeded[] = [
                'purchase_order_line_id' => (int) $poLine->id,
                'quantity' => (float) $open,
                'item_uom_id' => $poLine->item_uom_id ? (int) $poLine->item_uom_id : null,
                'unit_cost' => $poLine->unit_price,
            ];
        }

        if ($seeded === []) {
            abort(422, 'This purchase order has no remaining quantity to receive.', [
                'X-Error-Code' => 'GOODS_RECEIPT_PO_FULLY_RECEIVED',
            ]);
        }

        $this->replaceLines($receipt, $seeded);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(GoodsReceipt $receipt, array $lines): void
    {
        if ($receipt->purchase_order_id !== null) {
            $this->replacePurchaseOrderLines($receipt, $lines);

            return;
        }

        $this->replaceDirectLines($receipt, $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replacePurchaseOrderLines(GoodsReceipt $receipt, array $lines): void
    {
        $order = PurchaseOrder::query()->with('lines')->findOrFail($receipt->purchase_order_id);
        $poLines = $order->lines->keyBy('id');
        $normalized = [];
        $qtyByPoLine = [];

        foreach ($lines as $row) {
            if (! isset($row['purchase_order_line_id']) || $row['purchase_order_line_id'] === null || $row['purchase_order_line_id'] === '') {
                abort(422, 'Purchase order line does not belong to this order.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_PO_LINE_MISMATCH',
                ]);
            }

            $poLineId = (int) $row['purchase_order_line_id'];
            $poLine = $poLines->get($poLineId);
            if ($poLine === null || $poLine->purchase_order_id !== $order->id) {
                abort(422, 'Purchase order line does not belong to this order.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_PO_LINE_MISMATCH',
                ]);
            }

            $item = Item::query()->findOrFail($poLine->item_id);
            $itemUomId = array_key_exists('item_uom_id', $row)
                ? (isset($row['item_uom_id']) ? (int) $row['item_uom_id'] : null)
                : ($poLine->item_uom_id ? (int) $poLine->item_uom_id : null);

            $resolved = PurchaseOrderLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                $itemUomId,
            );
            $lotId = $this->resolveLineLotId($item, $row);
            $lineKey = $poLineId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate purchase order line and lot combinations are not allowed.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_DUPLICATE_LINE',
                ]);
            }

            $qtyByPoLine[$poLineId] = bcadd($qtyByPoLine[$poLineId] ?? '0', $resolved['quantity'], 6);
            $open = GoodsReceiptRules::openQuantity($poLine);
            if (bccomp($qtyByPoLine[$poLineId], $open, 6) > 0) {
                abort(422, 'Received quantity exceeds the open purchase order quantity.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_QTY_EXCEEDS_OPEN',
                ]);
            }

            $normalized[$lineKey] = [
                ...$resolved,
                'purchase_order_line_id' => $poLineId,
                'item_id' => (string) $poLine->item_id,
                'lot_id' => $lotId,
                'unit_cost' => $this->normalizeUnitCost($row['unit_cost'] ?? $poLine->unit_price),
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        $this->persistLines($receipt, $normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceDirectLines(GoodsReceipt $receipt, array $lines): void
    {
        $normalized = [];

        foreach ($lines as $row) {
            if (isset($row['purchase_order_line_id']) && $row['purchase_order_line_id'] !== null && $row['purchase_order_line_id'] !== '') {
                abort(422, 'Purchase order line does not belong to this order.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_PO_LINE_MISMATCH',
                ]);
            }

            $itemId = isset($row['item_id']) ? (string) $row['item_id'] : '';
            if ($itemId === '') {
                abort(422, 'Select an item for each goods receipt line.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_ITEM_REQUIRED',
                ]);
            }

            $item = Item::query()->findOrFail($itemId);
            PurchaseOrderRules::assertPurchasableItem($item);

            $itemUomId = isset($row['item_uom_id']) && $row['item_uom_id'] !== null && $row['item_uom_id'] !== ''
                ? (int) $row['item_uom_id']
                : null;

            $resolved = PurchaseOrderLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                $itemUomId,
            );
            $lotId = $this->resolveLineLotId($item, $row);
            $lineKey = $itemId.'|'.($lotId ?? '');
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_DUPLICATE_LINE',
                ]);
            }

            $normalized[$lineKey] = [
                ...$resolved,
                'purchase_order_line_id' => null,
                'item_id' => $itemId,
                'lot_id' => $lotId,
                'unit_cost' => $this->normalizeUnitCost($row['unit_cost'] ?? null),
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        $this->persistLines($receipt, $normalized);
    }

    /**
     * @param  array<string, array<string, mixed>>  $normalized
     */
    private function persistLines(GoodsReceipt $receipt, array $normalized): void
    {
        GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->delete();

        foreach ($normalized as $line) {
            GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $receipt->id,
                'purchase_order_line_id' => $line['purchase_order_line_id'],
                'item_id' => $line['item_id'],
                'quantity' => $line['quantity'],
                'base_quantity' => $line['base_quantity'],
                'item_uom_id' => $line['item_uom_id'],
                'unit_cost' => $line['unit_cost'],
                'lot_id' => $line['lot_id'],
                'notes' => $line['notes'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveLineLotId(Item $item, array $row): ?int
    {
        $lotIdInput = isset($row['lot_id']) && $row['lot_id'] !== '' && $row['lot_id'] !== null
            ? (int) $row['lot_id']
            : null;
        if ($lotIdInput === 0) {
            $lotIdInput = null;
        }
        $lotNumberInput = isset($row['lot_number']) ? (string) $row['lot_number'] : null;
        $hasLotInput = $lotIdInput !== null
            || InventoryLotService::normalizeLotNumber($lotNumberInput) !== null;

        if (! $hasLotInput) {
            return null;
        }

        $lot = $this->inventoryLotService->resolve(
            $item,
            $lotIdInput,
            $lotNumberInput,
            isset($row['expiry_date']) ? (string) $row['expiry_date'] : null,
            inbound: true,
        );

        return $lot?->id;
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $lines
     */
    private function assertQuantitiesWithinOpen(Collection $lines): void
    {
        $qtyByPoLine = [];
        foreach ($lines as $line) {
            if (! $line->purchase_order_line_id) {
                continue;
            }
            $poLineId = (int) $line->purchase_order_line_id;
            $qtyByPoLine[$poLineId] = bcadd($qtyByPoLine[$poLineId] ?? '0', (string) $line->quantity, 6);
        }

        foreach ($qtyByPoLine as $poLineId => $qty) {
            $poLine = PurchaseOrderLine::query()->whereKey($poLineId)->lockForUpdate()->firstOrFail();
            $open = GoodsReceiptRules::openQuantity($poLine);
            if (bccomp((string) $qty, $open, 6) > 0) {
                abort(422, 'Received quantity exceeds the open purchase order quantity.', [
                    'X-Error-Code' => 'GOODS_RECEIPT_QTY_EXCEEDS_OPEN',
                ]);
            }
        }
    }

    private function closePurchaseOrderIfFullyReceived(PurchaseOrder $order): void
    {
        $lines = PurchaseOrderLine::query()
            ->where('purchase_order_id', $order->id)
            ->lockForUpdate()
            ->get();

        $order->setRelation('lines', $lines);

        if (! GoodsReceiptRules::isFullyReceived($order)) {
            return;
        }

        $order->update(['status' => PurchaseOrderStatus::Closed]);
    }

    private function resolveBaseUnitCost(
        Item $item,
        GoodsReceiptLine $line,
        ?PurchaseOrderLine $poLine,
        ?int $itemUomId,
    ): ?string {
        $entered = $line->unit_cost ?? $poLine?->unit_price;

        return StockAdjustmentQuantity::resolveBaseUnitCost($item, $entered, $itemUomId);
    }

    private function lockDraft(GoodsReceipt $receipt): GoodsReceipt
    {
        $locked = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        GoodsReceiptRules::assertDraft($locked);

        return $locked;
    }

    private function formatGrnNumber(): string
    {
        $seq = GoodsReceipt::query()->count();

        return 'GRN-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeUnitCost(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cost = number_format((float) $value, 4, '.', '');
        if (bccomp($cost, '0', 4) < 0) {
            abort(422, 'Unit cost cannot be negative.', ['X-Error-Code' => 'STOCK_UNIT_COST_INVALID']);
        }

        return $cost;
    }
}
