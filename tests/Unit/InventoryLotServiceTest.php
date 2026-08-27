<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Services\InventoryLotService;
use Tests\TestCase;

class InventoryLotServiceTest extends TestCase
{
    public function test_normalize_lot_number(): void
    {
        $this->assertNull(InventoryLotService::normalizeLotNumber(null));
        $this->assertNull(InventoryLotService::normalizeLotNumber('   '));
        $this->assertSame('LOT-1', InventoryLotService::normalizeLotNumber(' lot-1 '));
    }

    public function test_normalize_expiry(): void
    {
        $this->assertNull(InventoryLotService::normalizeExpiry(null));
        $this->assertNull(InventoryLotService::normalizeExpiry(''));
        $this->assertSame('2026-08-24', InventoryLotService::normalizeExpiry('2026-08-24'));
    }
}
