<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Inventory\Stock\Models\InventorySerial;
use App\Modules\Inventory\Stock\Models\InventorySerialMovement;
use App\Support\TenantClock;
use Illuminate\Support\Collection;

final class InventorySerialResponseData
{
    /**
     * @param  Collection<int, InventorySerial>  $serials
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $serials): array
    {
        $refs = [];
        foreach ($serials as $serial) {
            $refs[] = [(string) $serial->received_ref_type, $serial->received_ref_id];
            $refs[] = [(string) $serial->sold_ref_type, $serial->sold_ref_id];
        }
        $numbers = SourceDocuments::numbers($refs);

        return $serials->map(fn (InventorySerial $serial): array => self::row($serial, $numbers))->values()->all();
    }

    /**
     * @param  Collection<int, InventorySerialMovement>  $history
     * @return array<string, mixed>
     */
    public static function detail(InventorySerial $serial, Collection $history): array
    {
        $refs = [
            [(string) $serial->received_ref_type, $serial->received_ref_id],
            [(string) $serial->sold_ref_type, $serial->sold_ref_id],
        ];
        foreach ($history as $move) {
            $refs[] = [(string) $move->reference_type, $move->reference_id];
        }
        $numbers = SourceDocuments::numbers($refs);

        return [
            ...self::row($serial, $numbers),
            'history' => $history->map(fn (InventorySerialMovement $move): array => [
                'id' => (int) $move->id,
                'moved_at' => $move->moved_at->toIso8601String(),
                'movement_type' => $move->movement_type,
                'direction' => $move->direction,
                'is_reversal' => (bool) $move->is_reversal,
                'reversed' => $move->reversed_at !== null,
                'status_after' => $move->status_after,
                'warehouse' => $move->warehouse ? [
                    'id' => (int) $move->warehouse->id,
                    'name' => $move->warehouse->name,
                ] : null,
                'reference' => self::ref($move->reference_type, $move->reference_id, $numbers),
                'user' => $move->user ? ['id' => (string) $move->user->id, 'name' => $move->user->name] : null,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $numbers
     * @return array<string, mixed>
     */
    private static function row(InventorySerial $serial, array $numbers): array
    {
        $warrantyEnds = $serial->warranty_ends_on;

        return [
            'id' => (int) $serial->id,
            'serial_number' => $serial->serial_number,
            'status' => $serial->status,
            'item' => $serial->item ? [
                'id' => (string) $serial->item->id,
                'item_code' => $serial->item->item_code,
                'name' => $serial->item->name,
            ] : null,
            'warehouse' => $serial->warehouse ? [
                'id' => (int) $serial->warehouse->id,
                'name' => $serial->warehouse->name,
            ] : null,
            'supplier' => $serial->supplier ? [
                'id' => (string) $serial->supplier->id,
                'name' => $serial->supplier->name,
                'code' => $serial->supplier->supplier_code,
            ] : null,
            'customer' => $serial->customer ? [
                'id' => (string) $serial->customer->id,
                'name' => $serial->customer->name,
                'code' => $serial->customer->customer_code,
            ] : null,
            'received_at' => $serial->received_at?->toIso8601String(),
            'received_ref' => self::ref($serial->received_ref_type, $serial->received_ref_id, $numbers),
            'sold_at' => $serial->sold_at?->toIso8601String(),
            'sold_ref' => self::ref($serial->sold_ref_type, $serial->sold_ref_id, $numbers),
            'warranty_ends_on' => $warrantyEnds?->toDateString(),
            'in_warranty' => $warrantyEnds !== null
                ? $warrantyEnds->toDateString() >= TenantClock::today()->toDateString()
                : null,
            'notes' => $serial->notes,
            'updated_at' => $serial->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $numbers
     * @return array{type: string, id: string, number: string|null}|null
     */
    private static function ref(?string $type, ?string $id, array $numbers): ?array
    {
        if ($type === null || $type === '' || $id === null || $id === '') {
            return null;
        }

        return ['type' => $type, 'id' => $id, 'number' => $numbers[$type.':'.$id] ?? null];
    }
}
