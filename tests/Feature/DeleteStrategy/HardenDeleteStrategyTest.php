<?php

declare(strict_types=1);

namespace Tests\Feature\DeleteStrategy;

use App\Models\User;
use App\Modules\Category\Models\Category;
use App\Modules\Customer\Models\CustomerGroup;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\ItemService;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Rbac\Models\Role;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Soft-delete and lookup-group delete-guard coverage for issue #128.
 *
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db
 */
#[Group('tenant-db')]
class HardenDeleteStrategyTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('delete_strategy');
        $this->token = $this->tenantBearerToken();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_user_delete_soft_deletes_and_hides_from_index(): void
    {
        $userId = $this->tenant->run(function (): string {
            $role = Role::query()->where('name', '!=', 'Owner')->orderBy('id')->first()
                ?? Role::query()->create([
                    'name' => 'Staff',
                    'description' => 'Test staff role',
                    'is_active' => true,
                ]);

            $user = User::factory()->create([
                'name' => 'Soft Delete User',
                'email' => 'soft-delete@delete-strategy.local',
                'is_active' => true,
                'role_id' => $role->id,
            ]);

            return (string) $user->id;
        });

        $this->asTenantRequest($this->token)
            ->delete($this->tenantUrl("/users/{$userId}"))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->tenant->run(function () use ($userId): void {
            $this->assertNull(User::query()->find($userId));
            $trashed = User::withTrashed()->find($userId);
            $this->assertNotNull($trashed);
            $this->assertNotNull($trashed->deleted_at);
        });

        $list = $this->asTenantRequest($this->token)
            ->get($this->tenantUrl('/users'))
            ->assertOk()
            ->json('data');

        $ids = collect($list)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertNotContains($userId, $ids);
    }

    public function test_customer_group_delete_blocked_while_members_exist(): void
    {
        $groupId = $this->tenant->run(function (): int {
            $group = CustomerGroup::query()->create([
                'code' => 'RETAIL',
                'name' => 'Retail',
                'is_active' => true,
            ]);

            $this->createCustomer('Grouped Customer')->forceFill([
                'customer_group_id' => $group->id,
            ])->save();

            return (int) $group->id;
        });

        $this->asTenantRequest($this->token)
            ->delete($this->tenantUrl("/customer-groups/{$groupId}"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'CUSTOMER_GROUP_DELETE_HAS_MEMBERS');

        $this->tenant->run(function () use ($groupId): void {
            $this->assertTrue(CustomerGroup::query()->whereKey($groupId)->exists());
        });
    }

    public function test_vat_group_default_delete_forbidden(): void
    {
        $vatGroupId = $this->tenant->run(function (): int {
            return (int) VatGroup::query()->create([
                'abrv' => 'VAT-DEF',
                'name' => 'Default VAT',
                'percentage' => 15,
                'is_default' => true,
                'is_active' => true,
            ])->id;
        });

        $this->asTenantRequest($this->token)
            ->delete($this->tenantUrl("/vat-groups/{$vatGroupId}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VAT_GROUP_DEFAULT_DELETE_FORBIDDEN');
    }

    public function test_vat_group_delete_blocked_when_assigned_to_customer(): void
    {
        $vatGroupId = $this->tenant->run(function (): int {
            $vatGroup = VatGroup::query()->create([
                'abrv' => 'VAT-STD',
                'name' => 'Standard VAT',
                'percentage' => 10,
                'is_default' => false,
                'is_active' => true,
            ]);

            $this->createCustomer('VAT Customer')->forceFill([
                'vat_group_id' => $vatGroup->id,
            ])->save();

            return (int) $vatGroup->id;
        });

        $this->asTenantRequest($this->token)
            ->delete($this->tenantUrl("/vat-groups/{$vatGroupId}"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VAT_GROUP_DELETE_REFERENCED_BY_CUSTOMERS');
    }

    public function test_vat_group_delete_blocked_when_soft_deleted_customer_still_references_it(): void
    {
        $vatGroupId = $this->tenant->run(function (): int {
            $vatGroup = VatGroup::query()->create([
                'abrv' => 'VAT-SOFT',
                'name' => 'Soft Ref VAT',
                'percentage' => 5,
                'is_default' => false,
                'is_active' => true,
            ]);

            $customer = $this->createCustomer('Soft Deleted VAT Customer');
            $customer->forceFill(['vat_group_id' => $vatGroup->id])->save();
            $customer->delete();

            return (int) $vatGroup->id;
        });

        $this->asTenantRequest($this->token)
            ->delete($this->tenantUrl("/vat-groups/{$vatGroupId}"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'VAT_GROUP_DELETE_REFERENCED_BY_CUSTOMERS');
    }

    public function test_item_delete_soft_deletes(): void
    {
        $itemId = $this->tenant->run(function (): string {
            $itemType = ItemType::query()->firstOrCreate(
                ['code' => 'STOCK'],
                ['name' => 'Stock', 'is_system' => true, 'is_active' => true],
            );

            $category = Category::query()->firstOrCreate(
                ['code' => 'CAT-SOFT'],
                ['name' => 'Soft Delete Category', 'color' => '#336699', 'is_active' => true],
            );

            $unitGroup = UnitGroup::query()->firstOrCreate(
                ['code' => 'COUNT'],
                ['name' => 'Count', 'dimension_type' => DimensionType::Count, 'is_active' => true],
            );

            $item = Item::query()->create([
                'name' => 'Soft Delete Item',
                'sku' => 'SKU-SOFT-01',
                'item_type_id' => $itemType->id,
                'category_id' => $category->id,
                'unit_group_id' => $unitGroup->id,
                'is_active' => true,
            ]);

            app(ItemService::class)->delete($item);

            return (string) $item->id;
        });

        $this->tenant->run(function () use ($itemId): void {
            $this->assertNull(Item::query()->find($itemId));
            $trashed = Item::withTrashed()->find($itemId);
            $this->assertNotNull($trashed);
            $this->assertNotNull($trashed->deleted_at);
        });
    }
}
