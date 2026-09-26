<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Enums\StockTransferClosureOutcome;
use App\Modules\Inventory\Stock\Enums\StockTransferReceiptStatus;
use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferClosure;
use App\Modules\Inventory\Stock\Models\StockTransferClosureLine;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use App\Modules\Inventory\Stock\Models\StockTransferReceipt;
use App\Modules\Inventory\Stock\Models\StockTransferReceiptLine;
use App\Modules\Inventory\Stock\Support\StockTransferLineQuantity;
use App\Modules\Inventory\Stock\Support\StockTransferRules;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly StockTransferQueryService $stockTransferQueryService,
        private readonly DomainNotificationPublisher $notifications,
        private readonly InventoryLotService $inventoryLotService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   from_warehouse_id?:int,
     *   to_warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, StockTransfer>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->stockTransferQueryService->paginate($filters, $perPage);
    }

    public function find(string $id): StockTransfer
    {
        $transfer = StockTransfer::query()
            ->with([
                'fromWarehouse',
                'toWarehouse',
                'createdByUser',
                'dispatchedByUser',
                'receivedByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
                'lines.lot',
                'receipts' => fn ($query) => $query->orderByDesc('posted_at'),
                'receipts.lines.item',
                'receipts.lines.lot',
                'receipts.createdByUser',
                'receipts.postedByUser',
                'closures' => fn ($query) => $query->orderByDesc('closed_at'),
                'closures.lines.item',
                'closures.lines.lot',
                'closures.lines.reason',
                'closures.createdByUser',
            ])
            ->findOrFail($id);

        StockTransferRules::assertTransferVisible($transfer);

        return $transfer;
    }

    /**
     * @param  array{
     *   from_warehouse_id:int,
     *   to_warehouse_id:int,
     *   notes?:?string,
     *   lines?:list<array{item_id:string,quantity:numeric,item_uom_id?:?int,notes?:?string}>
     * }  $data
     */
    public function create(array $data, ?string $userId): StockTransfer
    {
        StockTransferRules::assertWarehouses(
            (int) $data['from_warehouse_id'],
            (int) $data['to_warehouse_id']
        );

        return DB::transaction(function () use ($data, $userId): StockTransfer {
            $transfer = StockTransfer::query()->create([
                'from_warehouse_id' => (int) $data['from_warehouse_id'],
                'to_warehouse_id' => (int) $data['to_warehouse_id'],
                'status' => StockTransferStatus::Draft,
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
            ]);

            $transfer->update(['transfer_number' => $this->formatTransferNumber()]);

            if (! empty($data['lines'])) {
                $this->replaceLines($transfer, $data['lines']);
            }

            return $this->find($transfer->id);
        });
    }

    /**
     * @param  array{
     *   from_warehouse_id?:int,
     *   to_warehouse_id?:int,
     *   notes?:?string
     * }  $data
     */
    public function updateHeader(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data): StockTransfer {
            $transfer = $this->lockDraftTransfer($transfer);

            $fromId = (int) ($data['from_warehouse_id'] ?? $transfer->from_warehouse_id);
            $toId = (int) ($data['to_warehouse_id'] ?? $transfer->to_warehouse_id);
            StockTransferRules::assertWarehouses($fromId, $toId);

            $transfer->update([
                'from_warehouse_id' => $fromId,
                'to_warehouse_id' => $toId,
                'notes' => array_key_exists('notes', $data)
                    ? $this->normalizeNotes($data['notes'])
                    : $transfer->notes,
            ]);

            return $this->find($transfer->id);
        });
    }

    /**
     * @param  list<array{item_id:string,quantity:numeric,item_uom_id?:?int,notes?:?string}>  $lines
     */
    public function syncLines(StockTransfer $transfer, array $lines): Collection
    {
        return DB::transaction(function () use ($transfer, $lines): Collection {
            $transfer = $this->lockDraftTransfer($transfer);
            $this->replaceLines($transfer, $lines);

            return StockTransferLine::query()
                ->where('stock_transfer_id', $transfer->id)
                ->with(['item', 'itemUom.uom'])
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $transfer = $this->lockDraftTransfer($transfer);
            $transfer->delete();
        });
    }

    public function cancel(StockTransfer $transfer, ?string $userId): StockTransfer
    {
        $cancelled = DB::transaction(function () use ($transfer, $userId): StockTransfer {
            $locked = $this->lockTransfer($transfer);
            StockTransferRules::assertCancellable($locked);
            if ($locked->status === StockTransferStatus::InTransit) {
                StockTransferRules::assertCanCancelTransit($locked);
                $this->restoreSourceStock($locked, $userId);
            }

            $locked->update(['status' => StockTransferStatus::Cancelled]);

            return $this->find($locked->id);
        });

        $this->notifications->stockTransferCancelled($cancelled, $userId);

        return $cancelled;
    }

    public function dispatch(StockTransfer $transfer, ?string $userId): StockTransfer
    {
        $dispatched = DB::transaction(function () use ($transfer, $userId): StockTransfer {
            $locked = $this->lockTransfer($transfer);
            StockTransferRules::assertDispatchable($locked);
            StockTransferRules::assertWarehouses($locked->from_warehouse_id, $locked->to_warehouse_id);

            $lines = $this->lockTransferLines($locked);
            $referenceNote = 'Transfer '.$locked->transfer_number;

            foreach ($lines as $line) {
                $baseQty = (string) $line->base_quantity;
                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;

                $this->stockMovementService->post(StockMovementData::forTransfer(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->from_warehouse_id,
                    quantityDelta: bcmul($baseQty, '-1', 6),
                    type: StockMovementType::TransferOut,
                    stockTransferId: (string) $locked->id,
                    itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
                    notes: $lineNote,
                    userId: $userId,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                ));
            }

            $locked->update([
                'status' => StockTransferStatus::InTransit,
                'dispatched_by' => $userId,
                'dispatched_at' => now(),
            ]);

            return $this->find($locked->id);
        });

        $this->notifications->stockTransferDispatched($dispatched, $userId);

        return $dispatched;
    }

    /**
     * @param  array{
     *   received_date?:?string,
     *   notes?:?string,
     *   lines?:list<array{stock_transfer_line_id:int,quantity:numeric,notes?:?string}>
     * }  $data
     */
    public function receive(StockTransfer $transfer, ?string $userId, array $data = []): StockTransfer
    {
        $received = DB::transaction(function () use ($transfer, $userId, $data): StockTransfer {
            $locked = $this->lockTransfer($transfer);
            $locked->load('lines');
            StockTransferRules::assertReceivable($locked);
            StockTransferRules::assertReceiveSide($locked);
            StockTransferRules::assertWarehouses($locked->from_warehouse_id, $locked->to_warehouse_id);

            $lines = $this->lockTransferLines($locked);
            $posted = $this->normalizeReceiptLines($lines, $data['lines'] ?? []);
            $outCosts = $this->transferOutUnitCosts((string) $locked->id);
            $referenceNote = 'Transfer '.$locked->transfer_number;
            $receivedDate = $this->normalizeReceivedDate($data['received_date'] ?? null);

            $receipt = StockTransferReceipt::query()->create([
                'stock_transfer_id' => $locked->id,
                'warehouse_id' => (int) $locked->to_warehouse_id,
                'status' => StockTransferReceiptStatus::Posted,
                'received_date' => $receivedDate,
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);
            $receipt->update(['receipt_number' => $this->formatReceiptNumber()]);

            foreach ($posted as $row) {
                /** @var StockTransferLine $line */
                $line = $row['line'];
                $qty = $row['quantity'];
                $baseQty = $row['base_quantity'];
                $lineNote = $row['notes'] ?? ($line->notes ? $referenceNote.' — '.$line->notes : $referenceNote);
                $unitCost = $outCosts->get(self::costKey((string) $line->item_id, $line->lot_id ? (int) $line->lot_id : null));

                StockTransferReceiptLine::query()->create([
                    'stock_transfer_receipt_id' => $receipt->id,
                    'stock_transfer_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'lot_id' => $line->lot_id,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'item_uom_id' => $line->item_uom_id,
                    'notes' => $row['notes'],
                ]);

                $this->stockMovementService->post(StockMovementData::forTransfer(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $locked->to_warehouse_id,
                    quantityDelta: $baseQty,
                    type: StockMovementType::TransferIn,
                    stockTransferId: (string) $locked->id,
                    itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
                    notes: $lineNote,
                    userId: $userId,
                    unitCost: $unitCost !== null ? (string) $unitCost : null,
                    lotId: $line->lot_id ? (int) $line->lot_id : null,
                ));

                $line->update([
                    'received_quantity' => bcadd((string) $line->received_quantity, $qty, 6),
                    'received_base_quantity' => bcadd((string) $line->received_base_quantity, $baseQty, 6),
                ]);
            }

            $this->syncLifecycleAfterReceive($locked, $userId);

            return $this->find($locked->id);
        });

        $this->notifications->stockTransferReceived($received, $userId);

        return $received;
    }

    /**
     * @param  array{
     *   notes?:?string,
     *   lines:list<array{stock_transfer_line_id:int,outcome:string,quantity:numeric,stock_adjustment_reason_id:int,notes?:?string}>
     * }  $data
     */
    public function closeOpen(StockTransfer $transfer, ?string $userId, array $data): StockTransfer
    {
        $closed = DB::transaction(function () use ($transfer, $userId, $data): StockTransfer {
            $locked = $this->lockTransfer($transfer);
            $locked->load('lines');
            StockTransferRules::assertCloseable($locked);
            StockTransferRules::assertSourceSide($locked);
            StockTransferRules::assertWarehouses($locked->from_warehouse_id, $locked->to_warehouse_id);

            $lines = $this->lockTransferLines($locked);
            $posted = $this->normalizeCloseLines($lines, $data['lines'] ?? []);
            $outCosts = $this->transferOutUnitCosts((string) $locked->id);
            $referenceNote = 'Transfer '.$locked->transfer_number.' — leftover';

            $closure = StockTransferClosure::query()->create([
                'stock_transfer_id' => $locked->id,
                'warehouse_id' => (int) $locked->from_warehouse_id,
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'created_by' => $userId,
                'closed_at' => now(),
            ]);
            $closure->update(['closure_number' => $this->formatClosureNumber()]);

            foreach ($posted as $row) {
                /** @var StockTransferLine $line */
                $line = $row['line'];
                $qty = $row['quantity'];
                $baseQty = $row['base_quantity'];
                $outcome = $row['outcome'];

                StockTransferClosureLine::query()->create([
                    'stock_transfer_closure_id' => $closure->id,
                    'stock_transfer_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'lot_id' => $line->lot_id,
                    'outcome' => $outcome->value,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'item_uom_id' => $line->item_uom_id,
                    'stock_adjustment_reason_id' => $row['reason_id'],
                    'notes' => $row['notes'],
                ]);

                if ($outcome === StockTransferClosureOutcome::Return) {
                    $lineNote = $row['notes'] ?? $referenceNote;
                    $unitCost = $outCosts->get(self::costKey((string) $line->item_id, $line->lot_id ? (int) $line->lot_id : null));
                    $this->stockMovementService->post(StockMovementData::forTransfer(
                        itemId: (string) $line->item_id,
                        warehouseId: (int) $locked->from_warehouse_id,
                        quantityDelta: $baseQty,
                        type: StockMovementType::TransferReturn,
                        stockTransferId: (string) $locked->id,
                        itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
                        notes: $lineNote,
                        userId: $userId,
                        unitCost: $unitCost !== null ? (string) $unitCost : null,
                        lotId: $line->lot_id ? (int) $line->lot_id : null,
                    ));
                    $line->update([
                        'returned_quantity' => bcadd((string) $line->returned_quantity, $qty, 6),
                        'returned_base_quantity' => bcadd((string) $line->returned_base_quantity, $baseQty, 6),
                    ]);
                } else {
                    $line->update([
                        'written_off_quantity' => bcadd((string) $line->written_off_quantity, $qty, 6),
                        'written_off_base_quantity' => bcadd((string) $line->written_off_base_quantity, $baseQty, 6),
                    ]);
                }
            }

            $this->syncLifecycleAfterReceive($locked, $userId);

            return $this->find($locked->id);
        });

        return $closed;
    }

    /**
     * @param  list<array{item_id:string,quantity:numeric,item_uom_id?:?int,lot_id?:?int,lot_number?:?string,expiry_date?:?string,notes?:?string}>  $lines
     */
    private function replaceLines(StockTransfer $transfer, array $lines): void
    {
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = (string) $row['item_id'];
            $item = Item::query()->findOrFail($itemId);
            $lot = $this->inventoryLotService->resolve(
                $item,
                isset($row['lot_id']) ? (int) $row['lot_id'] : null,
                isset($row['lot_number']) ? (string) $row['lot_number'] : null,
                isset($row['expiry_date']) ? (string) $row['expiry_date'] : null,
                inbound: false,
            );
            $lotId = $lot?->id;
            $lineKey = self::costKey($itemId, $lotId);
            if (isset($normalized[$lineKey])) {
                abort(422, 'Duplicate item and lot combinations are not allowed on a transfer.', ['X-Error-Code' => 'STOCK_TRANSFER_DUPLICATE_ITEM']);
            }

            $resolved = StockTransferLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                isset($row['item_uom_id']) ? (int) $row['item_uom_id'] : null
            );

            $normalized[$lineKey] = [
                ...$resolved,
                'item_id' => $itemId,
                'lot_id' => $lotId,
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        StockTransferLine::query()->where('stock_transfer_id', $transfer->id)->delete();

        foreach ($normalized as $line) {
            StockTransferLine::query()->create([
                'stock_transfer_id' => $transfer->id,
                'item_id' => $line['item_id'],
                'lot_id' => $line['lot_id'],
                'quantity' => $line['quantity'],
                'base_quantity' => $line['base_quantity'],
                'item_uom_id' => $line['item_uom_id'],
                'notes' => $line['notes'],
            ]);
        }
    }

    /**
     * @param  Collection<int, StockTransferLine>  $lines
     * @param  list<array{stock_transfer_line_id?:int,quantity?:numeric,notes?:?string}>  $input
     * @return list<array{line:StockTransferLine,quantity:string,base_quantity:string,notes:?string}>
     */
    private function normalizeReceiptLines(Collection $lines, array $input): array
    {
        $byId = $lines->keyBy('id');
        $rows = $input;
        if ($rows === []) {
            foreach ($lines as $line) {
                $open = StockTransferLineQuantity::openQuantity($line);
                if (bccomp($open, '0', 6) <= 0) {
                    continue;
                }
                $rows[] = [
                    'stock_transfer_line_id' => (int) $line->id,
                    'quantity' => $open,
                ];
            }
        }

        $posted = [];
        $seen = [];
        foreach ($rows as $row) {
            $lineId = (int) ($row['stock_transfer_line_id'] ?? 0);
            if ($lineId < 1 || isset($seen[$lineId])) {
                abort(422, 'Each transfer line can appear only once on a receipt.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_DUPLICATE_RECEIPT_LINE',
                ]);
            }
            $seen[$lineId] = true;
            /** @var StockTransferLine|null $line */
            $line = $byId->get($lineId);
            if (! $line) {
                abort(422, 'Receipt line does not belong to this transfer.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_LINE_NOT_FOUND',
                ]);
            }

            $qty = number_format((float) ($row['quantity'] ?? 0), 6, '.', '');
            if (bccomp($qty, '0', 6) <= 0) {
                continue;
            }
            $open = StockTransferLineQuantity::openQuantity($line);
            if (bccomp($qty, $open, 6) > 0) {
                abort(422, 'Received quantity exceeds the remaining open quantity.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_QTY_EXCEEDS_OPEN',
                ]);
            }

            $posted[] = [
                'line' => $line,
                'quantity' => $qty,
                'base_quantity' => StockTransferLineQuantity::baseForQuantity($line, $qty),
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        if ($posted === []) {
            abort(422, 'Cannot post a transfer receipt without quantities.', [
                'X-Error-Code' => 'STOCK_TRANSFER_NO_RECEIPT_QTY',
            ]);
        }

        return $posted;
    }

    /**
     * @param  Collection<int, StockTransferLine>  $lines
     * @param  list<array{stock_transfer_line_id?:int,outcome?:string,quantity?:numeric,stock_adjustment_reason_id?:int,notes?:?string}>  $input
     * @return list<array{line:StockTransferLine,outcome:StockTransferClosureOutcome,quantity:string,base_quantity:string,reason_id:int,notes:?string}>
     */
    private function normalizeCloseLines(Collection $lines, array $input): array
    {
        if ($input === []) {
            abort(422, 'Select remaining quantities to return or write off.', [
                'X-Error-Code' => 'STOCK_TRANSFER_NO_CLOSE_LINES',
            ]);
        }

        $byId = $lines->keyBy('id');
        $posted = [];
        $seen = [];
        foreach ($input as $row) {
            $lineId = (int) ($row['stock_transfer_line_id'] ?? 0);
            if ($lineId < 1 || isset($seen[$lineId])) {
                abort(422, 'Each transfer line can appear only once when closing remaining quantity.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_DUPLICATE_CLOSE_LINE',
                ]);
            }
            $seen[$lineId] = true;
            /** @var StockTransferLine|null $line */
            $line = $byId->get($lineId);
            if (! $line) {
                abort(422, 'Close line does not belong to this transfer.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_LINE_NOT_FOUND',
                ]);
            }

            $outcome = StockTransferClosureOutcome::tryFrom((string) ($row['outcome'] ?? ''));
            if ($outcome === null) {
                abort(422, 'Remaining quantity must be returned or written off.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_INVALID_CLOSE_OUTCOME',
                ]);
            }

            $qty = number_format((float) ($row['quantity'] ?? 0), 6, '.', '');
            if (bccomp($qty, '0', 6) <= 0) {
                continue;
            }
            $open = StockTransferLineQuantity::openQuantity($line);
            if (bccomp($qty, $open, 6) > 0) {
                abort(422, 'Closed quantity exceeds the remaining open quantity.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_QTY_EXCEEDS_OPEN',
                ]);
            }

            $reasonId = (int) ($row['stock_adjustment_reason_id'] ?? 0);
            $reason = StockAdjustmentReason::query()->find($reasonId);
            if (! $reason || ! $reason->is_active) {
                abort(422, 'A reason is required to close remaining transfer quantity.', [
                    'X-Error-Code' => 'STOCK_TRANSFER_CLOSE_REASON_REQUIRED',
                ]);
            }

            $posted[] = [
                'line' => $line,
                'outcome' => $outcome,
                'quantity' => $qty,
                'base_quantity' => StockTransferLineQuantity::baseForQuantity($line, $qty),
                'reason_id' => (int) $reason->id,
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        if ($posted === []) {
            abort(422, 'Select remaining quantities to return or write off.', [
                'X-Error-Code' => 'STOCK_TRANSFER_NO_CLOSE_LINES',
            ]);
        }

        return $posted;
    }

    private function syncLifecycleAfterReceive(StockTransfer $transfer, ?string $userId): void
    {
        $transfer->unsetRelation('lines');
        $transfer->load('lines');
        $hasOpen = StockTransferLineQuantity::hasOpenQuantity($transfer);
        $updates = [
            'status' => $hasOpen ? StockTransferStatus::PartiallyReceived : StockTransferStatus::Received,
        ];
        if ($transfer->received_at === null) {
            $updates['received_by'] = $userId;
            $updates['received_at'] = now();
        }
        $transfer->update($updates);
    }

    private function normalizeReceivedDate(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return now()->toDateString();
    }

    private function formatReceiptNumber(): string
    {
        $seq = StockTransferReceipt::query()->count();

        return 'STR-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function formatClosureNumber(): string
    {
        $seq = StockTransferClosure::query()->count();

        return 'STC-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function restoreSourceStock(StockTransfer $transfer, ?string $userId): void
    {
        $lines = $this->lockTransferLines($transfer);
        $outCosts = $this->transferOutUnitCosts((string) $transfer->id);
        $referenceNote = 'Transfer '.$transfer->transfer_number.' — cancelled';

        foreach ($lines as $line) {
            $baseQty = (string) $line->base_quantity;
            $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;
            $unitCost = $outCosts->get(self::costKey((string) $line->item_id, $line->lot_id ? (int) $line->lot_id : null));

            $this->stockMovementService->post(StockMovementData::forTransfer(
                itemId: (string) $line->item_id,
                warehouseId: (int) $transfer->from_warehouse_id,
                quantityDelta: $baseQty,
                type: StockMovementType::TransferReturn,
                stockTransferId: (string) $transfer->id,
                itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
                notes: $lineNote,
                userId: $userId,
                unitCost: $unitCost !== null ? (string) $unitCost : null,
                lotId: $line->lot_id ? (int) $line->lot_id : null,
            ));
        }
    }

    /**
     * @return Collection<int, StockTransferLine>
     */
    private function lockTransferLines(StockTransfer $transfer): Collection
    {
        $lines = StockTransferLine::query()
            ->where('stock_transfer_id', $transfer->id)
            ->orderBy('item_id')
            ->lockForUpdate()
            ->get();

        if ($lines->isEmpty()) {
            abort(422, 'Cannot process a transfer without lines.', ['X-Error-Code' => 'STOCK_TRANSFER_NO_LINES']);
        }

        return $lines;
    }

    /**
     * @return \Illuminate\Support\Collection<string, string>
     */
    private function transferOutUnitCosts(string $transferId): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<string, string> $costs */
        $costs = StockMovement::query()
            ->where('reference_type', 'stock_transfer')
            ->where('reference_id', $transferId)
            ->where('type', StockMovementType::TransferOut)
            ->get()
            ->mapWithKeys(fn (StockMovement $movement): array => [
                self::costKey((string) $movement->item_id, $movement->lot_id ? (int) $movement->lot_id : null) => (string) $movement->unit_cost,
            ]);

        return $costs;
    }

    private static function costKey(string $itemId, ?int $lotId): string
    {
        return $itemId.'|'.($lotId ?? '');
    }

    private function lockDraftTransfer(StockTransfer $transfer): StockTransfer
    {
        $locked = $this->lockTransfer($transfer);
        StockTransferRules::assertDraft($locked);

        return $locked;
    }

    private function lockTransfer(StockTransfer $transfer): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        StockTransferRules::assertTransferVisible($locked);

        return $locked;
    }

    private function formatTransferNumber(): string
    {
        $seq = StockTransfer::query()->count();

        return 'ST-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
