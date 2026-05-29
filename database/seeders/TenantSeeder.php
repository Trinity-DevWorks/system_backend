<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Category\Models\Category;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\TenantSetting;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Seeds a development tenant: name "tenant", login tenant@gmail.com / 12345678.
 * Access tenant API via domain {@see self::TENANT_DOMAIN} (add to hosts: 127.0.0.1 tenant.localhost).
 */
class TenantSeeder extends Seeder
{
    public const TENANT_NAME = 'tenant';

    public const TENANT_DOMAIN = 'tenant.localhost';

    public const OWNER_EMAIL = 'tenant@gmail.com';

    public const OWNER_PASSWORD = '12345678';

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
        if (Domain::query()->where('domain', self::TENANT_DOMAIN)->exists()) {
            $this->command?->info('Tenant domain ['.self::TENANT_DOMAIN.'] already exists - skipping.');

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
                'active' => true,
            ]);
            $ownerUserId = $user->id;

            foreach (self::DEFAULT_CATEGORY_TREE as $node) {
                $this->seedCategoryNode($node, null);
            }

            $this->seedPrimaryCurrency();
        });

        if ($ownerUserId === null) {
            throw new \RuntimeException('Failed to create tenant owner user.');
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);

        $this->command?->info('Tenant ['.self::TENANT_NAME.'] created. Domain: '.self::TENANT_DOMAIN.'. Owner: '.self::OWNER_EMAIL);
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
                'active' => true,
            ]
        );

        TenantSetting::singleton()->update(['primary_currency_id' => $currency->id]);
    }
}
