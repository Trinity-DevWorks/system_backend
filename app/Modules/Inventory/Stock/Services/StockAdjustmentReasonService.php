<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\StockAdjustmentReasonDirection;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class StockAdjustmentReasonService
{
    /**
     * @return Collection<int, StockAdjustmentReason>
     */
    public function list(bool $activeOnly = false): Collection
    {
        $query = StockAdjustmentReason::query()->orderBy('name');
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function paginate(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = StockAdjustmentReason::query()->orderBy('name');

        if ($search) {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{code:string,name:string,direction:string,is_active?:bool,notes?:?string}  $data
     */
    public function create(array $data): StockAdjustmentReason
    {
        return StockAdjustmentReason::query()->create([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'direction' => StockAdjustmentReasonDirection::from($data['direction']),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'is_system' => false,
            'notes' => $this->normalizeNotes($data['notes'] ?? null),
        ]);
    }

    /**
     * @param  array{code?:string,name?:string,direction?:string,is_active?:bool,notes?:?string}  $data
     */
    public function update(StockAdjustmentReason $reason, array $data): StockAdjustmentReason
    {
        $updates = [];

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $updates['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('direction', $data) && $data['direction'] !== null) {
            $updates['direction'] = StockAdjustmentReasonDirection::from((string) $data['direction']);
        }
        if (array_key_exists('is_active', $data)) {
            $updates['is_active'] = (bool) $data['is_active'];
        }
        if (array_key_exists('notes', $data)) {
            $updates['notes'] = $this->normalizeNotes($data['notes']);
        }
        if (! $reason->is_system && array_key_exists('code', $data) && $data['code'] !== null) {
            $updates['code'] = strtoupper(trim((string) $data['code']));
        }

        if ($updates !== []) {
            $reason->update($updates);
        }

        return $reason->refresh();
    }

    public function delete(StockAdjustmentReason $reason): void
    {
        if ($reason->is_system) {
            abort(422, 'System adjustment reasons cannot be deleted.', [
                'X-Error-Code' => 'STOCK_ADJUSTMENT_REASON_SYSTEM',
            ]);
        }

        if ($reason->adjustments()->exists()) {
            abort(422, 'Cannot delete a reason that is used on an adjustment.', [
                'X-Error-Code' => 'STOCK_ADJUSTMENT_REASON_IN_USE',
            ]);
        }

        $reason->delete();
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
