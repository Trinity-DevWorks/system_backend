<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ItemType\Services;

use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;

final class ItemTypeCatalogService
{
    private const CACHE_KEY = 'item_types.catalog';

    public function allOrdered(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_KEY,
            ItemType::class,
            fn (): Collection => ItemType::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get()
        );
    }

    public function forget(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }

    /**
     * Insert or update catalog rows from config (tenant context required).
     */
    public function syncFromConfig(): void
    {
        $types = config('item_types.types', []);

        foreach ($types as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = strtoupper(trim((string) ($entry['code'] ?? '')));
            $name = strtolower(trim((string) ($entry['name'] ?? '')));

            if ($code === '' || $name === '') {
                continue;
            }

            ItemType::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'is_system' => true,
                    'is_active' => true,
                ]
            );
        }

        $this->forget();
    }
}
