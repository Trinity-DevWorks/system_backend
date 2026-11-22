<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Services;

use App\Modules\Inventory\Item\Support\InStoreBarcode;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use App\Support\ListPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Saves scale label formats and keeps them safe: positions that fit, no clash with our own
 * in-store barcodes, and no two active formats claiming the same codes in the same branch.
 */
class ScaleBarcodeFormatService
{
    /** Fixed total length per symbology (custom Code 128 has none). */
    private const SYMBOLOGY_LENGTH = ['ean13' => 13, 'upca' => 12];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ScaleBarcodeFormat>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ScaleBarcodeFormat::query()->with(['uom:id,code,name', 'currency:id,code,name', 'branches:id,name']);
        ListPagination::applySearch($query, $filters['search'] ?? null, ['name']);
        if (($filters['active'] ?? null) !== null && $filters['active'] !== '') {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->orderBy('priority')->orderBy('id')->paginate($perPage);
    }

    public function find(int $id): ScaleBarcodeFormat
    {
        return ScaleBarcodeFormat::query()->with(['uom:id,code,name', 'currency:id,code,name', 'branches:id,name'])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ScaleBarcodeFormat
    {
        return DB::transaction(function () use ($data): ScaleBarcodeFormat {
            [$attributes, $branchIds] = $this->normalize($data);
            $this->assertNoOverlap($attributes, $branchIds, null);
            $format = ScaleBarcodeFormat::query()->create($attributes);
            $format->branches()->sync($branchIds);

            return $this->find((int) $format->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ScaleBarcodeFormat $format, array $data): ScaleBarcodeFormat
    {
        return DB::transaction(function () use ($format, $data): ScaleBarcodeFormat {
            [$attributes, $branchIds] = $this->normalize($data);
            $this->assertNoOverlap($attributes, $branchIds, (int) $format->id);
            $format->update($attributes);
            $format->branches()->sync($branchIds);

            return $this->find((int) $format->id);
        });
    }

    public function delete(ScaleBarcodeFormat $format): void
    {
        $format->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ScaleBarcodeFormat $format): array
    {
        return [
            'id' => (int) $format->id,
            'name' => $format->name,
            'engine' => $format->engine,
            'symbology' => $format->symbology,
            'prefixes' => $format->prefixes ?? [],
            'total_length' => $format->total_length,
            'plu_start' => $format->plu_start,
            'plu_length' => $format->plu_length,
            'value_start' => $format->value_start,
            'value_length' => $format->value_length,
            'value_type' => $format->value_type,
            'decimals' => (int) $format->decimals,
            'multiplier' => (string) $format->multiplier,
            'uom_id' => $format->uom_id,
            'uom' => $format->uom ? ['id' => (int) $format->uom->id, 'code' => $format->uom->code, 'name' => $format->uom->name] : null,
            'currency_id' => $format->currency_id,
            'currency' => $format->currency ? ['id' => (int) $format->currency->id, 'code' => $format->currency->code, 'name' => $format->currency->name] : null,
            'price_includes_tax' => (bool) $format->price_includes_tax,
            'verify_check_digit' => (bool) $format->verify_check_digit,
            'price_check_digit_position' => $format->price_check_digit_position,
            'priority' => (int) $format->priority,
            'is_active' => (bool) $format->is_active,
            'branch_ids' => $format->branches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'branches' => $format->branches->map(fn ($b): array => ['id' => (int) $b->id, 'name' => $b->name])->values()->all(),
            'updated_at' => $format->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Clean the input and check the layout. Also used by the "Test a barcode" box.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    public function normalize(array $data): array
    {
        $engine = (string) $data['engine'];
        $symbology = (string) $data['symbology'];
        $valueType = (string) $data['value_type'];
        $int = static fn (mixed $v): ?int => $v === null || $v === '' ? null : (int) $v;

        $prefixes = array_values(array_unique(array_filter(
            array_map(static fn ($p): string => trim((string) $p), (array) ($data['prefixes'] ?? [])),
            static fn (string $p): bool => $p !== '',
        )));

        $attributes = [
            'name' => trim((string) $data['name']),
            'engine' => $engine,
            'symbology' => $symbology,
            'prefixes' => $prefixes,
            'total_length' => $int($data['total_length'] ?? null),
            'plu_start' => $int($data['plu_start'] ?? null),
            'plu_length' => $int($data['plu_length'] ?? null),
            'value_start' => $int($data['value_start'] ?? null),
            'value_length' => $int($data['value_length'] ?? null),
            'value_type' => $valueType,
            'decimals' => (int) ($data['decimals'] ?? 0),
            'multiplier' => isset($data['multiplier']) && $data['multiplier'] !== '' ? (string) $data['multiplier'] : '1',
            'uom_id' => $int($data['uom_id'] ?? null),
            'currency_id' => $valueType === ScaleBarcodeFormat::TYPE_PRICE ? $int($data['currency_id'] ?? null) : null,
            'price_includes_tax' => (bool) ($data['price_includes_tax'] ?? true),
            'verify_check_digit' => (bool) ($data['verify_check_digit'] ?? true),
            'price_check_digit_position' => $int($data['price_check_digit_position'] ?? null),
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        $isGs1Symbology = in_array($symbology, ['gs1_128', 'gs1_databar'], true);
        if (($engine === ScaleBarcodeFormat::ENGINE_GS1) !== $isGs1Symbology) {
            self::fail('GS1 symbologies use the GS1 reader; EAN, UPC and custom layouts use fixed positions.', 'SCALE_BARCODE_FORMAT_ENGINE_MISMATCH');
        }
        // GS1 weights say their own unit (310n kg / 320n lb), so only other weight / count formats need one.
        $gs1Weight = $engine === ScaleBarcodeFormat::ENGINE_GS1 && $valueType === ScaleBarcodeFormat::TYPE_WEIGHT;
        if ($gs1Weight) {
            $attributes['uom_id'] = null;
        }
        if ($valueType !== ScaleBarcodeFormat::TYPE_PRICE && ! $gs1Weight && $attributes['uom_id'] === null) {
            self::fail('Choose the unit the label weight or count is in.', 'SCALE_BARCODE_FORMAT_UNIT_REQUIRED');
        }

        if ($engine === ScaleBarcodeFormat::ENGINE_FIXED) {
            $this->checkFixedLayout($attributes);
        } else {
            // GS1 values carry their own decimals; PLU positions (if any) are inside the GTIN.
            $attributes['total_length'] = null;
            $attributes['value_start'] = null;
            $attributes['value_length'] = null;
            $attributes['decimals'] = 0;
            $attributes['multiplier'] = '1';
            $attributes['price_check_digit_position'] = null;
            if (($attributes['plu_start'] === null) !== ($attributes['plu_length'] === null)) {
                self::fail('Give both the PLU start and length, or neither.', 'SCALE_BARCODE_FORMAT_POSITIONS_INVALID');
            }
        }

        $branchIds = array_values(array_unique(array_map('intval', (array) ($data['branch_ids'] ?? []))));

        return [$attributes, $branchIds];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function checkFixedLayout(array &$attributes): void
    {
        $symbology = (string) $attributes['symbology'];
        if (isset(self::SYMBOLOGY_LENGTH[$symbology])) {
            $attributes['total_length'] = self::SYMBOLOGY_LENGTH[$symbology];
        }
        $length = $attributes['total_length'];

        foreach (['plu_start', 'plu_length', 'value_start', 'value_length'] as $field) {
            if ($attributes[$field] === null || $attributes[$field] < 1) {
                self::fail('Set where the PLU and the value are on the label.', 'SCALE_BARCODE_FORMAT_POSITIONS_INVALID');
            }
        }
        $plu = [$attributes['plu_start'], $attributes['plu_start'] + $attributes['plu_length'] - 1];
        $value = [$attributes['value_start'], $attributes['value_start'] + $attributes['value_length'] - 1];
        $ranges = ['plu' => $plu, 'value' => $value];
        if ($attributes['price_check_digit_position'] !== null) {
            $p = (int) $attributes['price_check_digit_position'];
            $ranges['price_check'] = [$p, $p];
            if ($attributes['value_type'] !== ScaleBarcodeFormat::TYPE_PRICE || ! in_array($attributes['value_length'], [4, 5], true)) {
                self::fail('A price check digit only works with a 4- or 5-digit price.', 'SCALE_BARCODE_FORMAT_PRICE_CHECK_INVALID');
            }
        }
        $mod10 = in_array($symbology, ScaleBarcodeFormat::MOD10_SYMBOLOGIES, true);
        if ($length !== null && $mod10 && $attributes['verify_check_digit']) {
            $ranges['check'] = [$length, $length];
        }
        $prefixLength = max(0, ...array_map('strlen', $attributes['prefixes'] ?: ['']));
        if ($prefixLength > 0) {
            $ranges['prefix'] = [1, $prefixLength];
        }

        $names = array_keys($ranges);
        foreach ($names as $i => $a) {
            [$aFrom, $aTo] = $ranges[$a];
            if ($length !== null && $aTo > $length) {
                self::fail('A position runs past the end of the barcode.', 'SCALE_BARCODE_FORMAT_POSITIONS_INVALID');
            }
            foreach (array_slice($names, $i + 1) as $b) {
                [$bFrom, $bTo] = $ranges[$b];
                if ($aFrom <= $bTo && $bFrom <= $aTo) {
                    self::fail('The '.$a.' and '.$b.' positions overlap.', 'SCALE_BARCODE_FORMAT_POSITIONS_INVALID');
                }
            }
        }

        if ($mod10) {
            foreach ($attributes['prefixes'] as $prefix) {
                if (preg_match('/^\d+$/', $prefix) !== 1) {
                    self::fail('EAN and UPC prefixes are digits.', 'SCALE_BARCODE_FORMAT_POSITIONS_INVALID');
                }
            }
        }

        // Our own in-store EAN-13 codes start with 200.
        if ($length === 13) {
            foreach ($attributes['prefixes'] ?: [''] as $prefix) {
                if (str_starts_with(InStoreBarcode::PREFIX, $prefix) || str_starts_with($prefix, InStoreBarcode::PREFIX)) {
                    self::fail('EAN-13 codes starting with '.InStoreBarcode::PREFIX.' are the in-store barcodes this system creates. Use 21–29 for scale labels.', 'SCALE_BARCODE_PREFIX_RESERVED');
                }
            }
        }
    }

    /**
     * Two active formats must not claim the same codes in a shared branch.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $branchIds
     */
    private function assertNoOverlap(array $attributes, array $branchIds, ?int $ignoreId): void
    {
        if (! $attributes['is_active']) {
            return;
        }
        $others = ScaleBarcodeFormat::query()
            ->with('branches:id')
            ->where('is_active', true)
            ->where('engine', $attributes['engine'])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();

        foreach ($others as $other) {
            $otherBranches = $other->branches->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $shareBranch = $branchIds === [] || $otherBranches === [] || array_intersect($branchIds, $otherBranches) !== [];
            if (! $shareBranch) {
                continue;
            }
            if ($attributes['engine'] === ScaleBarcodeFormat::ENGINE_FIXED) {
                $sameLength = $attributes['total_length'] === null || $other->total_length === null
                    || (int) $attributes['total_length'] === (int) $other->total_length;
                if (! $sameLength) {
                    continue;
                }
            } elseif ($attributes['value_type'] !== $other->value_type) {
                continue;
            }
            if (self::prefixesOverlap($attributes['prefixes'], $other->prefixes ?? [])) {
                self::fail('This format reads the same barcodes as "'.$other->name.'". Change the prefix, length or branches.', 'SCALE_BARCODE_FORMAT_OVERLAP', ['format' => (string) $other->name]);
            }
        }
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function prefixesOverlap(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return true;
        }
        foreach ($a as $x) {
            foreach ($b as $y) {
                if (str_starts_with($x, $y) || str_starts_with($y, $x)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $params
     */
    private static function fail(string $message, string $code, array $params = []): never
    {
        abort(422, $message, ['X-Error-Code' => $code, 'X-Error-Params' => (string) json_encode($params)]);
    }
}
