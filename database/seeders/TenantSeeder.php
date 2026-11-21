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
 * Seeds the demo companies with posted opening-stock balances.
 *
 * Spinneys, Kassatly Chtaura, and Liban Lait share one grocery catalog.
 * Sahel Mashghara and Amleya Hypermarket share one dairy catalog.
 * Sabil and Baytouna Market share one beverage catalog.
 * Dayra Bakery and Nahla Cafe, Qarya and Souqna Market, Rif and Wared Market,
 * Nabea and Baladi Grocery, and Ghadeer and Arz Mart each share one catalog.
 * Within a catalog, item codes, prices, units, and currencies match, and each company
 * is a customer and a supplier of the others, so a sales invoice can be
 * linked as a purchase invoice. Wallet addresses are left empty.
 *
 * Password for every seeded user is {@see self::PASSWORD}.
 *
 * Hosts:
 *   127.0.0.1 spinneys.localhost
 *   127.0.0.1 kassatly.localhost
 *   127.0.0.1 libanlait.localhost
 *   127.0.0.1 sahelmashghara.localhost
 *   127.0.0.1 amleya.localhost
 *   127.0.0.1 sabil.localhost
 *   127.0.0.1 baytouna.localhost
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
            $this->command?->warn('The old demo tenant tenant.localhost is still present. This seed adds the demo companies and does not rename that tenant.');
        }

        $modules = app(ModuleEntitlementService::class);
        $grouped = [];
        foreach (self::companies() as $company) {
            $grouped[$company['catalog']][] = $company;
        }

        foreach ($grouped as $companies) {
            foreach ($companies as $company) {
                $peers = array_values(array_filter(
                    $companies,
                    fn (array $other): bool => $other['id'] !== $company['id']
                ));
                $this->seedCompany($company, $peers, $modules);
            }
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
                'catalog' => 'grocery',
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
                'catalog' => 'grocery',
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
                'catalog' => 'grocery',
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
            [
                'id' => 'sahelmashghara',
                'catalog' => 'dairy',
                'name' => 'Sahel Mashghara',
                'legal_name' => 'Sahel Mashghara SAL',
                'domain' => 'sahelmashghara.localhost',
                'owner_email' => 'sahelmashghara.owner@gmail.com',
                'admin_email' => 'sahelmashghara.admin@gmail.com',
                'user_email' => 'sahelmashghara.user@gmail.com',
                'employee_email' => 'sahelmashghara.employee@gmail.com',
                'dc_email' => 'sahelmashghara.dc@gmail.com',
                'accounts_email' => 'sahelmashghara.accounts@gmail.com',
                'sales_email' => 'sahelmashghara.sales@gmail.com',
                'country' => 'LB',
                'country_name' => 'Lebanon',
                'timezone' => 'Asia/Beirut',
                'phone' => '+961 8 650 100',
                'website' => 'https://www.sahelmashghara.com',
                'tax_number' => '601-812001',
                'registration_number' => 'West Bekaa 4410',
                'address' => 'Main Road',
                'city' => 'Mashghara',
                'state' => 'Bekaa',
                'dc_address' => 'Mashghara Industrial Area, West Bekaa, Lebanon',
                'hq_name' => 'Sahel Mashghara Dairy Plant',
                'dc_name' => 'Sahel Mashghara Cold Store',
                'salesmen' => [
                    ['code' => 'SM-01', 'first_name' => 'Karim', 'last_name' => 'Hassan', 'phone' => '+961 3 444 201', 'email' => 'karim.hassan.sahelmashghara@gmail.com'],
                    ['code' => 'SM-02', 'first_name' => 'Lina', 'last_name' => 'Farhat', 'phone' => '+961 3 444 202', 'email' => 'lina.farhat.sahelmashghara@gmail.com'],
                ],
            ],
            [
                'id' => 'amleya',
                'catalog' => 'dairy',
                'name' => 'Amleya Hypermarket',
                'legal_name' => 'Amleya Hypermarket SAL',
                'domain' => 'amleya.localhost',
                'owner_email' => 'amleya.owner@gmail.com',
                'admin_email' => 'amleya.admin@gmail.com',
                'user_email' => 'amleya.user@gmail.com',
                'employee_email' => 'amleya.employee@gmail.com',
                'dc_email' => 'amleya.dc@gmail.com',
                'accounts_email' => 'amleya.accounts@gmail.com',
                'sales_email' => 'amleya.sales@gmail.com',
                'country' => 'LB',
                'country_name' => 'Lebanon',
                'timezone' => 'Asia/Beirut',
                'phone' => '+961 5 450 200',
                'website' => 'https://www.amleya.com',
                'tax_number' => '601-812002',
                'registration_number' => 'Baabda 55210',
                'address' => 'Airport Road',
                'city' => 'Hazmieh',
                'state' => 'Mount Lebanon',
                'dc_address' => 'Hazmieh Logistics Park, Mount Lebanon, Lebanon',
                'hq_name' => 'Amleya Hypermarket',
                'dc_name' => 'Amleya Distribution Center',
                'salesmen' => [
                    ['code' => 'SM-01', 'first_name' => 'Jad', 'last_name' => 'Mansour', 'phone' => '+961 3 555 201', 'email' => 'jad.mansour.amleya@gmail.com'],
                    ['code' => 'SM-02', 'first_name' => 'Sara', 'last_name' => 'Karam', 'phone' => '+961 3 555 202', 'email' => 'sara.karam.amleya@gmail.com'],
                ],
            ],
            [
                'id' => 'sabil',
                'catalog' => 'beverage',
                'name' => 'Sabil',
                'legal_name' => 'Sabil Beverages SAL',
                'domain' => 'sabil.localhost',
                'owner_email' => 'sabil.owner@gmail.com',
                'admin_email' => 'sabil.admin@gmail.com',
                'user_email' => 'sabil.user@gmail.com',
                'employee_email' => 'sabil.employee@gmail.com',
                'dc_email' => 'sabil.dc@gmail.com',
                'accounts_email' => 'sabil.accounts@gmail.com',
                'sales_email' => 'sabil.sales@gmail.com',
                'country' => 'LB',
                'country_name' => 'Lebanon',
                'timezone' => 'Asia/Beirut',
                'phone' => '+961 9 540 300',
                'website' => 'https://www.sabilbeverages.com',
                'tax_number' => '601-813001',
                'registration_number' => 'Jbeil 22018',
                'address' => 'Industrial Road',
                'city' => 'Jbeil',
                'state' => 'Mount Lebanon',
                'dc_address' => 'Jbeil Industrial Zone, Mount Lebanon, Lebanon',
                'hq_name' => 'Sabil Bottling Plant',
                'dc_name' => 'Sabil Distribution Center',
                'salesmen' => [
                    ['code' => 'SM-01', 'first_name' => 'Walid', 'last_name' => 'Daher', 'phone' => '+961 3 666 201', 'email' => 'walid.daher.sabil@gmail.com'],
                    ['code' => 'SM-02', 'first_name' => 'Hala', 'last_name' => 'Boutros', 'phone' => '+961 3 666 202', 'email' => 'hala.boutros.sabil@gmail.com'],
                ],
            ],
            [
                'id' => 'baytouna',
                'catalog' => 'beverage',
                'name' => 'Baytouna Market',
                'legal_name' => 'Baytouna Market SAL',
                'domain' => 'baytouna.localhost',
                'owner_email' => 'baytouna.owner@gmail.com',
                'admin_email' => 'baytouna.admin@gmail.com',
                'user_email' => 'baytouna.user@gmail.com',
                'employee_email' => 'baytouna.employee@gmail.com',
                'dc_email' => 'baytouna.dc@gmail.com',
                'accounts_email' => 'baytouna.accounts@gmail.com',
                'sales_email' => 'baytouna.sales@gmail.com',
                'country' => 'LB',
                'country_name' => 'Lebanon',
                'timezone' => 'Asia/Beirut',
                'phone' => '+961 4 520 800',
                'website' => 'https://www.baytounamarket.com',
                'tax_number' => '601-813002',
                'registration_number' => 'Metn 33044',
                'address' => 'Antelias Highway',
                'city' => 'Antelias',
                'state' => 'Mount Lebanon',
                'dc_address' => 'Dora Logistics Park, Mount Lebanon, Lebanon',
                'hq_name' => 'Baytouna Market',
                'dc_name' => 'Baytouna Distribution Center',
                'salesmen' => [
                    ['code' => 'SM-01', 'first_name' => 'Fadi', 'last_name' => 'Nader', 'phone' => '+961 3 777 201', 'email' => 'fadi.nader.baytouna@gmail.com'],
                    ['code' => 'SM-02', 'first_name' => 'Rana', 'last_name' => 'Chidiac', 'phone' => '+961 3 777 202', 'email' => 'rana.chidiac.baytouna@gmail.com'],
                ],
            ],
            ...self::extraCompanies(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function extraCompanies(): array
    {
        $rows = [
            ['dayra', 'bakery', 'Dayra Bakery', 'Dayra Bakery SAL', '+961 1 240 100', '601-814001', 'Beirut 12041', 'Armenia Street', 'Bourj Hammoud', 'Mount Lebanon', 'Bourj Hammoud Industrial Street, Mount Lebanon, Lebanon', 'Dayra Bakery', 'Dayra Cold Room', 'Tony', 'Karam', '+961 3 811 201', 'Mira', 'Hobeika', '+961 3 811 202'],
            ['nahla', 'bakery', 'Nahla Cafe', 'Nahla Cafe SAL', '+961 1 350 200', '601-814002', 'Beirut 12088', 'Hamra Street', 'Beirut', 'Beirut', 'Hamra Service Street, Beirut, Lebanon', 'Nahla Cafe', 'Nahla Store Room', 'Sami', 'Daouk', '+961 3 811 301', 'Lea', 'Feghali', '+961 3 811 302'],
            ['qarya', 'snacks', 'Qarya', 'Qarya Snacks SAL', '+961 8 545 100', '601-815001', 'Zahle 22110', 'Industrial Road', 'Chtaura', 'Bekaa', 'Chtaura Industrial Zone, Bekaa, Lebanon', 'Qarya Plant', 'Qarya Distribution Center', 'Bassam', 'Itani', '+961 3 812 201', 'Nour', 'Sleiman', '+961 3 812 202'],
            ['souqna', 'snacks', 'Souqna Market', 'Souqna Market SAL', '+961 1 250 300', '601-815002', 'Metn 33120', 'Dora Highway', 'Dora', 'Mount Lebanon', 'Dora Logistics Park, Mount Lebanon, Lebanon', 'Souqna Market', 'Souqna Warehouse', 'Charbel', 'Aoun', '+961 3 812 301', 'Yara', 'Helou', '+961 3 812 302'],
            ['rif', 'home', 'Rif', 'Rif Home SAL', '+961 9 478 100', '601-816001', 'Jbeil 24055', 'Coastal Road', 'Halat', 'Mount Lebanon', 'Halat Industrial Area, Mount Lebanon, Lebanon', 'Rif Plant', 'Rif Distribution Center', 'Joseph', 'Malek', '+961 3 813 201', 'Carla', 'Nehme', '+961 3 813 202'],
            ['wared', 'home', 'Wared Market', 'Wared Market SAL', '+961 1 890 400', '601-816002', 'Metn 33201', 'Main Road', 'Jdeideh', 'Mount Lebanon', 'Jdeideh Warehouse District, Mount Lebanon, Lebanon', 'Wared Market', 'Wared Distribution Center', 'Pierre', 'Hajj', '+961 3 813 301', 'Dana', 'Khoury', '+961 3 813 302'],
            ['nabea', 'coffee', 'Nabea', 'Nabea Roasters SAL', '+961 1 566 100', '601-817001', 'Beirut 12140', 'Gouraud Street', 'Beirut', 'Beirut', 'Bourj Hammoud Roastery, Mount Lebanon, Lebanon', 'Nabea Roastery', 'Nabea Store', 'Elias', 'Frem', '+961 3 814 201', 'Tala', 'Rizk', '+961 3 814 202'],
            ['baladi', 'coffee', 'Baladi Grocery', 'Baladi Grocery SAL', '+961 1 328 200', '601-817002', 'Beirut 12177', 'Sassine Square', 'Beirut', 'Beirut', 'Achrafieh Back Street, Beirut, Lebanon', 'Baladi Grocery', 'Baladi Stock Room', 'Marc', 'Abi Nader', '+961 3 814 301', 'Joy', 'Saliba', '+961 3 814 302'],
            ['ghadeer', 'pantry', 'Ghadeer', 'Ghadeer Oils SAL', '+961 6 955 100', '601-818001', 'Koura 26018', 'Main Road', 'Amioun', 'North Lebanon', 'Koura Industrial Road, North Lebanon, Lebanon', 'Ghadeer Mill', 'Ghadeer Warehouse', 'Antoine', 'Estephan', '+961 3 815 201', 'Rita', 'Mouawad', '+961 3 815 202'],
            ['arzmart', 'pantry', 'Arz Mart', 'Arz Mart SAL', '+961 9 211 500', '601-818002', 'Keserwan 27044', 'Highway', 'Zouk Mosbeh', 'Mount Lebanon', 'Zouk Logistics Park, Mount Lebanon, Lebanon', 'Arz Mart', 'Arz Mart Warehouse', 'Roy', 'Haddad', '+961 3 815 301', 'Celine', 'Geara', '+961 3 815 302'],
        ];

        return array_map(fn (array $row): array => self::extraCompany($row), $rows);
    }

    /**
     * @param  list<string>  $row
     * @return array<string, mixed>
     */
    private static function extraCompany(array $row): array
    {
        [$id, $catalog, $name, $legal, $phone, $tax, $registration, $address, $city, $state, $dcAddress, $hq, $dc, $firstA, $lastA, $phoneA, $firstB, $lastB, $phoneB] = $row;

        $salesman = function (string $code, string $first, string $last, string $salesPhone) use ($id): array {
            $slug = strtolower($first.'.'.$last);

            return [
                'code' => $code,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $salesPhone,
                'email' => str_replace(' ', '', $slug).'.'.$id.'@gmail.com',
            ];
        };

        return [
            'id' => $id,
            'catalog' => $catalog,
            'name' => $name,
            'legal_name' => $legal,
            'domain' => $id.'.localhost',
            'owner_email' => $id.'.owner@gmail.com',
            'admin_email' => $id.'.admin@gmail.com',
            'user_email' => $id.'.user@gmail.com',
            'employee_email' => $id.'.employee@gmail.com',
            'dc_email' => $id.'.dc@gmail.com',
            'accounts_email' => $id.'.accounts@gmail.com',
            'sales_email' => $id.'.sales@gmail.com',
            'country' => 'LB',
            'country_name' => 'Lebanon',
            'timezone' => 'Asia/Beirut',
            'phone' => $phone,
            'website' => 'https://www.'.$id.'.com',
            'tax_number' => $tax,
            'registration_number' => $registration,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'dc_address' => $dcAddress,
            'hq_name' => $hq,
            'dc_name' => $dc,
            'salesmen' => [
                $salesman('SM-01', $firstA, $lastA, $phoneA),
                $salesman('SM-02', $firstB, $lastB, $phoneB),
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
