<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Category\Models\Category;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Enums\CustomerType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\WalkInCustomerService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Rbac\Models\Role;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\ModuleEntitlementService;
use App\Services\PermissionService;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Seeds a development tenant: name "tenant", login tenant@gmail.com / 12345678.
 * Access tenant API via domain {@see self::TENANT_DOMAIN} (add to hosts: 127.0.0.1 tenant.localhost).
 *
 * Demo data (after RBAC): Admin user, branch-linked warehouses, 1 customer, 1 supplier,
 * 2 inventory items each with base + alternate UOM.
 */
class TenantSeeder extends Seeder
{
    public const TENANT_NAME = 'tenant';

    public const TENANT_DOMAIN = 'tenant.localhost';

    public const OWNER_EMAIL = 'tenant@gmail.com';

    public const OWNER_PASSWORD = '12345678';

    public const ADMIN_EMAIL = 'admin@gmail.com';

    public const ADMIN_PASSWORD = '12345678';

    /**
     * Sample category tree (parent → children). Only leaf nodes should be used on items.
     *
     * @var list<array{code:string,name:string,color:string,description:string,is_active:bool,children?:list<array<string,mixed>>}>
     */
    private const DEFAULT_CATEGORY_TREE = [
        [
            'code' => 'BEV',
            'name' => 'Beverages',
            'color' => '#1E88E5',
            'description' => 'All beverage products.',
            'is_active' => true,
            'children' => [
                [
                    'code' => 'BEV-SOFT',
                    'name' => 'Soft Drinks',
                    'color' => '#42A5F5',
                    'description' => 'Carbonated and non-carbonated soft drinks.',
                    'is_active' => true,
                    'children' => [
                        [
                            'code' => 'BEV-ENERGY',
                            'name' => 'Energy Drinks',
                            'color' => '#7E57C2',
                            'description' => 'Energy and sports drinks.',
                            'is_active' => true,
                        ],
                        [
                            'code' => 'BEV-COLA',
                            'name' => 'Cola',
                            'color' => '#5D4037',
                            'description' => 'Cola beverages.',
                            'is_active' => true,
                        ],
                    ],
                ],
                [
                    'code' => 'BEV-JUI',
                    'name' => 'Juices',
                    'color' => '#43A047',
                    'description' => 'Packaged fruit and mixed juices.',
                    'is_active' => true,
                ],
            ],
        ],
        [
            'code' => 'SNA',
            'name' => 'Snacks',
            'color' => '#FB8C00',
            'description' => 'Snack foods.',
            'is_active' => true,
            'children' => [
                [
                    'code' => 'SNA-CHI',
                    'name' => 'Chips',
                    'color' => '#F57C00',
                    'description' => 'Salted and flavored potato chips.',
                    'is_active' => true,
                ],
                [
                    'code' => 'SNA-BIS',
                    'name' => 'Biscuits',
                    'color' => '#8D6E63',
                    'description' => 'Sweet and savory biscuits.',
                    'is_active' => true,
                ],
            ],
        ],
        [
            'code' => 'DAI',
            'name' => 'Dairy',
            'color' => '#5C6BC0',
            'description' => 'Milk and milk-based items.',
            'is_active' => true,
        ],
    ];

    public function run(): void
    {
        $modules = app(ModuleEntitlementService::class);

        if (Domain::query()->where('domain', self::TENANT_DOMAIN)->exists()) {
            $tenant = Tenant::query()->whereKey(self::TENANT_NAME)->first()
                ?? Tenant::query()
                    ->whereHas('domains', fn ($q) => $q->where('domain', self::TENANT_DOMAIN))
                    ->first();

            if ($tenant !== null) {
                $modules->grantAll($tenant);

                $ownerUserId = null;
                $tenant->run(function () use (&$ownerUserId): void {
                    foreach (self::DEFAULT_CATEGORY_TREE as $node) {
                        $this->seedCategoryNode($node, null);
                    }
                    $this->seedPrimaryCurrency();
                    app(WalkInCustomerService::class)->ensure();

                    $ownerUserId = User::query()
                        ->where('email', self::OWNER_EMAIL)
                        ->value('id');
                });

                if ($ownerUserId !== null) {
                    BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);
                }

                $tenant->run(function (): void {
                    $this->seedDemoData();
                });

                $this->command?->info(
                    'Tenant ['.$tenant->id.'] already exists - ensured modules and demo data.'
                );
                $this->printDemoCredentials();
            } else {
                $this->command?->info('Tenant domain ['.self::TENANT_DOMAIN.'] already exists - skipping.');
            }

            return;
        }

        $tenant = Tenant::query()->create([
            'id' => self::TENANT_NAME,
            'name' => self::TENANT_NAME,
        ]);

        $tenant->domains()->create([
            'domain' => self::TENANT_DOMAIN,
        ]);

        $ownerUserId = null;

        $tenant->run(function () use (&$ownerUserId): void {
            $user = User::query()->create([
                'name' => self::TENANT_NAME.'_owner',
                'email' => self::OWNER_EMAIL,
                'password' => self::OWNER_PASSWORD,
                'is_active' => true,
            ]);
            $ownerUserId = $user->id;

            foreach (self::DEFAULT_CATEGORY_TREE as $node) {
                $this->seedCategoryNode($node, null);
            }

            $this->seedPrimaryCurrency();
            app(WalkInCustomerService::class)->ensure();
        });

        if ($ownerUserId === null) {
            throw new \RuntimeException('Failed to create tenant owner user.');
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);
        $modules->grantAll($tenant);

        $tenant->run(function (): void {
            $this->seedDemoData();
        });

        $this->command?->info('Tenant ['.self::TENANT_NAME.'] created. Domain: '.self::TENANT_DOMAIN.'.');
        $this->printDemoCredentials();
    }

    private function printDemoCredentials(): void
    {
        $this->command?->info('Owner: '.self::OWNER_EMAIL.' / '.self::OWNER_PASSWORD);
        $this->command?->info('Admin: '.self::ADMIN_EMAIL.' / '.self::ADMIN_PASSWORD);
    }

    /**
     * Demo records that depend on default branch, unit catalog, and Admin role.
     */
    private function seedDemoData(): void
    {
        $this->seedAdminUser();
        $this->seedDemoWarehouses();
        $this->seedDemoCustomer();
        $this->seedDemoSupplier();
        $this->seedDemoItems();
    }

    private function seedAdminUser(): void
    {
        $adminRole = Role::query()->where('name', 'Admin')->first();
        if ($adminRole === null) {
            throw new \RuntimeException('Admin role missing; run BootstrapTenantRbac before demo seed.');
        }

        $admin = User::query()->firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => self::TENANT_NAME.'_admin',
                'password' => self::ADMIN_PASSWORD,
                'is_active' => true,
            ]
        );

        app(BranchService::class)->assignUserToDefaultBranch($admin, (int) $adminRole->id);
        app(PermissionService::class)->invalidateCacheForUser($admin->fresh() ?? $admin);
    }

    private function seedDemoWarehouses(): void
    {
        $branchId = app(BranchService::class)->defaultBranchId();
        $managerId = User::query()->where('email', self::OWNER_EMAIL)->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($managerId === null) {
            throw new \RuntimeException('Cannot seed warehouses without a user to assign as manager.');
        }

        $this->upsertBranchWarehouse(
            shortcut: 'WH-A',
            name: 'Demo Warehouse A',
            branchId: $branchId,
            managerId: $managerId,
            defaults: [
                'is_default' => true,
                'is_default_storage' => true,
            ],
        );

        $this->upsertBranchWarehouse(
            shortcut: 'WH-B',
            name: 'Demo Warehouse B',
            branchId: $branchId,
            managerId: $managerId,
            defaults: [],
        );
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function upsertBranchWarehouse(
        string $shortcut,
        string $name,
        int $branchId,
        string $managerId,
        array $defaults,
    ): void {
        $warehouse = Warehouse::query()->firstOrNew(['shortcut_name' => $shortcut]);

        $warehouse->fill(array_merge([
            'name' => $name,
            'type' => WarehouseType::Branch,
            'branch_id' => $branchId,
            'manager_id' => $warehouse->manager_id ?? $managerId,
            'is_active' => true,
        ], $warehouse->exists ? [] : $defaults));

        // Always keep seeded warehouses on the default branch (idempotent re-runs).
        $warehouse->type = WarehouseType::Branch;
        $warehouse->branch_id = $branchId;
        if ($warehouse->manager_id === null) {
            $warehouse->manager_id = $managerId;
        }

        $warehouse->save();
    }

    private function seedDemoCustomer(): void
    {
        Customer::query()->firstOrCreate(
            ['customer_code' => 'CUST-DEMO01'],
            [
                'name' => 'Demo Customer',
                'email' => 'customer@demo.local',
                'phone' => '+10000000001',
                'type' => CustomerType::Business,
                'status' => CustomerStatus::Active,
                'account_number' => 'ACC-DEMO-001',
                'is_vat_registered' => false,
                'is_exempted' => false,
                'is_system' => false,
            ]
        );
    }

    private function seedDemoSupplier(): void
    {
        Supplier::query()->firstOrCreate(
            ['supplier_code' => 'SUP-DEMO01'],
            [
                'name' => 'Demo Supplier',
                'company_name' => 'Demo Supplier Co.',
                'email' => 'supplier@demo.local',
                'phone' => '+10000000002',
                'is_active' => true,
                'is_vat_registered' => false,
                'is_exempted' => false,
            ]
        );
    }

    private function seedDemoItems(): void
    {
        $itemType = ItemType::query()->firstOrCreate(
            ['code' => 'INVENTORY'],
            ['name' => 'inventory', 'is_system' => true, 'is_active' => true]
        );

        $category = Category::query()->firstOrCreate(
            ['code' => 'DEMO'],
            [
                'name' => 'Demo Items',
                'color' => '#64748B',
                'description' => 'General development items.',
                'is_active' => true,
            ]
        );

        $unitGroup = UnitGroup::query()->firstOrCreate(
            ['code' => 'COUNT'],
            [
                'name' => 'Count / Piece',
                'dimension_type' => DimensionType::Count,
                'is_active' => true,
            ]
        );

        $each = $this->ensureUom($unitGroup->id, 'EA', 'Each', 'ea');
        $box = $this->ensureUom($unitGroup->id, 'BX', 'Box', 'bx');

        $this->seedInventoryItemWithUoms(
            sku: 'DEMO-ITEM-001',
            itemCode: 'DEMO-001',
            name: 'Demo Cola Can',
            description: 'Soft drink can — base Each, purchase Box of 12.',
            itemTypeId: $itemType->id,
            categoryId: $category->id,
            unitGroupId: $unitGroup->id,
            baseUomId: $each->id,
            altUomId: $box->id,
            altFactor: '12',
            salePrice: '1.5000',
            costPrice: '0.9000',
            altSalePrice: '16.0000',
            altCostPrice: '10.0000',
        );

        $this->seedInventoryItemWithUoms(
            sku: 'DEMO-ITEM-002',
            itemCode: 'DEMO-002',
            name: 'Demo Potato Chips',
            description: 'Snack bag — base Each, purchase Box of 24.',
            itemTypeId: $itemType->id,
            categoryId: $category->id,
            unitGroupId: $unitGroup->id,
            baseUomId: $each->id,
            altUomId: $box->id,
            altFactor: '24',
            salePrice: '2.2500',
            costPrice: '1.2000',
            altSalePrice: '48.0000',
            altCostPrice: '26.0000',
        );
    }

    private function ensureUom(int $unitGroupId, string $code, string $name, string $symbol): UnitOfMeasurement
    {
        return UnitOfMeasurement::query()->firstOrCreate(
            [
                'unit_group_id' => $unitGroupId,
                'code' => $code,
            ],
            [
                'name' => $name,
                'symbol' => $symbol,
                'decimal_places' => 0,
                'is_active' => true,
            ]
        );
    }

    private function seedInventoryItemWithUoms(
        string $sku,
        string $itemCode,
        string $name,
        string $description,
        int $itemTypeId,
        int $categoryId,
        int $unitGroupId,
        int $baseUomId,
        int $altUomId,
        string $altFactor,
        string $salePrice,
        string $costPrice,
        string $altSalePrice,
        string $altCostPrice,
    ): void {
        $item = Item::query()->firstOrCreate(
            ['sku' => $sku],
            [
                'name' => $name,
                'item_code' => $itemCode,
                'item_type_id' => $itemTypeId,
                'category_id' => $categoryId,
                'unit_group_id' => $unitGroupId,
                'base_uom_id' => $baseUomId,
                'description' => $description,
                'track_inventory' => true,
                'allow_sale' => true,
                'allow_purchase' => true,
                'is_active' => true,
            ]
        );

        if ($item->base_uom_id === null) {
            $item->update(['base_uom_id' => $baseUomId]);
        }

        ItemUom::query()->firstOrCreate(
            [
                'item_id' => $item->id,
                'uom_id' => $baseUomId,
            ],
            [
                'conversion_factor' => '1',
                'selling_price' => $salePrice,
                'cost_price' => $costPrice,
                'is_base' => true,
                'is_default_sale' => true,
                'is_default_purchase' => false,
            ]
        );

        ItemUom::query()->firstOrCreate(
            [
                'item_id' => $item->id,
                'uom_id' => $altUomId,
            ],
            [
                'conversion_factor' => $altFactor,
                'selling_price' => $altSalePrice,
                'cost_price' => $altCostPrice,
                'is_base' => false,
                'is_default_sale' => false,
                'is_default_purchase' => true,
            ]
        );
    }

    /**
     * @param  array{code:string,name:string,color:string,description:string,is_active:bool,children?:list<array<string,mixed>>}  $node
     */
    private function seedCategoryNode(array $node, ?int $parentId): void
    {
        $category = Category::query()->firstOrCreate(
            ['code' => $node['code']],
            [
                'parent_id' => $parentId,
                'name' => $node['name'],
                'color' => $node['color'],
                'description' => $node['description'],
                'is_active' => $node['is_active'],
            ]
        );

        foreach ($node['children'] ?? [] as $child) {
            $this->seedCategoryNode($child, $category->id);
        }
    }

    private function seedPrimaryCurrency(): void
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'iso_code' => 'USD',
                'symbol' => '$',
                'is_active' => true,
            ]
        );

        CompanySetting::singleton()->update(['primary_currency_id' => $currency->id]);
        TenantReferenceCache::forget(CompanySettingService::CACHE_KEY);
    }
}
