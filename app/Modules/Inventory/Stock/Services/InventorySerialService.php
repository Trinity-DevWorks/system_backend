<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\InventorySerial;
use App\Modules\Inventory\Stock\Models\InventorySerialMovement;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Support\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Serial numbers move with stock: StockMovementService calls apply() for every movement of a
 * serial-tracked item and reverse() when a document is reversed. Document services only collect
 * the numbers on their lines (forLine) and pass them on the movement (StockMovementData::withSerials).
 */
class InventorySerialService
{
    public const MAX_LENGTH = 100;

    /**
     * Trim, upper-case and drop blanks. Duplicates on one line are refused.
     *
     * @return list<string>
     */
    public static function normalize(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (is_string($input)) {
            $input = preg_split('/[\r\n,;]+/', $input) ?: [];
        }
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach ($input as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $number = mb_strtoupper(trim((string) $value));
            if ($number === '') {
                continue;
            }
            if (mb_strlen($number) > self::MAX_LENGTH) {
                self::abortWith('Serial number '.$number.' is too long (max '.self::MAX_LENGTH.' characters).', 'STOCK_SERIAL_TOO_LONG', ['serial' => $number]);
            }
            if (isset($out[$number])) {
                self::abortWith('Serial number '.$number.' is entered twice.', 'STOCK_SERIAL_DUPLICATE', ['serial' => $number]);
            }
            $out[$number] = true;
        }

        return array_keys($out);
    }

    /**
     * Serials for a draft line: null when the item doesn't track serials. A draft may hold fewer
     * serials than its quantity (they can be scanned later); posting needs the full count.
     *
     * @return list<string>|null
     */
    public function forLine(Item $item, mixed $input, string|int|float $baseQuantity, ?int $lineNumber = null): ?array
    {
        $numbers = self::normalize($input);
        if (! $item->track_serials) {
            if ($numbers !== []) {
                $this->fail($lineNumber, $item->name.' does not track serial numbers.', 'STOCK_SERIAL_NOT_TRACKED', ['item' => $item->name]);
            }

            return null;
        }

        $quantity = $this->wholeQuantity($item, ltrim((string) $baseQuantity, '-'), $lineNumber);
        if (count($numbers) > $quantity) {
            $this->fail(
                $lineNumber,
                $item->name.': '.count($numbers).' serial numbers for a quantity of '.$quantity.'.',
                'STOCK_SERIAL_COUNT_MISMATCH',
                ['item' => $item->name, 'needed' => (string) $quantity, 'entered' => (string) count($numbers)],
            );
        }

        return $numbers;
    }

    /**
     * Called by StockMovementService::post right after the ledger row is written.
     */
    public function apply(StockMovement $movement, Item $item, StockMovementData $data): void
    {
        if (! $item->track_serials) {
            if ($data->serialNumbers !== []) {
                self::abortWith($item->name.' does not track serial numbers.', 'STOCK_SERIAL_NOT_TRACKED', ['item' => $item->name]);
            }

            return;
        }

        $inbound = bccomp($data->quantityDelta, '0', 6) > 0;
        $quantity = $this->wholeQuantity($item, ltrim($data->quantityDelta, '-'), null);
        $numbers = self::normalize($data->serialNumbers);
        if (count($numbers) !== $quantity) {
            self::abortWith(
                $item->name.' needs '.$quantity.' serial number(s); '.count($numbers).' entered.',
                'STOCK_SERIAL_COUNT_MISMATCH',
                ['item' => $item->name, 'needed' => (string) $quantity, 'entered' => (string) count($numbers)],
            );
        }

        /** @var Collection<string, InventorySerial> $existing */
        $existing = InventorySerial::query()
            ->where('item_id', $item->id)
            ->whereIn('serial_number', $numbers)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial_number');

        $inbound
            ? $this->assertCanReceive($item, $numbers, $existing, $data->type)
            : $this->assertCanIssue($item, $numbers, $existing, (int) $data->warehouseId);

        $now = now();
        foreach ($numbers as $number) {
            $serial = $existing->get($number) ?? new InventorySerial([
                'item_id' => $item->id,
                'serial_number' => $number,
            ]);
            $statusBefore = $serial->exists ? $serial->status : null;
            $warehouseBefore = $serial->exists && $serial->warehouse_id !== null ? (int) $serial->warehouse_id : null;

            if ($inbound) {
                $this->receive($serial, $data, $now);
            } else {
                $this->issue($serial, $item, $data, $now);
            }
            $serial->save();

            InventorySerialMovement::query()->create([
                'inventory_serial_id' => $serial->id,
                'stock_movement_id' => $movement->id,
                'reference_type' => $data->referenceType,
                'reference_id' => $data->referenceId,
                'movement_type' => $data->type->value,
                'direction' => $inbound ? 'in' : 'out',
                'is_reversal' => false,
                'warehouse_id' => $data->warehouseId,
                'status_before' => $statusBefore,
                'warehouse_before_id' => $warehouseBefore,
                'status_after' => $serial->status,
                'party_type' => $data->partyType,
                'party_id' => $data->partyId,
                'user_id' => $data->userId,
                'moved_at' => $now,
            ]);
        }
    }

    /**
     * Refuse to reverse a movement whose serials have moved since (checked before the stock check,
     * so the message names the unit).
     */
    public function assertReversible(StockMovement $original): void
    {
        $rows = InventorySerialMovement::query()
            ->where('stock_movement_id', $original->id)
            ->where('is_reversal', false)
            ->whereNull('reversed_at')
            ->with('serial:id,serial_number')
            ->get();
        foreach ($rows as $row) {
            $latestId = $this->latestStandingMoveId((int) $row->inventory_serial_id);
            if ($latestId !== (int) $row->id) {
                $number = (string) $row->serial?->serial_number;
                self::abortWith(
                    'Serial number '.$number.' has moved since this document was posted. Reverse the later document first.',
                    'STOCK_SERIAL_MOVED_SINCE',
                    ['serial' => $number],
                );
            }
        }
    }

    /**
     * Undo what $original did to its serials. Refused when a unit has moved since
     * (for example: reversing a receipt whose unit was already sold).
     */
    public function reverse(StockMovement $original, StockMovement $reverse): void
    {
        $rows = InventorySerialMovement::query()
            ->where('stock_movement_id', $original->id)
            ->where('is_reversal', false)
            ->whereNull('reversed_at')
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        $serials = InventorySerial::query()
            ->whereIn('id', $rows->pluck('inventory_serial_id')->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $now = now();
        foreach ($rows as $row) {
            /** @var InventorySerial $serial */
            $serial = $serials->get($row->inventory_serial_id);
            $latestId = $this->latestStandingMoveId((int) $serial->id);
            if ($latestId !== (int) $row->id) {
                self::abortWith(
                    'Serial number '.$serial->serial_number.' has moved since this document was posted. Reverse the later document first.',
                    'STOCK_SERIAL_MOVED_SINCE',
                    ['serial' => $serial->serial_number],
                );
            }

            $statusNow = $serial->status;
            $warehouseNow = $serial->warehouse_id !== null ? (int) $serial->warehouse_id : null;
            $serial->status = $row->status_before ?? InventorySerial::STATUS_VOID;
            $serial->warehouse_id = $row->warehouse_before_id;
            $row->update(['reversed_at' => $now]);
            $this->refreshSaleAndReceipt($serial);
            $serial->save();

            InventorySerialMovement::query()->create([
                'inventory_serial_id' => $serial->id,
                'stock_movement_id' => $reverse->id,
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id,
                'movement_type' => $row->movement_type,
                'direction' => $row->direction === 'in' ? 'out' : 'in',
                'is_reversal' => true,
                'warehouse_id' => $row->warehouse_id,
                'status_before' => $statusNow,
                'warehouse_before_id' => $warehouseNow,
                'status_after' => $serial->status,
                'party_type' => $row->party_type,
                'party_id' => $row->party_id,
                'user_id' => $reverse->user_id,
                'moved_at' => $now,
            ]);
        }
    }

    /**
     * Units of a transfer line that are still on their way (not received, returned or written off).
     *
     * @param  list<string>|null  $lineSerials
     * @return list<string>
     */
    public function stillInTransit(string $itemId, ?array $lineSerials): array
    {
        if ($lineSerials === null || $lineSerials === []) {
            return [];
        }

        return InventorySerial::query()
            ->where('item_id', $itemId)
            ->whereIn('serial_number', $lineSerials)
            ->where('status', InventorySerial::STATUS_IN_TRANSIT)
            ->orderBy('serial_number')
            ->pluck('serial_number')
            ->map(fn ($n): string => (string) $n)
            ->values()
            ->all();
    }

    /**
     * Serials for part of a transfer line (receive / return / write off). With none given and the
     * quantity covering every unit still in transit, those units are used.
     *
     * @return list<string>|null
     */
    public function pickFromTransit(Item $item, ?array $lineSerials, mixed $input, string $baseQuantity): ?array
    {
        if (! $item->track_serials) {
            return null;
        }
        $remaining = $this->stillInTransit((string) $item->id, $lineSerials);
        $chosen = self::normalize($input);
        $quantity = $this->wholeQuantity($item, ltrim($baseQuantity, '-'), null);
        if ($chosen === [] && count($remaining) === $quantity) {
            return $remaining;
        }
        if (count($chosen) !== $quantity) {
            self::abortWith(
                $item->name.' needs '.$quantity.' serial number(s); '.count($chosen).' entered.',
                'STOCK_SERIAL_COUNT_MISMATCH',
                ['item' => $item->name, 'needed' => (string) $quantity, 'entered' => (string) count($chosen)],
            );
        }
        self::assertSubset($remaining, $chosen, $item->name);

        return $chosen;
    }

    /**
     * Units lost on the way (transfer closed with a write-off): no stock movement, so the serials
     * are marked written off here, with their own history row.
     *
     * @param  list<string>  $numbers
     */
    public function writeOffInTransit(Item $item, array $numbers, string $referenceType, string $referenceId, ?string $userId): void
    {
        if ($numbers === []) {
            return;
        }
        $serials = InventorySerial::query()
            ->where('item_id', $item->id)
            ->whereIn('serial_number', $numbers)
            ->lockForUpdate()
            ->get();
        $now = now();
        foreach ($serials as $serial) {
            if ($serial->status !== InventorySerial::STATUS_IN_TRANSIT) {
                continue;
            }
            $warehouseBefore = $serial->warehouse_id !== null ? (int) $serial->warehouse_id : null;
            $serial->status = InventorySerial::STATUS_SCRAPPED;
            $serial->save();
            InventorySerialMovement::query()->create([
                'inventory_serial_id' => $serial->id,
                'stock_movement_id' => null,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'movement_type' => 'transfer_write_off',
                'direction' => 'out',
                'is_reversal' => false,
                'warehouse_id' => $warehouseBefore,
                'status_before' => InventorySerial::STATUS_IN_TRANSIT,
                'warehouse_before_id' => $warehouseBefore,
                'status_after' => InventorySerial::STATUS_SCRAPPED,
                'user_id' => $userId,
                'moved_at' => $now,
            ]);
        }
    }

    /**
     * Serials posted on a document line, for a credit note to choose from.
     *
     * @param  list<string>  $allowed  the serials of the source line
     * @param  list<string>  $chosen
     */
    public static function assertSubset(array $allowed, array $chosen, string $itemName, ?int $lineNumber = null): void
    {
        $missing = array_values(array_diff($chosen, $allowed));
        if ($missing !== []) {
            $prefix = $lineNumber !== null ? 'Line '.$lineNumber.': ' : '';
            self::abortWith(
                $prefix.implode(', ', $missing).' was not on the original line for '.$itemName.'.',
                'STOCK_SERIAL_NOT_ON_SOURCE',
                ['item' => $itemName, 'serials' => implode(', ', $missing)],
            );
        }
    }

    /**
     * @param  list<string>  $numbers
     * @param  Collection<string, InventorySerial>  $existing
     */
    private function assertCanReceive(Item $item, array $numbers, Collection $existing, StockMovementType $type): void
    {
        // A transfer brings in only units that are on their way (shipped by that transfer).
        if (in_array($type, [StockMovementType::TransferIn, StockMovementType::TransferReturn], true)) {
            $notInTransit = [];
            foreach ($numbers as $number) {
                $serial = $existing->get($number);
                if ($serial === null || $serial->status !== InventorySerial::STATUS_IN_TRANSIT) {
                    $notInTransit[] = $number;
                }
            }
            if ($notInTransit !== []) {
                self::abortWith(
                    $item->name.': '.implode(', ', $notInTransit).' '.(count($notInTransit) === 1 ? 'is' : 'are').' not in transit.',
                    'STOCK_SERIAL_NOT_IN_TRANSIT',
                    ['item' => $item->name, 'serials' => implode(', ', $notInTransit)],
                );
            }

            return;
        }

        $taken = [];
        foreach ($numbers as $number) {
            $serial = $existing->get($number);
            if ($serial !== null && in_array($serial->status, [InventorySerial::STATUS_IN_STOCK, InventorySerial::STATUS_IN_TRANSIT], true)) {
                $taken[] = $number;
            }
        }
        if ($taken !== []) {
            self::abortWith(
                $item->name.': '.implode(', ', $taken).' '.(count($taken) === 1 ? 'is' : 'are').' already in stock.',
                'STOCK_SERIAL_ALREADY_IN_STOCK',
                ['item' => $item->name, 'serials' => implode(', ', $taken)],
            );
        }
    }

    /**
     * @param  list<string>  $numbers
     * @param  Collection<string, InventorySerial>  $existing
     */
    private function assertCanIssue(Item $item, array $numbers, Collection $existing, int $warehouseId): void
    {
        $missing = [];
        foreach ($numbers as $number) {
            $serial = $existing->get($number);
            if ($serial === null
                || $serial->status !== InventorySerial::STATUS_IN_STOCK
                || (int) $serial->warehouse_id !== $warehouseId) {
                $missing[] = $number;
            }
        }
        if ($missing !== []) {
            self::abortWith(
                $item->name.': '.implode(', ', $missing).' '.(count($missing) === 1 ? 'is' : 'are').' not in stock in this warehouse.',
                'STOCK_SERIAL_NOT_AVAILABLE',
                ['item' => $item->name, 'serials' => implode(', ', $missing)],
            );
        }
    }

    private function receive(InventorySerial $serial, StockMovementData $data, Carbon $now): void
    {
        $firstReceipt = ! $serial->exists || $serial->status === InventorySerial::STATUS_VOID;
        $wasSold = $serial->exists && $serial->status === InventorySerial::STATUS_SOLD;
        $serial->status = InventorySerial::STATUS_IN_STOCK;
        $serial->warehouse_id = $data->warehouseId;
        if ($wasSold) {
            // Back in stock: the sale stays in the history, not on the unit (reversing the return restores it).
            $this->clearSale($serial);
        }

        if ($firstReceipt || $data->type === StockMovementType::Purchase) {
            $serial->received_at = $now;
            $serial->received_ref_type = $data->referenceType;
            $serial->received_ref_id = $data->referenceId;
            if ($data->partyType === 'supplier') {
                $serial->supplier_id = $data->partyId;
            }
        }
    }

    private function issue(InventorySerial $serial, Item $item, StockMovementData $data, Carbon $now): void
    {
        // warehouse_id stays: the last warehouse the unit was in (status says it left).
        $serial->status = match ($data->type) {
            StockMovementType::Sale, StockMovementType::BundleSale => InventorySerial::STATUS_SOLD,
            StockMovementType::PurchaseReturn => InventorySerial::STATUS_RETURNED_TO_SUPPLIER,
            StockMovementType::ProductionOut => InventorySerial::STATUS_CONSUMED,
            StockMovementType::TransferOut => InventorySerial::STATUS_IN_TRANSIT,
            default => InventorySerial::STATUS_SCRAPPED,
        };

        if ($serial->status === InventorySerial::STATUS_SOLD) {
            $serial->sold_at = $now;
            $serial->sold_ref_type = $data->referenceType;
            $serial->sold_ref_id = $data->referenceId;
            if ($data->partyType === 'customer') {
                $serial->customer_id = $data->partyId;
            }
            $months = $item->warranty_months !== null ? (int) $item->warranty_months : 0;
            $serial->warranty_ends_on = $months > 0 ? TenantClock::today()->addMonthsNoOverflow($months) : null;
        }
    }

    /**
     * After a reversal, sale and receipt details come from the latest moves still standing.
     */
    private function refreshSaleAndReceipt(InventorySerial $serial): void
    {
        $standing = InventorySerialMovement::query()
            ->where('inventory_serial_id', $serial->id)
            ->where('is_reversal', false)
            ->whereNull('reversed_at');

        // Only a sold unit carries its sale; a unit back in stock keeps it in the history only.
        $sale = $serial->status === InventorySerial::STATUS_SOLD
            ? (clone $standing)
                ->where('direction', 'out')
                ->whereIn('movement_type', [StockMovementType::Sale->value, StockMovementType::BundleSale->value])
                ->orderByDesc('id')
                ->first()
            : null;
        if ($sale === null) {
            $this->clearSale($serial);
        } else {
            $sameSale = $serial->sold_ref_type === $sale->reference_type && (string) $serial->sold_ref_id === (string) $sale->reference_id;
            $serial->sold_at = $sale->moved_at;
            $serial->sold_ref_type = $sale->reference_type;
            $serial->sold_ref_id = $sale->reference_id;
            $serial->customer_id = $sale->party_type === 'customer' ? $sale->party_id : $serial->customer_id;
            if (! $sameSale || $serial->warranty_ends_on === null) {
                $months = (int) (Item::query()->whereKey($serial->item_id)->value('warranty_months') ?? 0);
                $serial->warranty_ends_on = $months > 0 ? Carbon::parse($sale->moved_at)->addMonthsNoOverflow($months) : null;
            }
        }

        $receipt = (clone $standing)->where('direction', 'in')->orderBy('id')->first();
        if ($receipt === null) {
            $serial->received_at = null;
            $serial->received_ref_type = null;
            $serial->received_ref_id = null;
            $serial->supplier_id = null;
        }
    }

    private function clearSale(InventorySerial $serial): void
    {
        $serial->customer_id = null;
        $serial->sold_at = null;
        $serial->sold_ref_type = null;
        $serial->sold_ref_id = null;
        $serial->warranty_ends_on = null;
    }

    /** Latest move of a unit that is not a reversal and has not been reversed. */
    private function latestStandingMoveId(int $serialId): int
    {
        return (int) InventorySerialMovement::query()
            ->where('inventory_serial_id', $serialId)
            ->where('is_reversal', false)
            ->whereNull('reversed_at')
            ->max('id');
    }

    private function wholeQuantity(Item $item, string $quantity, ?int $lineNumber): int
    {
        $whole = bcadd($quantity, '0', 0);
        if (bccomp($quantity, $whole, 6) !== 0) {
            $this->fail($lineNumber, $item->name.' is tracked by serial number, so its quantity must be a whole number.', 'STOCK_SERIAL_WHOLE_QUANTITY', ['item' => $item->name]);
        }

        return (int) $whole;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function fail(?int $lineNumber, string $message, string $code, array $params): never
    {
        $prefix = $lineNumber !== null ? 'Line '.$lineNumber.': ' : '';
        self::abortWith($prefix.$message, $code, $params);
    }

    /**
     * 422 with an error code and the values its translated message needs (X-Error-Params).
     *
     * @param  array<string, string>  $params
     */
    private static function abortWith(string $message, string $code, array $params): never
    {
        abort(422, $message, [
            'X-Error-Code' => $code,
            'X-Error-Params' => (string) json_encode($params),
        ]);
    }
}
