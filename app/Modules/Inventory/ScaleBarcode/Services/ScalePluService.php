<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use Illuminate\Support\Collection;

/**
 * PLU helpers: the next free PLU, whether a PLU fits the active label formats, and which items
 * a format can't read because their PLU is too long.
 */
class ScalePluService
{
    private const DEFAULT_LENGTH = 5;

    /**
     * @return array{plu: string, length: int}
     */
    public function next(): array
    {
        $length = $this->commonLength();
        $max = (int) str_repeat('9', $length);
        $taken = $this->takenPlus();
        // PLUs longer than the formats read (e.g. full EAN codes typed as PLU) don't count.
        $highest = 0;
        foreach (array_keys($taken) as $plu) {
            if ($plu <= $max) {
                $highest = max($highest, $plu);
            }
        }
        $candidate = $highest + 1;
        while (isset($taken[$candidate])) {
            $candidate++;
        }
        if ($candidate > $max) {
            // Range used up from the top: take the lowest free code instead.
            $candidate = 1;
            while (isset($taken[$candidate]) && $candidate <= $max) {
                $candidate++;
            }
        }

        return ['plu' => str_pad((string) $candidate, $length, '0', STR_PAD_LEFT), 'length' => $length];
    }

    /**
     * @return array{fits: bool, formats: list<array{id: int, name: string, plu_length: int}>, taken_by: array{id: string, name: string}|null}
     */
    public function check(string $plu, ?string $ignoreItemId = null): array
    {
        $plu = trim($plu);
        $digits = strlen(ltrim($plu, '0'));
        $tooShort = [];
        foreach ($this->fixedFormats() as $format) {
            if ($digits > (int) $format->plu_length) {
                $tooShort[] = ['id' => (int) $format->id, 'name' => $format->name, 'plu_length' => (int) $format->plu_length];
            }
        }

        $takenBy = null;
        if ($plu !== '') {
            $other = Item::query()
                ->where('is_scale_item', true)
                ->whereNotNull('plu_code')
                ->when($ignoreItemId !== null && $ignoreItemId !== '', fn ($q) => $q->where('id', '!=', $ignoreItemId))
                ->get(['id', 'name', 'plu_code'])
                ->first(fn (Item $item): bool => ltrim((string) $item->plu_code, '0') === ltrim($plu, '0'));
            $takenBy = $other ? ['id' => (string) $other->id, 'name' => $other->name] : null;
        }

        return ['fits' => $tooShort === [], 'formats' => $tooShort, 'taken_by' => $takenBy];
    }

    /**
     * Scale items this format can't read: PLU longer than its PLU digits. Shown as a warning on save.
     *
     * @return list<array{id: string, name: string, plu_code: string}>
     */
    public function itemsTooLongFor(ScaleBarcodeFormat $format): array
    {
        if (! $format->is_active || $format->plu_length === null) {
            return [];
        }

        return Item::query()
            ->where('is_scale_item', true)
            ->where('is_active', true)
            ->whereNotNull('plu_code')
            ->orderBy('name')
            ->get(['id', 'name', 'plu_code'])
            ->filter(fn (Item $item): bool => strlen(ltrim((string) $item->plu_code, '0')) > (int) $format->plu_length)
            ->take(50)
            ->map(fn (Item $item): array => ['id' => (string) $item->id, 'name' => (string) $item->name, 'plu_code' => (string) $item->plu_code])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ScaleBarcodeFormat>
     */
    private function fixedFormats(): Collection
    {
        return ScaleBarcodeFormat::query()
            ->where('is_active', true)
            ->where('engine', ScaleBarcodeFormat::ENGINE_FIXED)
            ->whereNotNull('plu_length')
            ->orderBy('priority')
            ->get(['id', 'name', 'plu_length']);
    }

    /** The PLU length most active fixed formats use (5 when there is none). */
    private function commonLength(): int
    {
        $counts = $this->fixedFormats()->countBy(fn (ScaleBarcodeFormat $f): int => (int) $f->plu_length);
        if ($counts->isEmpty()) {
            return self::DEFAULT_LENGTH;
        }

        return (int) $counts->sortDesc()->keys()->first();
    }

    /**
     * @return array<int, true> PLU as a number => true
     */
    private function takenPlus(): array
    {
        $taken = [];
        foreach (Item::query()->whereNotNull('plu_code')->pluck('plu_code') as $plu) {
            $plu = (string) $plu;
            if (preg_match('/^\d+$/', $plu) === 1) {
                $taken[(int) $plu] = true;
            }
        }

        return $taken;
    }
}
