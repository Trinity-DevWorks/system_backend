<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderLineQuantity;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderQueryService $purchaseOrderQueryService
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   supplier_id?:string,
     *   warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string,
     *   limit?:int
     * }  $filters
     * @return Collection<int, PurchaseOrder>
     */
    public function list(array $filters = []): Collection
    {
        return $this->purchaseOrderQueryService->list($filters);
    }

    public function find(string $id): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'warehouse',
                'createdByUser',
                'confirmedByUser',
                'sentByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array{
     *   supplier_id:string,
     *   warehouse_id:int,
     *   order_date?:string,
     *   expected_date?:?string,
     *   notes?:?string,
     *   lines?:list<array{
     *     item_id:string,
     *     quantity:numeric,
     *     item_uom_id?:?int,
     *     unit_price?:?numeric,
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
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $this->normalizeDate($data['expected_date'] ?? null),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $order->update(['po_number' => $this->formatPoNumber()]);

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

            $order->update([
                'supplier_id' => array_key_exists('supplier_id', $data)
                    ? (string) $data['supplier_id']
                    : $order->supplier_id,
                'warehouse_id' => array_key_exists('warehouse_id', $data)
                    ? (int) $data['warehouse_id']
                    : $order->warehouse_id,
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

            return $this->find($order->id);
        });
    }

    /**
     * @param  list<array{
     *   item_id:string,
     *   quantity:numeric,
     *   item_uom_id?:?int,
     *   unit_price?:?numeric,
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
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            PurchaseOrderRules::assertCancellable($locked);
            $locked->update(['status' => PurchaseOrderStatus::Cancelled]);

            return $this->find($locked->id);
        });
    }

    public function confirm(PurchaseOrder $order, ?string $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $order = $this->lockDraftOrder($order);

            $lineCount = PurchaseOrderLine::query()
                ->where('purchase_order_id', $order->id)
                ->count();

            if ($lineCount === 0) {
                abort(422, 'Cannot confirm a purchase order without lines.', ['X-Error-Code' => 'PURCHASE_ORDER_NO_LINES']);
            }

            $order->update([
                'status' => PurchaseOrderStatus::Confirmed,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return $this->find($order->id);
        });
    }

    public function markAsSent(PurchaseOrder $order, ?string $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            PurchaseOrderRules::assertMarkAsSent($locked);

            $locked->update([
                'sent_at' => now(),
                'sent_by' => $userId,
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array{
     *   item_id:string,
     *   quantity:numeric,
     *   item_uom_id?:?int,
     *   unit_price?:?numeric,
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
                'unit_price' => $this->normalizeUnitPrice($row['unit_price'] ?? null),
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->delete();

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
                'notes' => $line['notes'],
            ]);
        }
    }

    private function lockDraftOrder(PurchaseOrder $order): PurchaseOrder
    {
        $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        PurchaseOrderRules::assertDraft($locked);

        return $locked;
    }

    private function formatPoNumber(): string
    {
        $seq = PurchaseOrder::query()->count();

        return 'PO-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
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

        $price = number_format((float) $value, 4, '.', '');

        if (bccomp($price, '0', 4) < 0) {
            abort(422, 'Unit price cannot be negative.', ['X-Error-Code' => 'PURCHASE_ORDER_LINE_INVALID_UNIT_PRICE']);
        }

        return $price;
    }
}
