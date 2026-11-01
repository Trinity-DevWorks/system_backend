<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Sales\DeliveryNote\Enums\DeliveryNoteStatus;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNoteLine;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Services\SalesInvoiceService;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesOrder\Models\SalesOrderLine;
use App\Modules\Sales\SalesOrder\Services\SalesOrderFulfillment;
use App\Modules\Sales\Support\CommercialLineQuantity;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\Decimal;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Delivery notes: draft -> posted (stock out + cost of sales) -> reversed.
 * Invoices made from a delivery note book only the revenue (see SalesInvoiceService).
 */
class DeliveryNoteService
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly JournalPostingService $journalPostingService,
        private readonly SalesOrderFulfillment $salesOrderFulfillment,
        private readonly SalesInvoiceService $salesInvoiceService,
        private readonly WarehouseService $warehouseService,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @param  array{status?: string, customer_id?: string, sales_order_id?: string, search?: string}  $filters
     * @return LengthAwarePaginator<int, DeliveryNote>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = DeliveryNote::query()
            ->with(['customer:id,name,customer_code', 'warehouse:id,name', 'salesOrder:id,order_number'])
            ->orderByDesc('delivery_date')
            ->orderByDesc('created_at');
        foreach (['status', 'customer_id', 'sales_order_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');
        ListPagination::applySearch($query, $filters['search'] ?? null, ['delivery_number', 'notes'], ['customer' => ['name', 'customer_code']]);

        return $query->paginate($perPage);
    }

    public function find(string $id): DeliveryNote
    {
        return DeliveryNote::query()
            ->with([
                'customer:id,name,customer_code',
                'warehouse:id,name,shortcut_name',
                'salesOrder:id,order_number',
                'postedByUser:id,name',
                'lines.item:id,item_code,name,track_lots,track_inventory',
                'lines.itemUom.uom:id,code,name',
                'lines.lot:id,lot_number,expiry_date',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): DeliveryNote
    {
        $customer = SalesInvoiceRules::assertCustomer((string) $data['customer_id']);
        SalesInvoiceRules::assertWarehouse((int) $data['warehouse_id']);

        $orderId = $this->deliverableOrderId($data['sales_order_id'] ?? null, (string) $customer->id);

        return DB::transaction(function () use ($data, $userId, $customer, $orderId): DeliveryNote {
            $note = DeliveryNote::query()->create([
                'customer_id' => $customer->id,
                'warehouse_id' => (int) $data['warehouse_id'],
                'sales_order_id' => $orderId,
                'delivery_date' => $data['delivery_date'] ?? now()->toDateString(),
                'shipping_address' => $data['shipping_address'] ?? null,
                'notes' => $this->nullableString($data['notes'] ?? null),
                'status' => DeliveryNoteStatus::Draft,
                'created_by' => $userId,
            ]);
            $this->replaceLines($note, (array) ($data['lines'] ?? []));

            return $this->find((string) $note->id);
        });
    }

    /**
     * Draft delivery note for everything the order has not shipped yet.
     */
    public function createFromOrder(SalesOrder $order, ?string $userId): DeliveryNote
    {
        return DB::transaction(function () use ($order, $userId): DeliveryNote {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $template = $this->orderTemplate($locked);

            return $this->create([
                'customer_id' => $template['customer_id'],
                'warehouse_id' => $template['warehouse_id'],
                'sales_order_id' => $template['sales_order_id'],
                'delivery_date' => $template['delivery_date'],
                'notes' => $template['notes'],
                'lines' => $template['lines'],
            ], $userId);
        });
    }

    /**
     * Unsaved delivery note for everything the order has not shipped yet (fills the create drawer).
     *
     * @return array<string, mixed>
     */
    public function orderTemplate(SalesOrder $order): array
    {
        $this->assertDeliverable($order);
        $order->loadMissing('customer');

        $lines = [];
        foreach (SalesOrderLine::query()->where('sales_order_id', $order->id)->with(['item:id,item_code,name,track_lots'])->orderBy('id')->get() as $line) {
            $qty = $line->undeliveredQuantity();
            if (Decimal::isPositive($qty)) {
                $lines[] = [
                    'sales_order_line_id' => (int) $line->id,
                    'item_id' => (string) $line->item_id,
                    'item' => $line->item ? ['id' => (string) $line->item->id, 'item_code' => $line->item->item_code, 'name' => $line->item->name, 'track_lots' => (bool) $line->item->track_lots] : null,
                    'item_uom_id' => $line->item_uom_id,
                    'quantity' => Decimal::round($qty, 6),
                    'description' => $line->description,
                ];
            }
        }
        if ($lines === []) {
            abort(422, 'This sales order has nothing left to deliver.', ['X-Error-Code' => 'SALES_ORDER_NOTHING_TO_DELIVER']);
        }

        return [
            'customer_id' => (string) $order->customer_id,
            'customer' => $order->customer ? ['id' => (string) $order->customer->id, 'name' => $order->customer->name] : null,
            'warehouse_id' => (int) $order->warehouse_id,
            'sales_order_id' => (string) $order->id,
            'sales_order' => ['id' => (string) $order->id, 'order_number' => $order->order_number],
            'delivery_date' => now()->toDateString(),
            'notes' => $order->notes,
            'lines' => $lines,
        ];
    }

    /**
     * Confirmed orders of a customer that still have something to ship.
     *
     * @return list<array{id: string, order_number: string|null, order_date: string|null}>
     */
    public function deliverableOrders(string $customerId): array
    {
        return SalesOrder::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyInvoiced])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('sales_order_lines as l')
                ->whereColumn('l.sales_order_id', 'sales_orders.id')
                ->whereColumn('l.delivered_base_quantity', '<', 'l.base_quantity'))
            ->orderByDesc('order_date')
            ->limit(100)
            ->get(['id', 'order_number', 'order_date'])
            ->map(fn (SalesOrder $o): array => [
                'id' => (string) $o->id,
                'order_number' => $o->order_number,
                'order_date' => $o->order_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    private function assertDeliverable(SalesOrder $order): void
    {
        if (! in_array($order->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyInvoiced], true)) {
            abort(422, 'Only a confirmed sales order can be delivered.', ['X-Error-Code' => 'SALES_ORDER_NOT_CONFIRMED']);
        }
    }

    /** A linked order must be deliverable and belong to the note's customer. */
    private function deliverableOrderId(mixed $orderId, string $customerId): ?string
    {
        if ($orderId === null || $orderId === '') {
            return null;
        }
        $order = SalesOrder::query()->findOrFail((string) $orderId);
        $this->assertDeliverable($order);
        if ((string) $order->customer_id !== $customerId) {
            abort(422, 'The sales order belongs to another customer.', ['X-Error-Code' => 'DELIVERY_NOTE_ORDER_CUSTOMER_MISMATCH']);
        }

        return (string) $order->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DeliveryNote $note, array $data): DeliveryNote
    {
        return DB::transaction(function () use ($note, $data): DeliveryNote {
            $locked = $this->lockDraft($note);
            $header = [];
            if (array_key_exists('customer_id', $data)) {
                $header['customer_id'] = SalesInvoiceRules::assertCustomer((string) $data['customer_id'])->id;
            }
            if (array_key_exists('warehouse_id', $data)) {
                SalesInvoiceRules::assertWarehouse((int) $data['warehouse_id']);
                $header['warehouse_id'] = (int) $data['warehouse_id'];
            }
            foreach (['delivery_date', 'shipping_address'] as $key) {
                if (array_key_exists($key, $data)) {
                    $header[$key] = $data[$key];
                }
            }
            if (array_key_exists('sales_order_id', $data) || array_key_exists('customer_id', $data)) {
                $header['sales_order_id'] = $this->deliverableOrderId(
                    array_key_exists('sales_order_id', $data) ? $data['sales_order_id'] : $locked->sales_order_id,
                    (string) ($header['customer_id'] ?? $locked->customer_id),
                );
            }
            if (array_key_exists('notes', $data)) {
                $header['notes'] = $this->nullableString($data['notes']);
            }
            if ($header !== []) {
                $locked->update($header);
            }
            if (array_key_exists('lines', $data)) {
                $this->replaceLines($locked, (array) $data['lines']);
            }

            return $this->find((string) $locked->id);
        });
    }

    public function delete(DeliveryNote $note): void
    {
        DB::transaction(function () use ($note): void {
            $this->lockDraft($note)->delete();
        });
    }

    public function post(DeliveryNote $note, ?string $userId): DeliveryNote
    {
        return DB::transaction(function () use ($note, $userId): DeliveryNote {
            $locked = $this->lockDraft($note);
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
            SalesInvoiceRules::assertCustomer((string) $locked->customer_id);

            $lines = DeliveryNoteLine::query()
                ->where('delivery_note_id', $locked->id)
                ->with('item.itemType')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($lines->isEmpty()) {
                abort(422, 'Add at least one line before posting.', ['X-Error-Code' => 'DELIVERY_NOTE_NO_LINES']);
            }

            $this->numbers->assign($locked, 'delivery_number', DocumentType::DeliveryNote, (int) $locked->branch_id, $locked->delivery_date);

            // Order lines leave the reservation before the stock moves (see StockReservationService).
            $this->salesOrderFulfillment->applyDelivery($locked, false);

            foreach ($lines as $line) {
                $this->postLineStock($locked, $line, $userId);
            }

            $this->journalPostingService->deliveryNote($locked, $userId);

            $locked->update([
                'status' => DeliveryNoteStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find((string) $locked->id);
        });
    }

    public function reverse(DeliveryNote $note, ?string $userId): DeliveryNote
    {
        return DB::transaction(function () use ($note, $userId): DeliveryNote {
            $locked = DeliveryNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== DeliveryNoteStatus::Posted) {
                abort(422, 'Only a posted delivery note can be reversed.', ['X-Error-Code' => 'DELIVERY_NOTE_NOT_POSTED']);
            }
            $invoiced = DeliveryNoteLine::query()
                ->where('delivery_note_id', $locked->id)
                ->where('invoiced_base_quantity', '>', 0)
                ->exists();
            if ($invoiced) {
                abort(422, 'This delivery note is already invoiced. Reverse the invoice first.', ['X-Error-Code' => 'DELIVERY_NOTE_HAS_INVOICES']);
            }
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

            $this->stockMovementService->reverseReference(DeliveryNote::REFERENCE_TYPE, (string) $locked->id, $userId);
            $this->journalPostingService->reverse(DeliveryNote::REFERENCE_TYPE, (string) $locked->id, $userId);
            $this->salesOrderFulfillment->applyDelivery($locked, true);

            $locked->update(['status' => DeliveryNoteStatus::Reversed]);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * Draft invoice for the delivered quantities not invoiced yet (revenue only; the stock already left).
     */
    public function createInvoice(DeliveryNote $note, ?string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($note, $userId): SalesInvoice {
            $locked = DeliveryNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== DeliveryNoteStatus::Posted) {
                abort(422, 'Only a posted delivery note can be invoiced.', ['X-Error-Code' => 'DELIVERY_NOTE_NOT_POSTED']);
            }

            $order = $locked->sales_order_id !== null ? SalesOrder::query()->find($locked->sales_order_id) : null;
            $orderLines = $order !== null
                ? SalesOrderLine::query()->where('sales_order_id', $order->id)->get()->keyBy('id')
                : collect();

            $rows = [];
            foreach (DeliveryNoteLine::query()->where('delivery_note_id', $locked->id)->orderBy('sort_order')->orderBy('id')->get() as $line) {
                $qty = $line->uninvoicedQuantity();
                if (! Decimal::isPositive($qty)) {
                    continue;
                }
                $orderLine = $line->sales_order_line_id !== null ? $orderLines->get($line->sales_order_line_id) : null;
                $rows[] = [
                    'delivery_note_line_id' => (int) $line->id,
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'item_id' => (string) $line->item_id,
                    'item_uom_id' => $line->item_uom_id,
                    'quantity' => Decimal::round($qty, 6),
                    'unit_price' => $orderLine?->unit_price ?? $this->catalogPrice($line),
                    'discount_percent' => $orderLine?->discount_percent ?? 0,
                    'description' => $line->description,
                    'warehouse_id' => $line->warehouse_id ?? $locked->warehouse_id,
                    'lot_id' => $line->lot_id,
                ];
            }
            if ($rows === []) {
                abort(422, 'This delivery note is fully invoiced.', ['X-Error-Code' => 'DELIVERY_NOTE_NOTHING_TO_INVOICE']);
            }

            return $this->salesInvoiceService->create([
                'customer_id' => (string) $locked->customer_id,
                'warehouse_id' => (int) $locked->warehouse_id,
                'currency_id' => $order?->currency_id ?? Currency::getPrimary()?->id,
                'exchange_rate' => $order?->exchange_rate,
                'salesman_id' => $order?->salesman_id,
                'payment_terms_id' => $order?->payment_terms_id,
                'invoice_date' => now()->toDateString(),
                'sales_order_id' => $order?->id,
                'shipping_address' => $locked->shipping_address,
                'lines' => $rows,
            ], $userId);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function replaceLines(DeliveryNote $note, array $rows): void
    {
        DeliveryNoteLine::query()->where('delivery_note_id', $note->id)->delete();

        $orderLines = $note->sales_order_id !== null
            ? SalesOrderLine::query()->where('sales_order_id', $note->sales_order_id)->get()->keyBy('id')
            : collect();

        foreach (array_values($rows) as $index => $row) {
            $item = Item::query()->findOrFail((string) $row['item_id']);
            SalesInvoiceRules::assertSellableItem($item);

            $uomId = isset($row['item_uom_id']) && $row['item_uom_id'] !== '' && $row['item_uom_id'] !== null ? (int) $row['item_uom_id'] : null;
            $qty = CommercialLineQuantity::resolve($item, $row['quantity'] ?? 0, $uomId, 'DELIVERY_NOTE');

            $orderLineId = isset($row['sales_order_line_id']) && $row['sales_order_line_id'] !== '' && $row['sales_order_line_id'] !== null
                ? (int) $row['sales_order_line_id']
                : null;
            if ($orderLineId !== null) {
                $orderLine = $orderLines->get($orderLineId);
                if (! $orderLine instanceof SalesOrderLine
                    || (string) $orderLine->item_id !== (string) $item->id
                    || (int) ($orderLine->item_uom_id ?? 0) !== (int) ($qty['item_uom_id'] ?? 0)) {
                    abort(422, 'Line '.($index + 1).': does not match the sales order line.', [
                        'X-Error-Code' => 'DELIVERY_NOTE_ORDER_LINE_MISMATCH',
                    ]);
                }
            }

            $warehouseId = isset($row['warehouse_id']) && $row['warehouse_id'] !== '' && $row['warehouse_id'] !== null
                ? (int) $row['warehouse_id']
                : null;
            if ($warehouseId !== null) {
                SalesInvoiceRules::assertWarehouse($warehouseId);
            }

            $lotId = isset($row['lot_id']) && $row['lot_id'] !== '' && $row['lot_id'] !== null ? (int) $row['lot_id'] : null;
            if ($item->track_lots) {
                if ($lotId === null) {
                    abort(422, 'Line '.($index + 1).': select a lot for this item.', ['X-Error-Code' => 'STOCK_LOT_REQUIRED']);
                }
                $lot = InventoryLot::query()->findOrFail($lotId);
                if ((string) $lot->item_id !== (string) $item->id) {
                    abort(422, 'Line '.($index + 1).': lot does not belong to this item.', ['X-Error-Code' => 'DELIVERY_NOTE_LOT_ITEM_MISMATCH']);
                }
            } else {
                $lotId = null;
            }

            DeliveryNoteLine::query()->create([
                'delivery_note_id' => $note->id,
                'sales_order_line_id' => $orderLineId,
                'item_id' => $item->id,
                'item_uom_id' => $qty['item_uom_id'],
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'sort_order' => $index + 1,
                'quantity' => $qty['quantity'],
                'base_quantity' => $qty['base_quantity'],
                'conversion_factor' => $qty['conversion_factor'],
                'invoiced_base_quantity' => '0',
                'description' => $this->nullableString($row['description'] ?? $item->name),
                'notes' => $this->nullableString($row['notes'] ?? null),
            ]);
        }
    }

    private function postLineStock(DeliveryNote $note, DeliveryNoteLine $line, ?string $userId): void
    {
        $item = $line->item ?? Item::query()->findOrFail($line->item_id);
        $warehouseId = (int) ($line->warehouse_id ?? $note->warehouse_id);

        if (SalesInvoiceRules::isBundleItem($item)) {
            $components = BundleItem::query()->where('bundle_item_id', $item->id)->with('childItem')->get();
            if ($components->isEmpty()) {
                abort(422, 'Bundle has no components.', ['X-Error-Code' => 'SALES_INVOICE_BUNDLE_EMPTY']);
            }
            // " bundle " in the note lets credit notes find the component cost (SalesCreditNoteService).
            $note_ = 'DN '.$note->delivery_number.' bundle '.$item->item_code;
            foreach ($components as $component) {
                $child = $component->childItem;
                if (! $child instanceof Item || ! $child->track_inventory) {
                    continue;
                }
                if ($child->track_lots) {
                    abort(422, 'Lot-tracked bundle components cannot be delivered. Explode the bundle first.', [
                        'X-Error-Code' => 'SALES_INVOICE_BUNDLE_LOT_NOT_SUPPORTED',
                    ]);
                }
                $this->stockMovementService->post(StockMovementData::forSaleBundleComponent(
                    itemId: (string) $child->id,
                    warehouseId: $warehouseId,
                    quantityDelta: Decimal::neg(Decimal::mul($line->base_quantity, $component->quantity, 6), 6),
                    salesInvoiceId: (string) $note->id,
                    notes: $note_,
                    userId: $userId,
                    referenceType: DeliveryNote::REFERENCE_TYPE,
                ));
            }

            return;
        }

        if (! $item->track_inventory) {
            return;
        }

        $text = 'DN '.$note->delivery_number;
        $this->stockMovementService->post(StockMovementData::forSale(
            itemId: (string) $line->item_id,
            warehouseId: $warehouseId,
            quantityDelta: Decimal::neg($line->base_quantity, 6),
            salesInvoiceId: (string) $note->id,
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: $line->notes ? $text.' — '.$line->notes : $text,
            userId: $userId,
            lotId: $line->lot_id ? (int) $line->lot_id : null,
            referenceType: DeliveryNote::REFERENCE_TYPE,
        ));
    }

    private function catalogPrice(DeliveryNoteLine $line): string
    {
        $price = $line->item_uom_id !== null ? ItemUom::query()->whereKey($line->item_uom_id)->value('selling_price') : null;

        return Decimal::of($price ?? 0);
    }

    private function lockDraft(DeliveryNote $note): DeliveryNote
    {
        $locked = DeliveryNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== DeliveryNoteStatus::Draft) {
            abort(422, 'Only a draft delivery note can be changed or posted.', ['X-Error-Code' => 'DELIVERY_NOTE_NOT_DRAFT']);
        }

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
