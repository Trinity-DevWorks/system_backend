<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
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

    public function run(): void
    {
        if (Domain::query()->where('domain', self::TENANT_DOMAIN)->exists()) {
            $this->command?->info('Tenant domain ['.self::TENANT_DOMAIN.'] already exists — skipping.');

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
        });

        if ($ownerUserId === null) {
            throw new \RuntimeException('Failed to create tenant owner user.');
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);

        $this->command?->info('Tenant ['.self::TENANT_NAME.'] created. Domain: '.self::TENANT_DOMAIN.'. Owner: '.self::OWNER_EMAIL);
    }
}
