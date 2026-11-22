<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Services;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Category\Models\Category;
use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Services\ItemUomService;
use App\Modules\Inventory\PriceChange\Enums\PriceChangeSource;
use App\Modules\Inventory\PriceChange\Enums\PriceChangeStatus;
use App\Modules\Inventory\PriceChange\Enums\PriceField;
use App\Modules\Inventory\PriceChange\Models\PriceChange;
use App\Modules\Inventory\PriceChange\Models\PriceChangeLine;
use App\Modules\Inventory\PriceChange\Support\PriceChangeMath;
use App\Modules\Sales\PriceList\Models\PriceList;
use App\Modules\Sales\PriceList\Models\PriceListItem;
use App\Services\PermissionService;
use App\Support\Decimal;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ListPagination;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PriceChangeService
{
    public function __construct(
        private readonly ItemUomService $itemUoms,
        private readonly PriceHistoryRecorder $history,
        private readonly DocumentNumberService $numbers,
        private readonly FiscalCalendar $calendar,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @param  array{search?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, PriceChange>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = PriceChange::query()->withCount('lines')->orderByDesc('effective_date')->orderByDesc('created_at');
        if (! empty($filters['status']) && in_array($filters['status'], PriceChangeStatus::values(), true)) {
            $query->where('status', $filters['status']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['number', 'notes']);

        return $query->paginate($perPage);
    }

    public function find(string $id): PriceChange
    {
        return PriceChange::query()
            ->with([
                'lines.item:id,item_code,name',
                'lines.itemUom.uom:id,code,name',
                'lines.priceList:id,code,name,currency_id',
                'applier:id,name',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PriceChange
    {
        return DB::transaction(function () use ($data): PriceChange {
            $doc = PriceChange::query()->create([
                'effective_date' => $data['effective_date'],
                'status' => PriceChangeStatus::Draft,
                'notes' => $this->notes($data['notes'] ?? null),
                'created_by' => $this->userId(),
            ]);
            $this->numbers->assign($doc, 'number', DocumentType::PriceChange, null, $doc->effective_date);
            if (array_key_exists('lines', $data)) {
                $this->replaceLines($doc, (array) $data['lines']);
            }

            return $this->find((string) $doc->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PriceChange $doc, array $data): PriceChange
    {
        return DB::transaction(function () use ($doc, $data): PriceChange {
            $locked = $this->lockDraft($doc);
            $locked->update([
                'effective_date' => $data['effective_date'] ?? $locked->effective_date,
                'notes' => array_key_exists('notes', $data) ? $this->notes($data['notes']) : $locked->notes,
            ]);
            if (array_key_exists('lines', $data)) {
                $this->replaceLines($locked, (array) $data['lines']);
            }

            return $this->find((string) $locked->id);
        });
    }

    public function delete(PriceChange $doc): void
    {
        DB::transaction(function () use ($doc): void {
            $locked = $this->lockDraft($doc);
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{lines: list<array<string, mixed>>, below_cost: list<array<string, mixed>>}
     */
    public function previewBulk(array $filters): array
    {
        $field = PriceField::tryFrom((string) ($filters['field'] ?? ''));
        $rule = (string) ($filters['rule'] ?? '');
        $rounding = (string) ($filters['rounding'] ?? 'none');
        if ($field === null || ! in_array($rule, PriceChangeMath::RULES, true) || ! in_array($rounding, PriceChangeMath::ROUNDINGS, true)) {
            abort(422, 'The bulk rule is invalid.', ['X-Error-Code' => 'PRICE_CHANGE_BULK_INVALID']);
        }
        if ($field === PriceField::PriceList && empty($filters['price_list_id'])) {
            abort(422, 'Choose a price list to change its prices.', ['X-Error-Code' => 'PRICE_CHANGE_BULK_LIST_REQUIRED']);
        }

        $value = Decimal::of($filters['value'] ?? 0);
        $itemIds = $this->matchingItemIds($filters);
        $lines = $field === PriceField::PriceList
            ? $this->bulkListLines((int) $filters['price_list_id'], $itemIds, $rule, $value, $rounding)
            : $this->bulkUomLines($itemIds, $field, $rule, $value, $rounding);
        $decorated = $this->decorate($lines);

        return [
            'lines' => $decorated,
            'below_cost' => $this->belowCostRows($decorated),
        ];
    }

    /**
     * Apply now when the effective date is today or earlier; otherwise mark the document scheduled.
     * Already-applied documents are returned unchanged.
     *
     * @return array{document: PriceChange, below_cost: list<array<string, mixed>>, changed_item_ids: list<string>}
     */
    public function apply(PriceChange $doc, ?string $asOf = null): array
    {
        return DB::transaction(function () use ($doc, $asOf): array {
            $locked = PriceChange::query()->whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PriceChangeStatus::Applied) {
                $found = $this->find((string) $locked->id);

                return [
                    'document' => $found,
                    'below_cost' => [],
                    'changed_item_ids' => $found->lines->pluck('item_id')->unique()->values()->all(),
                ];
            }
            if (! in_array($locked->status, [PriceChangeStatus::Draft, PriceChangeStatus::Scheduled], true)) {
                abort(422, 'Only a draft or a scheduled price change can be applied.', ['X-Error-Code' => 'PRICE_CHANGE_NOT_OPEN']);
            }
            $locked->load('lines');
            if ($locked->lines->isEmpty()) {
                abort(422, 'Add at least one price before applying.', ['X-Error-Code' => 'PRICE_CHANGE_EMPTY']);
            }

            $draftLines = $locked->lines->map(fn (PriceChangeLine $line): array => [
                'item_id' => (string) $line->item_id,
                'item_uom_id' => $line->item_uom_id !== null ? (int) $line->item_uom_id : null,
                'price_list_id' => $line->price_list_id !== null ? (int) $line->price_list_id : null,
                'min_quantity' => $line->min_quantity !== null ? (string) $line->min_quantity : null,
                'field' => $line->field->value,
                'old_value' => $line->old_value !== null ? (string) $line->old_value : null,
                'new_value' => (string) $line->new_value,
            ])->all();
            $decorated = $this->decorate($draftLines);
            $warnings = $this->belowCostRows($decorated);
            // The permission is checked when a user applies or schedules the draft. A scheduled
            // document cannot be edited, so the nightly run (no user) applies what was approved.
            if ($warnings !== [] && $locked->status === PriceChangeStatus::Draft && ! $this->canPriceBelowCost()) {
                throw new HttpResponseException(ApiResponse::error(
                    'Some prices are below cost. A manager with the below-cost permission has to apply them.',
                    422,
                    ['below_cost' => $warnings],
                    [],
                    null,
                    null,
                    'PRICE_BELOW_COST',
                ));
            }

            $today = $asOf ?? $this->calendar->today();
            if ($locked->effective_date->toDateString() > $today) {
                $locked->update(['status' => PriceChangeStatus::Scheduled]);

                return [
                    'document' => $this->find((string) $locked->id),
                    'below_cost' => $warnings,
                    'changed_item_ids' => [],
                ];
            }

            $this->writePrices($locked);
            $locked->update([
                'status' => PriceChangeStatus::Applied,
                'applied_by' => $this->userId() ?? $locked->created_by,
                'applied_at' => now(),
            ]);
            $found = $this->find((string) $locked->id);

            return [
                'document' => $found,
                'below_cost' => $warnings,
                'changed_item_ids' => $found->lines->pluck('item_id')->unique()->values()->all(),
            ];
        });
    }

    public function cancel(PriceChange $doc): PriceChange
    {
        return DB::transaction(function () use ($doc): PriceChange {
            $locked = PriceChange::query()->whereKey($doc->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [PriceChangeStatus::Draft, PriceChangeStatus::Scheduled], true)) {
                abort(422, 'Only a draft or a scheduled price change can be cancelled.', ['X-Error-Code' => 'PRICE_CHANGE_NOT_CANCELLABLE']);
            }
            $locked->update([
                'status' => PriceChangeStatus::Cancelled,
                'cancelled_by' => $this->userId(),
                'cancelled_at' => now(),
            ]);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * @return array{applied: int, skipped: int, date: string}
     */
    public function applyScheduled(?string $date = null): array
    {
        $date ??= $this->calendar->today();
        $applied = 0;
        $skipped = 0;
        $ids = PriceChange::query()
            ->where('status', PriceChangeStatus::Scheduled)
            ->whereDate('effective_date', '<=', $date)
            ->orderBy('effective_date')
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $doc = PriceChange::query()->find($id);
                if ($doc === null || $doc->status !== PriceChangeStatus::Scheduled) {
                    continue;
                }
                $result = $this->apply($doc, $date);
                if ($result['document']->status === PriceChangeStatus::Applied) {
                    $applied++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $exception) {
                $skipped++;
                Log::warning('Scheduled price change could not be applied', ['price_change_id' => (string) $id, 'error' => $exception->getMessage()]);
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'date' => $date];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PriceChange $doc, array $extra = []): array
    {
        $raw = $doc->lines->map(fn (PriceChangeLine $line): array => [
            'id' => (int) $line->id,
            'item_id' => (string) $line->item_id,
            'item_uom_id' => $line->item_uom_id !== null ? (int) $line->item_uom_id : null,
            'price_list_id' => $line->price_list_id !== null ? (int) $line->price_list_id : null,
            'min_quantity' => $line->min_quantity !== null ? (string) $line->min_quantity : null,
            'field' => $line->field instanceof PriceField ? $line->field->value : (string) $line->field,
            'old_value' => $line->old_value !== null ? (string) $line->old_value : null,
            'new_value' => (string) $line->new_value,
            'sort_order' => (int) $line->sort_order,
        ])->all();
        $lines = $this->decorate($raw, $doc);

        return array_merge([
            'id' => (string) $doc->id,
            'number' => $doc->number,
            'effective_date' => $doc->effective_date->toDateString(),
            'status' => $doc->status->value,
            'notes' => $doc->notes,
            'lines_count' => $doc->lines_count ?? count($lines),
            'created_by' => $doc->created_by,
            'applied_by' => $doc->applied_by,
            // Who applied it, for the drawer footer.
            'applied_by_user' => $doc->applied_by !== null && $doc->loadMissing('applier:id,name')->applier !== null
                ? ['id' => (string) $doc->applier->id, 'name' => $doc->applier->name]
                : null,
            'applied_at' => $doc->applied_at?->toIso8601String(),
            'created_at' => $doc->created_at?->toIso8601String(),
            'lines' => $lines,
            'below_cost' => $this->belowCostRows($lines),
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentList(PriceChange $doc): array
    {
        return [
            'id' => (string) $doc->id,
            'number' => $doc->number,
            'effective_date' => $doc->effective_date->toDateString(),
            'status' => $doc->status->value,
            'notes' => $doc->notes,
            'lines_count' => (int) ($doc->lines_count ?? 0),
            'created_at' => $doc->created_at?->toIso8601String(),
        ];
    }

    private function lockDraft(PriceChange $doc): PriceChange
    {
        $locked = PriceChange::query()->whereKey($doc->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== PriceChangeStatus::Draft) {
            abort(422, 'Only a draft price change can be edited.', ['X-Error-Code' => 'PRICE_CHANGE_NOT_DRAFT']);
        }

        return $locked;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(PriceChange $doc, array $lines): void
    {
        $normalized = $this->normalizeLines($lines);
        $doc->lines()->delete();
        foreach ($normalized as $index => $line) {
            PriceChangeLine::query()->create([
                'price_change_id' => $doc->id,
                'item_id' => $line['item_id'],
                'item_uom_id' => $line['item_uom_id'],
                'price_list_id' => $line['price_list_id'],
                'min_quantity' => $line['min_quantity'],
                'field' => $line['field'],
                'old_value' => $line['old_value'],
                'new_value' => $line['new_value'],
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(array $lines): array
    {
        $out = [];
        $seen = [];
        foreach (array_values($lines) as $index => $line) {
            $field = PriceField::tryFrom((string) ($line['field'] ?? ''));
            $itemId = (string) ($line['item_id'] ?? '');
            $uomId = isset($line['item_uom_id']) ? (int) $line['item_uom_id'] : 0;
            $label = 'Line '.($index + 1);
            if ($field === null || $itemId === '' || $uomId <= 0) {
                abort(422, $label.': choose an item, a unit and a price field.', ['X-Error-Code' => 'PRICE_CHANGE_LINE_INVALID']);
            }
            $uom = ItemUom::query()->find($uomId);
            if ($uom === null || (string) $uom->item_id !== $itemId) {
                abort(422, $label.': the unit does not belong to this item.', ['X-Error-Code' => 'PRICE_CHANGE_LINE_INVALID']);
            }
            $new = PriceMath::normalize($line['new_value'] ?? null);
            if (Decimal::isNegative($new)) {
                abort(422, $label.': the new price cannot be negative.', ['X-Error-Code' => 'PRICE_CHANGE_LINE_INVALID']);
            }

            $priceListId = null;
            $minQuantity = null;
            $old = null;
            if ($field === PriceField::PriceList) {
                $priceListId = isset($line['price_list_id']) ? (int) $line['price_list_id'] : 0;
                $minQuantity = Decimal::round($line['min_quantity'] ?? 0, 6);
                if ($priceListId <= 0) {
                    abort(422, $label.': a price list row needs its list.', ['X-Error-Code' => 'PRICE_CHANGE_LINE_INVALID']);
                }
                $row = PriceListItem::query()
                    ->where('price_list_id', $priceListId)
                    ->where('item_id', $itemId)
                    ->where('item_uom_id', $uomId)
                    ->where('min_quantity', $minQuantity)
                    ->first();
                if ($row === null) {
                    abort(422, $label.': that price list row does not exist.', ['X-Error-Code' => 'PRICE_CHANGE_ROW_MISSING']);
                }
                $old = $this->money($row->price);
            } else {
                $old = $this->money($uom->getAttribute($field->value));
            }

            $key = $field->value.'|'.$itemId.'|'.$uomId.'|'.($priceListId ?? '').'|'.($minQuantity ?? '');
            if (isset($seen[$key])) {
                abort(422, $label.': this price appears twice.', ['X-Error-Code' => 'PRICE_CHANGE_LINE_INVALID']);
            }
            $seen[$key] = true;
            $out[] = [
                'item_id' => $itemId,
                'item_uom_id' => $uomId,
                'price_list_id' => $priceListId,
                'min_quantity' => $minQuantity,
                'field' => $field->value,
                'old_value' => $old,
                'new_value' => $new,
            ];
        }

        return $out;
    }

    private function writePrices(PriceChange $doc): void
    {
        $context = [
            'source' => PriceChangeSource::PriceChangeDoc->value,
            'source_id' => (string) $doc->id,
            'reason' => $doc->notes,
            'effective_at' => $doc->effective_date->toDateString(),
            'changed_by' => $this->userId() ?? $doc->created_by,
        ];
        foreach ($doc->lines as $line) {
            $item = Item::query()->findOrFail($line->item_id);
            if ($line->field === PriceField::PriceList) {
                $row = PriceListItem::query()
                    ->where('price_list_id', $line->price_list_id)
                    ->where('item_id', $line->item_id)
                    ->where('item_uom_id', $line->item_uom_id)
                    ->where('min_quantity', Decimal::round((string) $line->min_quantity, 6))
                    ->first();
                if ($row === null) {
                    abort(422, 'A price list row on this document no longer exists.', ['X-Error-Code' => 'PRICE_CHANGE_ROW_MISSING']);
                }
                $old = $this->money($row->price);
                $new = PriceMath::normalize($line->new_value);
                $row->update(['price' => $new]);
                $this->history->recordPriceListItem(
                    (string) $row->item_id,
                    (int) $row->item_uom_id,
                    (int) $row->price_list_id,
                    (string) $row->min_quantity,
                    $old,
                    $new,
                    $context,
                );

                continue;
            }

            $uom = ItemUom::query()->findOrFail((int) $line->item_uom_id);
            $this->itemUoms->update($item, $uom, [
                $line->field->value => (string) $line->new_value,
                'reason' => $doc->notes,
                'history_source' => PriceChangeSource::PriceChangeDoc->value,
                'history_source_id' => (string) $doc->id,
                'history_effective_at' => $doc->effective_date->toDateString(),
                'history_changed_by' => $context['changed_by'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function matchingItemIds(array $filters): array
    {
        $query = Item::query()->where('is_active', true);
        if (! empty($filters['category_id'])) {
            $query->whereIn('category_id', $this->categoryIds((int) $filters['category_id']));
        }
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $supplierId = (string) $filters['supplier_id'];
            $query->whereHas('supplierItems', fn ($q) => $q->where('supplier_id', $supplierId));
        }
        if (! empty($filters['item_ids']) && is_array($filters['item_ids'])) {
            $query->whereIn('id', $filters['item_ids']);
        }
        if (! empty($filters['price_list_id']) && ($filters['field'] ?? '') !== PriceField::PriceList->value) {
            $onList = PriceListItem::query()->where('price_list_id', (int) $filters['price_list_id'])->pluck('item_id');
            $query->whereIn('id', $onList);
        }

        return $query->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * @param  list<string>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function bulkUomLines(array $itemIds, PriceField $field, string $rule, string $value, string $rounding): array
    {
        if ($itemIds === []) {
            return [];
        }
        $uoms = ItemUom::query()->whereIn('item_id', $itemIds)->orderBy('item_id')->orderBy('id')->get();
        $costs = $this->unitCosts($uoms->all());
        $lines = [];
        foreach ($uoms as $uom) {
            $old = $this->money($uom->getAttribute($field->value));
            $cost = $costs[(int) $uom->id] ?? null;
            $computed = PriceChangeMath::compute($old, $cost, $rule, $value);
            if ($computed === null) {
                continue;
            }
            $lines[] = [
                'item_id' => (string) $uom->item_id,
                'item_uom_id' => (int) $uom->id,
                'price_list_id' => null,
                'min_quantity' => null,
                'field' => $field->value,
                'old_value' => $old,
                'new_value' => PriceMath::normalize(PriceChangeMath::round($computed, $rounding)),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<string>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function bulkListLines(int $priceListId, array $itemIds, string $rule, string $value, string $rounding): array
    {
        if ($itemIds === []) {
            return [];
        }
        $rows = PriceListItem::query()
            ->where('price_list_id', $priceListId)
            ->whereIn('item_id', $itemIds)
            ->orderBy('item_id')
            ->orderBy('id')
            ->get();
        $uoms = ItemUom::query()->whereIn('id', $rows->pluck('item_uom_id'))->get()->keyBy('id');
        $costs = $this->unitCosts($uoms->all());
        $lines = [];
        foreach ($rows as $row) {
            $old = $this->money($row->price);
            $cost = $costs[(int) $row->item_uom_id] ?? null;
            $computed = PriceChangeMath::compute($old, $cost, $rule, $value);
            if ($computed === null) {
                continue;
            }
            $lines[] = [
                'item_id' => (string) $row->item_id,
                'item_uom_id' => (int) $row->item_uom_id,
                'price_list_id' => $priceListId,
                'min_quantity' => Decimal::round((string) $row->min_quantity, 6),
                'field' => PriceField::PriceList->value,
                'old_value' => $old,
                'new_value' => PriceMath::normalize(PriceChangeMath::round($computed, $rounding)),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function decorate(array $lines, ?PriceChange $doc = null): array
    {
        if ($lines === []) {
            return [];
        }
        $uomIds = array_values(array_unique(array_filter(array_map(fn (array $line): int => (int) ($line['item_uom_id'] ?? 0), $lines))));
        $uoms = ItemUom::query()->with('uom:id,code,name')->whereIn('id', $uomIds)->get()->keyBy('id');
        $items = Item::query()->whereIn('id', array_unique(array_column($lines, 'item_id')))->get(['id', 'item_code', 'name'])->keyBy('id');
        $listIds = array_values(array_unique(array_filter(array_map(fn (array $line): int => (int) ($line['price_list_id'] ?? 0), $lines))));
        $lists = $listIds === [] ? collect() : PriceList::query()->whereIn('id', $listIds)->get(['id', 'code', 'name', 'currency_id'])->keyBy('id');
        $primaryId = Currency::getPrimary()?->id;
        $costs = $this->unitCosts($uoms->all());
        $loaded = $doc?->relationLoaded('lines') === true ? $doc->lines->keyBy('id') : null;

        $out = [];
        foreach ($lines as $line) {
            $uom = $uoms->get((int) ($line['item_uom_id'] ?? 0));
            $item = $items->get((string) $line['item_id']);
            $list = $line['price_list_id'] !== null ? $lists->get((int) $line['price_list_id']) : null;
            $field = (string) $line['field'];
            $cost = $uom !== null ? ($costs[(int) $uom->id] ?? null) : null;
            $new = $this->money($line['new_value'] ?? null);
            $comparable = $field !== PriceField::Cost->value
                && ($list === null || ($primaryId !== null && (int) $list->currency_id === (int) $primaryId));
            $below = $comparable && $cost !== null && $new !== null && Decimal::cmp($new, $cost, 6) < 0;
            $modelLine = $loaded !== null && isset($line['id']) ? $loaded->get((int) $line['id']) : null;
            $out[] = [
                'id' => $line['id'] ?? null,
                'item_id' => (string) $line['item_id'],
                'item_code' => $modelLine?->item?->item_code ?? $item?->item_code,
                'item_name' => $modelLine?->item?->name ?? $item?->name,
                'item_uom_id' => $uom !== null ? (int) $uom->id : null,
                'uom' => $modelLine?->itemUom?->uom?->code ?? $uom?->uom?->code,
                'price_list_id' => $line['price_list_id'],
                'price_list' => $modelLine?->priceList?->code ?? $list?->code,
                'min_quantity' => $line['min_quantity'] !== null ? (string) $line['min_quantity'] : null,
                'field' => $field,
                'old_value' => $this->money($line['old_value'] ?? null),
                'new_value' => $new,
                'cost' => $cost,
                'margin_percent' => PriceChangeMath::marginPercent($new, $cost),
                'below_cost' => $below,
                'sort_order' => $line['sort_order'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function belowCostRows(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (($line['below_cost'] ?? false) !== true) {
                continue;
            }
            $out[] = [
                'item_id' => $line['item_id'],
                'item_code' => $line['item_code'] ?? null,
                'item_name' => $line['item_name'] ?? null,
                'uom' => $line['uom'] ?? null,
                'field' => $line['field'],
                'new_value' => $line['new_value'],
                'cost' => $line['cost'],
            ];
        }

        return $out;
    }

    /**
     * Cost of one unit: on-hand average (base) × conversion, else the unit's own cost price.
     *
     * @param  list<ItemUom>  $uoms
     * @return array<int, string|null>
     */
    private function unitCosts(array $uoms): array
    {
        if ($uoms === []) {
            return [];
        }
        $itemIds = array_values(array_unique(array_map(fn (ItemUom $uom): string => (string) $uom->item_id, $uoms)));
        $averages = [];
        $rows = DB::table('stock_balances')
            ->whereIn('item_id', $itemIds)
            ->where('quantity', '>', 0)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(unit_cost * quantity) as extended, SUM(quantity) as qty')
            ->get();
        foreach ($rows as $row) {
            if (Decimal::isPositive($row->qty)) {
                $averages[(string) $row->item_id] = Decimal::div($row->extended, $row->qty, 6);
            }
        }

        $out = [];
        foreach ($uoms as $uom) {
            $base = $averages[(string) $uom->item_id] ?? null;
            if ($base !== null) {
                $out[(int) $uom->id] = Decimal::mul($base, (string) $uom->conversion_factor, 6);

                continue;
            }
            $out[(int) $uom->id] = $this->money($uom->cost_price);
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function categoryIds(int $rootId): array
    {
        $children = [];
        foreach (Category::query()->get(['id', 'parent_id']) as $category) {
            $children[(int) ($category->parent_id ?? 0)][] = (int) $category->id;
        }
        $out = [];
        $stack = [$rootId];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current === null || isset($out[$current])) {
                continue;
            }
            $out[$current] = true;
            foreach ($children[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_map('intval', array_keys($out));
    }

    private function canPriceBelowCost(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->permissions->userHas('price_below_cost_override', 'edit', $user);
    }

    private function userId(): ?string
    {
        $id = auth()->id();

        return $id !== null ? (string) $id : null;
    }

    private function notes(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $notes = trim((string) $value);

        return $notes === '' ? null : $notes;
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Decimal::round($value, 6);
    }
}
