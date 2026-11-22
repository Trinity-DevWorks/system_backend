<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Services;

use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\PriceChange\Enums\PriceChangeSource;
use App\Modules\Inventory\PriceChange\Enums\PriceField;
use App\Modules\Inventory\PriceChange\Models\ItemPriceChange;
use App\Modules\Inventory\PriceChange\Support\PriceChangeMath;
use App\Support\Decimal;
use Illuminate\Support\Carbon;

/**
 * Writes item_price_changes. Callers (item units, price lists, variant generation, and a future
 * item import) pass a source; a row is stored only when the value really changed.
 */
final class PriceHistoryRecorder
{
    /**
     * @param  array<string, mixed>|null  $before  previous field values; null when the unit row is new
     * @param  array<string, mixed>  $context
     */
    public function recordItemUom(ItemUom $row, ?array $before, array $context = []): void
    {
        foreach (PriceField::itemUomValues() as $field) {
            $old = $before === null ? null : $this->money($before[$field] ?? null);
            $new = $this->money($row->getAttribute($field));
            if ($this->same($old, $new)) {
                continue;
            }
            $this->write(
                itemId: (string) $row->item_id,
                itemUomId: (int) $row->id,
                priceListId: null,
                field: $field,
                minQuantity: null,
                old: $old,
                new: $new,
                context: $context,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordPriceListItem(
        string $itemId,
        int $itemUomId,
        int $priceListId,
        string $minQuantity,
        ?string $old,
        ?string $new,
        array $context = [],
    ): void {
        $old = $this->money($old);
        $new = $this->money($new);
        if ($this->same($old, $new)) {
            return;
        }

        $this->write(
            itemId: $itemId,
            itemUomId: $itemUomId,
            priceListId: $priceListId,
            field: PriceField::PriceList->value,
            minQuantity: Decimal::round($minQuantity, 6),
            old: $old,
            new: $new,
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{source: string, source_id: string|null, reason: string|null, effective_at: string|null, changed_by: string|null}
     */
    public static function contextFrom(array $data, string $defaultSource = 'manual'): array
    {
        $source = (string) ($data['history_source'] ?? $defaultSource);
        if (! in_array($source, PriceChangeSource::values(), true)) {
            $source = $defaultSource;
        }
        $sourceId = $data['history_source_id'] ?? null;
        $changedBy = $data['history_changed_by'] ?? null;
        $effective = $data['history_effective_at'] ?? null;

        return [
            'source' => $source,
            'source_id' => is_scalar($sourceId) && (string) $sourceId !== '' ? (string) $sourceId : null,
            'reason' => self::reason($data['reason'] ?? null),
            'effective_at' => is_scalar($effective) && (string) $effective !== '' ? (string) $effective : null,
            'changed_by' => is_scalar($changedBy) && (string) $changedBy !== '' ? (string) $changedBy : null,
        ];
    }

    public static function reason(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $reason = trim((string) $value);

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(
        string $itemId,
        ?int $itemUomId,
        ?int $priceListId,
        string $field,
        ?string $minQuantity,
        ?string $old,
        ?string $new,
        array $context,
    ): void {
        $resolved = $context === [] ? self::contextFrom([]) : $context;
        $changedBy = $resolved['changed_by'] ?? null;
        if (! is_string($changedBy) || $changedBy === '') {
            $authId = auth()->id();
            $changedBy = $authId !== null ? (string) $authId : null;
        }

        ItemPriceChange::query()->create([
            'item_id' => $itemId,
            'item_uom_id' => $itemUomId,
            'price_list_id' => $priceListId,
            'field' => $field,
            'min_quantity' => $minQuantity,
            'old_value' => $old,
            'new_value' => $new,
            'change_percent' => PriceChangeMath::changePercent($old, $new),
            'source' => $resolved['source'] ?? PriceChangeSource::Manual->value,
            'source_id' => $resolved['source_id'] ?? null,
            'reason' => self::reason($resolved['reason'] ?? null),
            'effective_at' => $this->effectiveAt(isset($resolved['effective_at']) && is_string($resolved['effective_at']) ? $resolved['effective_at'] : null),
            'changed_by' => $changedBy,
        ]);
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Decimal::round($value, 6);
    }

    private function same(?string $left, ?string $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            return false;
        }

        return Decimal::cmp($left, $right, 6) === 0;
    }

    private function effectiveAt(?string $value): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }
        $parsed = Carbon::parse($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $parsed->startOfDay();
        }

        return $parsed;
    }
}
