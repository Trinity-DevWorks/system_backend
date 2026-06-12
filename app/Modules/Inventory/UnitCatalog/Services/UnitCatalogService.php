<?php

declare(strict_types=1);

namespace App\Modules\Inventory\UnitCatalog\Services;

use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Support\TenantReferenceCache;

final class UnitCatalogService
{
    public function forgetCache(): void
    {
        TenantReferenceCache::forget('unit_groups.list', 'unit_of_measurements.list');
    }

    /**
     * Insert or update catalog rows from config (tenant context required).
     */
    public function syncFromConfig(): void
    {
        $groups = config('unit_catalog.groups', []);

        foreach ($groups as $groupEntry) {
            if (! is_array($groupEntry)) {
                continue;
            }

            $groupCode = strtoupper(trim((string) ($groupEntry['code'] ?? '')));
            $groupName = trim((string) ($groupEntry['name'] ?? ''));
            $dimensionType = $this->resolveDimensionType((string) ($groupEntry['dimension_type'] ?? ''));

            if ($groupCode === '' || $groupName === '') {
                continue;
            }

            $group = UnitGroup::query()->updateOrCreate(
                ['code' => $groupCode],
                [
                    'name' => $groupName,
                    'dimension_type' => $dimensionType,
                    'is_active' => true,
                ]
            );

            foreach ($groupEntry['units'] ?? [] as $unitEntry) {
                if (! is_array($unitEntry)) {
                    continue;
                }

                $unitCode = strtoupper(trim((string) ($unitEntry['code'] ?? '')));
                $unitName = trim((string) ($unitEntry['name'] ?? ''));

                if ($unitCode === '' || $unitName === '') {
                    continue;
                }

                $symbol = trim((string) ($unitEntry['symbol'] ?? ''));
                $decimalPlaces = max(0, min(6, (int) ($unitEntry['decimal_places'] ?? 2)));

                UnitOfMeasurement::query()->updateOrCreate(
                    [
                        'unit_group_id' => $group->id,
                        'code' => $unitCode,
                    ],
                    [
                        'name' => $unitName,
                        'symbol' => $symbol === '' ? null : $symbol,
                        'decimal_places' => $decimalPlaces,
                        'is_active' => true,
                    ]
                );
            }
        }

        $this->forgetCache();
    }

    private function resolveDimensionType(string $value): DimensionType
    {
        $normalized = strtolower(trim($value));

        return DimensionType::tryFrom($normalized) ?? DimensionType::Count;
    }
}
