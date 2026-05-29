<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Support;

use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

/**
 * Resolve tenant default warehouses by role (sales, purchase, storage, …).
 */
final class WarehouseDefaults
{
    public static function for(string $kind): ?Warehouse
    {
        return app(WarehouseService::class)->defaultWarehouseFor($kind);
    }

    public static function idFor(string $kind): ?int
    {
        return self::for($kind)?->id;
    }
}
