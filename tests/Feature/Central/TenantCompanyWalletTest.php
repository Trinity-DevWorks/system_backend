<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\TenantCompanyWallet;
use App\Services\Central\TenantCompanyWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('central')]
class TenantCompanyWalletTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $id): void
    {
        DB::table('tenants')->insert([
            'id' => $id,
            'name' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_wallet_matches_another_tenant_and_not_the_current_one(): void
    {
        $this->tenant('seller');
        $this->tenant('buyer');
        $service = app(TenantCompanyWalletService::class);
        $service->sync('seller', 'Seller Co', '0x1111111111111111111111111111111111111111', 'safe', null, null);
        $service->sync('buyer', 'Buyer Co', '0x2222222222222222222222222222222222222222', 'safe', null, null);

        $match = $service->knownTenant('0x2222222222222222222222222222222222222222');

        $this->assertSame([
            'tenant_id' => 'buyer',
            'company_name' => 'Buyer Co',
        ], $match);
    }

    public function test_publishing_a_wallet_releases_it_from_the_previous_tenant(): void
    {
        $this->tenant('first');
        $this->tenant('second');
        $service = app(TenantCompanyWalletService::class);
        $service->sync('first', 'First', '0x3333333333333333333333333333333333333333', 'safe', null, null);
        $service->sync('second', 'Second', '0x3333333333333333333333333333333333333333', 'safe', null, null);

        $match = $service->knownTenant('0x3333333333333333333333333333333333333333');

        $this->assertSame('second', $match['tenant_id'] ?? null);
        $this->assertNull(TenantCompanyWallet::query()->where('tenant_id', 'first')->value('wallet_address_anvil'));
    }
}
