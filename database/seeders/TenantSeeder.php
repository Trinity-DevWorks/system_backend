<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ModuleEntitlementService;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Seeds three Lebanese trading companies with the same items, currencies,
 * and posted opening-stock balances.
 *
 * Spinneys, Kassatly Chtaura, and Liban Lait each appear as a customer and a
 * supplier of the others, so a sales invoice can be linked as a purchase
 * invoice. Wallet addresses are left empty.
 *
 * Password for every seeded user is {@see self::PASSWORD}.
 *
 * Hosts:
 *   127.0.0.1 spinneys.localhost
 *   127.0.0.1 kassatly.localhost
 *   127.0.0.1 libanlait.localhost
 */
class TenantSeeder extends Seeder
{
    public const TENANT_NAME = 'spinneys';

    public const TENANT_DOMAIN = 'spinneys.localhost';

    public const OWNER_EMAIL = 'spinneys.owner@gmail.com';

    public const OWNER_PASSWORD = '12345678';

    public const ADMIN_EMAIL = 'spinneys.admin@gmail.com';

    public const ADMIN_PASSWORD = '12345678';

    public const PASSWORD = '12345678';

    public function run(): void
    {
        if (Domain::query()->where('domain', 'tenant.localhost')->exists()) {
            $this->command?->warn('The old demo tenant tenant.localhost is still present. This seed adds Spinneys, Kassatly Chtaura, and Liban Lait and does not rename that tenant.');
        }

        $modules = app(ModuleEntitlementService::class);
        $companies = self::companies();

        foreach ($companies as $company) {
            $peers = array_values(array_filter(
                $companies,
                fn (array $other): bool => $other['id'] !== $company['id']
            ));
            $this->seedCompany($company, $peers, $modules);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function companies(): array
    {
        return [
            [
                'id' => 'spinneys',
                'name' => 'Spinneys',
                'legal_name' => 'Spinneys Lebanon SAL',
                'domain' => 'spinneys.localhost',
                'owner_email' => 'spinneys.owner@gmail.com',
                'admin_email' => 'spinneys.admin@gmail.com',
                'user_email' => 'spinneys.user@gmail.com',
                'employee_email' => 'spinneys.employee@gmail.com',
                'dc_email' => 'spinneys.dc@gmail.com',
                'accounts_email' => 'spinneys.accounts@gmail.com',
                'sales_email' => 'spinneys.sales@gmail.com',
                'country' => 'LB',
                'country_name' => 'Lebanon',
                'timezone' => 'Asia/Beirut',
                'phone' => '+961 4 403 000',
                'website' => 'https://www.spinneyslebanon.com',
                'tax_number' => '601-234567',
                'registration_number' => 'Beirut 67231',
                'address' => 'Dbayeh Highway',
                'city' => 'Dbayeh',
                'state' => 'Mount Lebanon',
                'dc_address' => 'Mkalles Industrial Area, Mount Lebanon, Lebanon',
                'hq_name' => 'Spinneys Headquarters',
                'dc_name' => 'Spinneys Distribution Center',
                'salesmen' => [
                    ['code' => 'SM-01', 'first_name' => 'Rami', 'last_name' => 'Khoury', 'phone' => '+961 3 111 201', 'email' => 'rami.khoury.spinneys@gmail.com'],
                    ['code' => 'SM-02', 'first_name' => 'Nadine', 'last_name' => 'Saad', 'phone' => '+961 3 111 202', 'email' => 'nadine.saad.spinneys@gmail.com'],
                ],
            ],
            [
                'id' => 'kassatly',
                'name' => 'Kassatly Chtaura',
                'legal_name' => 'Kassatly Chtaura SAL',
                'domain' => 'kassatly.localhost',
                'owner_email' => 'kassatly.owner@gmail.com',
                'admin_email' => 'kassatly.admin@gmail.com',
                'user_email' => 'kassatly.user@gmail.com',
                'employee_email' => 'kassatly.employee@gmail.com',
                'dc_email' => 'kassatly.dc@gmail.com',
                'accounts_email' => 'kassatly.accounts@gmail.com',
                'sales_email' => 'kassatly.sales@gmail.com',
                'country' => 'LB',
                'country_name' => 'Lebanon',
                'timezone' => 'Asia/Beirut',
                'phone' => '+961 8 540 000',
                'website' => 'https://www.kassatlychtaura.com',
                'tax_number' => '601-345678',
                'registration_number' => 'Zahle 18420',
                'address' => 'Main Road',
                'city' => 'Chtaura',
                'state' => 'Bekaa',
                'dc_address' => 'Chtaura Industrial Zone, Bekaa, Lebanon',
                'hq_name' => 'Kassatly Chtaura Headquarters',
                'dc_name' => 'Kassatly Chtaura Distribution Center',
                'salesmen' => [
                    ['code' => 'SM-01', 'first_name' => 'Elie', 'last_name' => 'Haddad', 'phone' => '+961 3 222 201', 'email' => 'elie.haddad.kassatly@gmail.com'],
                    ['code' => 'SM-02', 'first_name' => 'Maya', 'last_name' => 'Nassar', 'phone' => '+961 3 222 202', 'email' => 'maya.nassar.kassatly@gmail.com'],
                ],
            ],
            [
                'id' => 'libanlait',
                'name' => 'Liban Lait',
                'legal_name' => 'Liban Lait SAL',
                'domain' => 'libanlait.localhost',
                'owner_email' => 'libanlait.owner@gmail.com',
                'admin_email' => 'libanlait.admin@gmail.com',
                'user_email' => 'libanlait.user@gmail.com',
                'employee_email' => 'libanlait.employee@gmail.com',
                'dc_email' => 'libanlait.dc@gmail.com',
                'accounts_email' => 'libanlait.accounts@gmail.com',
                'sales_email' => 'libanlait.sales@gmail.com',
                'country' => 'LB',
                'country_name' => 'Lebanon',
                'timezone' => 'Asia/Beirut',
                'phone' => '+961 8 370 100',
                'website' => 'https://www.libanlait.com',
                'tax_number' => '601-456789',
                'registration_number' => 'Baalbek 9033',
                'address' => 'Industrial Zone',
                'city' => 'Baalbek',
                'state' => 'Baalbek-Hermel',
                'dc_address' => 'Zahle Industrial City, Bekaa, Lebanon',
                'hq_name' => 'Liban Lait Headquarters',
                'dc_name' => 'Liban Lait Distribution Center',
                'salesmen' => [
                    ['code' => 'SM-01', 'first_name' => 'Georges', 'last_name' => 'Frem', 'phone' => '+961 3 333 201', 'email' => 'georges.frem.libanlait@gmail.com'],
                    ['code' => 'SM-02', 'first_name' => 'Rita', 'last_name' => 'Abi Aad', 'phone' => '+961 3 333 202', 'email' => 'rita.abiaad.libanlait@gmail.com'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  list<array<string, mixed>>  $peers
     */
    private function seedCompany(array $company, array $peers, ModuleEntitlementService $modules): void
    {
        $tenant = $this->ensureTenant($company);
        $modules->grantAll($tenant);

        $ownerUserId = null;
        $tenant->run(function () use (&$ownerUserId, $company): void {
            $user = User::query()->firstOrCreate(
                ['email' => $company['owner_email']],
                [
                    'name' => $company['name'].' Owner',
                    'password' => self::PASSWORD,
                    'is_active' => true,
                ]
            );
            $ownerUserId = $user->id;
        });

        if ($ownerUserId === null) {
            throw new \RuntimeException('Failed to create the owner for '.$company['name'].'.');
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);

        $tenant->run(function () use ($company, $peers): void {
            app(DemoMasterDataSeeder::class)->seed($company, $peers);
        });

        $this->command?->info($company['name'].' ['.$company['id'].'] ready at '.$company['domain'].'.');
        $this->command?->info('  Owner: '.$company['owner_email'].' / '.self::PASSWORD);
        $this->command?->info('  Admin: '.$company['admin_email'].' / '.self::PASSWORD);
        $this->command?->info('  User: '.$company['user_email'].' / '.self::PASSWORD);
        $this->command?->info('  Employee: '.$company['employee_email'].' / '.self::PASSWORD);
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function ensureTenant(array $company): Tenant
    {
        $existing = Tenant::query()->whereKey($company['id'])->first();
        if ($existing === null) {
            $existing = Tenant::query()
                ->whereHas('domains', fn ($query) => $query->where('domain', $company['domain']))
                ->first();
        }

        if ($existing !== null) {
            $existing->update(['name' => $company['name']]);
            if (! Domain::query()->where('domain', $company['domain'])->exists()) {
                $existing->domains()->create(['domain' => $company['domain']]);
            }

            return $existing;
        }

        $tenant = Tenant::query()->create([
            'id' => $company['id'],
            'name' => $company['name'],
        ]);
        $tenant->domains()->create([
            'domain' => $company['domain'],
        ]);

        return $tenant;
    }
}
