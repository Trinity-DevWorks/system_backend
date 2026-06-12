<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use App\Modules\Inventory\Stock\Support\StockTransferLineQuantity;
use App\Modules\Inventory\Stock\Support\StockTransferRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly StockTransferQueryService $stockTransferQueryService
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   from_warehouse_id?:int,
     *   to_warehouse_id?:int,
     *   search?:string,
     *   from?:string,
     *   to?:string,
     *   limit?:int
     * }  $filters
     * @return Collection<int, StockTransfer>
     */
    public function list(array $filters = []): Collection
    {
        return $this->stockTransferQueryService->list($filters);
    }

    public function find(string $id): StockTransfer
    {
        return StockTransfer::query()
            ->with([
                'fromWarehouse',
                'toWarehouse',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
            ])
            ->findOrFail($id);
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

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $transfer = $this->lockDraftTransfer($transfer);
            $transfer->update(['status' => StockTransferStatus::Cancelled]);

            return $this->find($transfer->id);
        });
    }

    public function post(StockTransfer $transfer, ?string $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): StockTransfer {
            $transfer = $this->lockDraftTransfer($transfer);
            StockTransferRules::assertWarehouses($transfer->from_warehouse_id, $transfer->to_warehouse_id);

            $lines = StockTransferLine::query()
                ->where('stock_transfer_id', $transfer->id)
                ->orderBy('item_id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                abort(422, 'Cannot post a transfer without lines.', ['X-Error-Code' => 'STOCK_TRANSFER_NO_LINES']);
            }

            $referenceNote = 'Transfer '.$transfer->transfer_number;

            foreach ($lines as $line) {
                $baseQty = (string) $line->base_quantity;
                $lineNote = $line->notes ? $referenceNote.' — '.$line->notes : $referenceNote;

                $this->stockMovementService->post(StockMovementData::forTransfer(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $transfer->from_warehouse_id,
                    quantityDelta: bcmul($baseQty, '-1', 6),
                    type: StockMovementType::TransferOut,
                    stockTransferId: (string) $transfer->id,
                    itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
                    notes: $lineNote,
                    userId: $userId,
                ));

                $this->stockMovementService->post(StockMovementData::forTransfer(
                    itemId: (string) $line->item_id,
                    warehouseId: (int) $transfer->to_warehouse_id,
                    quantityDelta: $baseQty,
                    type: StockMovementType::TransferIn,
                    stockTransferId: (string) $transfer->id,
                    itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
                    notes: $lineNote,
                    userId: $userId,
                ));
            }

            $transfer->update([
                'status' => StockTransferStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($transfer->id);
        });
    }

    /**
     * @param  list<array{item_id:string,quantity:numeric,item_uom_id?:?int,notes?:?string}>  $lines
     */
    private function replaceLines(StockTransfer $transfer, array $lines): void
    {
        $normalized = [];

        foreach ($lines as $row) {
            $itemId = (string) $row['item_id'];
            if (isset($normalized[$itemId])) {
                abort(422, 'Duplicate items are not allowed on a transfer.', ['X-Error-Code' => 'STOCK_TRANSFER_DUPLICATE_ITEM']);
            }

            $item = Item::query()->findOrFail($itemId);
            $resolved = StockTransferLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                isset($row['item_uom_id']) ? (int) $row['item_uom_id'] : null
            );

            $normalized[$itemId] = [
                ...$resolved,
                'notes' => $this->normalizeNotes($row['notes'] ?? null),
            ];
        }

        StockTransferLine::query()->where('stock_transfer_id', $transfer->id)->delete();

        foreach ($normalized as $itemId => $line) {
            StockTransferLine::query()->create([
                'stock_transfer_id' => $transfer->id,
                'item_id' => $itemId,
                'quantity' => $line['quantity'],
                'base_quantity' => $line['base_quantity'],
                'item_uom_id' => $line['item_uom_id'],
                'notes' => $line['notes'],
            ]);
        }
    }

    private function lockDraftTransfer(StockTransfer $transfer): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        StockTransferRules::assertDraft($locked);

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
