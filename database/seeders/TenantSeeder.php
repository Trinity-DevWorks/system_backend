<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Category\Models\Category;
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
     * @var list<array{code:string,name:string,color:string,description:string,is_active:bool}>
     */
    private const DEFAULT_CATEGORIES = [
        [
            'code' => 'BEV-CAR-001',
            'name' => 'Carbonated Beverages',
            'color' => '#1E88E5',
            'description' => 'Soft drinks and sparkling beverages.',
            'is_active' => true,
        ],
        [
            'code' => 'BEV-JUI-002',
            'name' => 'Juices',
            'color' => '#43A047',
            'description' => 'Packaged fruit and mixed juices.',
            'is_active' => true,
        ],
        [
            'code' => 'SNA-CHI-001',
            'name' => 'Chips',
            'color' => '#FB8C00',
            'description' => 'Salted and flavored potato chips.',
            'is_active' => true,
        ],
        [
            'code' => 'SNA-BIS-002',
            'name' => 'Biscuits',
            'color' => '#8D6E63',
            'description' => 'Sweet and savory biscuits.',
            'is_active' => true,
        ],
        [
            'code' => 'FRU-FRE-001',
            'name' => 'Fresh Fruits',
            'color' => '#7CB342',
            'description' => 'Fresh fruit products.',
            'is_active' => true,
        ],
        [
            'code' => 'DAI-MLK-001',
            'name' => 'Milk Products',
            'color' => '#5C6BC0',
            'description' => 'Milk and milk-based items.',
            'is_active' => true,
        ],
        [
            'code' => 'FRO-ICE-001',
            'name' => 'Frozen Foods',
            'color' => '#26C6DA',
            'description' => 'Frozen products and ice cream.',
            'is_active' => true,
        ],
        [
            'code' => 'CLE-HOU-001',
            'name' => 'Household Cleaning',
            'color' => '#EF5350',
            'description' => 'Cleaning and hygiene household products.',
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

            foreach (self::DEFAULT_CATEGORIES as $category) {
                Category::query()->firstOrCreate(
                    ['code' => $category['code']],
                    $category
                );
            }
        });

        if ($ownerUserId === null) {
            throw new \RuntimeException('Failed to create tenant owner user.');
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);

        $this->command?->info('Tenant ['.self::TENANT_NAME.'] created. Domain: '.self::TENANT_DOMAIN.'. Owner: '.self::OWNER_EMAIL);
    }
}
