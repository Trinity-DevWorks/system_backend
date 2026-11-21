<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Brand\Models\Brand;
use App\Modules\Category\Models\Category;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanyProfile\Services\CompanyProfileService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\CurrencyPairRate;
use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Enums\CustomerType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Models\CustomerContact;
use App\Modules\Customer\Models\CustomerGroup;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Item\Models\RecipeItem;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Enums\OpeningStockStatus;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Inventory\Stock\Services\OpeningStockService;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Salesman\Enums\CommissionType;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierAddress;
use App\Modules\Supplier\Models\SupplierContact;
use App\Modules\Supplier\Models\SupplierGroup;
use App\Modules\Supplier\Models\SupplierItem;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\Central\TenantCompanyWalletService;
use App\Services\PermissionService;
use App\Support\TenantReferenceCache;

/**
 * Master data and inventory catalogs shared by the demo companies.
 *
 * Grocery companies share one catalog. Sahel Mashghara and Amleya Hypermarket
 * share a dairy catalog. Sabil and Baytouna Market share a beverage catalog.
 * Dayra and Nahla, Qarya and Souqna, Rif and Wared, Nabea and Baladi, and
 * Ghadeer and Arz Mart each share one more catalog. Within a catalog, item codes, UOM codes,
 * prices, and currency codes are identical so a sales-invoice proof can be
 * linked as a purchase invoice in the buyer company.
 *
 * Posted opening stock fills on-hand balances in HQ and DC warehouses.
 * Does not seed invoices, receipts, payments, or other stock documents.
 */
class DemoMasterDataSeeder
{
    private string $catalog = 'grocery';
    /**
     * @param  array<string, mixed>  $company
     * @param  list<array<string, mixed>>  $peers
     */
    public function seed(array $company, array $peers): void
    {
        $this->catalog = (string) ($company['catalog'] ?? 'grocery');

        $branches = $this->seedBranches($company);
        $this->seedRolesAndUsers($company, $branches);
        $warehouses = $this->seedWarehouses($company, $branches);
        $this->seedCompanyProfile($company);
        $currencies = $this->seedCurrencies($company);
        $vatId = $this->seedVatGroups();
        $payment = $this->seedPaymentCatalog($currencies['USD']);
        $groups = $this->seedPartyGroups();
        $this->seedCategories();
        $this->seedBrands();
        $items = $this->seedItems($vatId);
        $this->seedItemStructures($items);
        $this->seedReplenishment($items, $warehouses);
        $this->seedParties($company, $peers, $groups, $payment, $items);
        $this->seedSalesmen($company, $branches['hq'], $warehouses['hq']);
        $this->seedOpeningStock($company, $items, $warehouses);
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array{hq: Branch, dc: Branch}
     */
    private function seedBranches(array $company): array
    {
        $ownerId = User::query()->where('email', $company['owner_email'])->value('id');

        $hq = Branch::query()->where('is_default', true)->first()
            ?? Branch::query()->where('shortcut_name', 'HQ')->first();

        if ($hq === null) {
            $hq = new Branch;
            $hq->is_default = true;
        }

        $hq->fill([
            'name' => $company['hq_name'],
            'shortcut_name' => 'HQ',
            'address' => $company['address'],
            'phone' => $company['phone'],
            'email' => $company['owner_email'],
            'timezone' => $company['timezone'],
            'opening_time' => '08:00:00',
            'closing_time' => '18:00:00',
            'manager_id' => $ownerId,
            'is_active' => true,
            'is_default' => true,
        ]);
        $hq->save();

        $dc = Branch::query()->updateOrCreate(
            ['shortcut_name' => 'DC'],
            [
                'name' => $company['dc_name'],
                'address' => $company['dc_address'],
                'phone' => $company['phone'],
                'email' => $company['dc_email'],
                'timezone' => $company['timezone'],
                'opening_time' => '06:00:00',
                'closing_time' => '22:00:00',
                'manager_id' => $ownerId,
                'is_active' => true,
                'is_default' => false,
            ]
        );

        TenantReferenceCache::forget('branches.list');

        return ['hq' => $hq, 'dc' => $dc];
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array{hq: Branch, dc: Branch}  $branches
     */
    private function seedRolesAndUsers(array $company, array $branches): void
    {
        $userRole = Role::query()->updateOrCreate(
            ['name' => 'User'],
            ['description' => 'Read-only access to company data', 'is_active' => true]
        );
        $employeeRole = Role::query()->updateOrCreate(
            ['name' => 'Employee'],
            ['description' => 'Day-to-day master data and inventory, without user administration', 'is_active' => true]
        );

        $this->grant($userRole, $this->viewOnly());
        $this->grant($employeeRole, $this->employeeAccess());

        $adminRole = Role::query()->where('name', 'Admin')->firstOrFail();
        $branchIds = [$branches['hq']->id, $branches['dc']->id];

        $this->ensureUser(
            $company['admin_email'],
            $company['name'].' Admin',
            (int) $adminRole->id,
            $branchIds,
        );
        $this->ensureUser(
            $company['user_email'],
            $company['name'].' User',
            (int) $userRole->id,
            $branchIds,
        );
        $this->ensureUser(
            $company['employee_email'],
            $company['name'].' Employee',
            (int) $employeeRole->id,
            $branchIds,
        );

        $owner = User::query()->where('email', $company['owner_email'])->first();
        $ownerRole = Role::query()->where('name', 'Owner')->first();
        if ($owner !== null && $ownerRole !== null) {
            $owner->update(['name' => $company['name'].' Owner']);
            $this->assignBranches($owner, (int) $ownerRole->id, $branchIds);
        }

        TenantReferenceCache::forget('roles.list');
    }

    /**
     * @param  array<string, array<string, bool>>  $byResource
     */
    private function grant(Role $role, array $byResource): void
    {
        $empty = [
            'can_view' => false,
            'can_add' => false,
            'can_edit' => false,
            'can_delete' => false,
            'can_import' => false,
            'can_export' => false,
            'can_reverse' => false,
        ];

        foreach (Permission::query()->get() as $permission) {
            RolePermission::query()->updateOrCreate(
                [
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                ],
                $byResource[$permission->resource_key] ?? $empty
            );
        }
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function viewOnly(): array
    {
        $hidden = ['users', 'roles', 'permissions', 'audits', 'company_settings'];
        $flags = [];
        foreach (array_keys(config('rbac.resources', [])) as $resource) {
            if (in_array($resource, $hidden, true)) {
                continue;
            }
            $flags[$resource] = [
                'can_view' => true,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_import' => false,
                'can_export' => false,
                'can_reverse' => false,
            ];
        }

        return $flags;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function employeeAccess(): array
    {
        $maintain = [
            'brands',
            'categories',
            'vat_groups',
            'payment_methods',
            'payment_terms',
            'warehouses',
            'salesmen',
            'items',
            'customer_groups',
            'customers',
            'supplier_groups',
            'suppliers',
            'unit_groups',
            'unit_of_measurements',
            'notifications',
        ];
        $view = [
            'currencies',
            'stock',
            'company_profile',
            'sales_invoices',
            'sales_credit_notes',
            'invoice_proofs',
            'customer_receipts',
            'purchase_invoices',
            'supplier_payments',
        ];

        $flags = [];
        foreach ($maintain as $resource) {
            $flags[$resource] = [
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => false,
                'can_import' => false,
                'can_export' => true,
                'can_reverse' => false,
            ];
        }
        foreach ($view as $resource) {
            $flags[$resource] = [
                'can_view' => true,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_import' => false,
                'can_export' => false,
                'can_reverse' => false,
            ];
        }

        return $flags;
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function ensureUser(string $email, string $name, int $roleId, array $branchIds): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => TenantSeeder::PASSWORD,
                'is_active' => true,
            ]
        );
        $user->update(['name' => $name, 'is_active' => true]);
        $this->assignBranches($user, $roleId, $branchIds);
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function assignBranches(User $user, int $roleId, array $branchIds): void
    {
        $payload = [];
        foreach ($branchIds as $branchId) {
            $payload[$branchId] = ['role_id' => $roleId];
        }
        $user->branches()->syncWithoutDetaching($payload);
        foreach ($branchIds as $branchId) {
            $user->branches()->updateExistingPivot($branchId, ['role_id' => $roleId]);
        }
        app(PermissionService::class)->invalidateCacheForUser($user->fresh() ?? $user);
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array{hq: Branch, dc: Branch}  $branches
     * @return array{hq: Warehouse, dc: Warehouse}
     */
    private function seedWarehouses(array $company, array $branches): array
    {
        $managerId = User::query()->where('email', $company['owner_email'])->value('id');
        if ($managerId === null) {
            throw new \RuntimeException('Cannot seed warehouses without the company owner.');
        }

        $hq = Warehouse::query()->updateOrCreate(
            ['shortcut_name' => 'WH-HQ'],
            [
                'name' => $company['name'].' Main Warehouse',
                'type' => WarehouseType::Branch,
                'branch_id' => $branches['hq']->id,
                'address' => $company['address'],
                'description' => 'Primary storage and shipping warehouse.',
                'manager_id' => $managerId,
                'is_active' => true,
                'is_default' => true,
                'is_default_sales' => true,
                'is_default_purchase' => true,
                'is_default_storage' => true,
                'is_default_production' => true,
            ]
        );

        $dc = Warehouse::query()->updateOrCreate(
            ['shortcut_name' => 'WH-DC'],
            [
                'name' => $company['name'].' Distribution Warehouse',
                'type' => WarehouseType::Distribution,
                'branch_id' => $branches['dc']->id,
                'address' => $company['dc_address'],
                'description' => 'Regional distribution warehouse.',
                'manager_id' => $managerId,
                'is_active' => true,
                'is_default' => false,
                'is_default_sales' => false,
                'is_default_purchase' => false,
                'is_default_storage' => false,
                'is_default_production' => false,
            ]
        );

        return ['hq' => $hq, 'dc' => $dc];
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function seedCompanyProfile(array $company): void
    {
        $profile = CompanyProfile::singleton();
        $profile->update([
            'company_name' => $company['name'],
            'legal_name' => $company['legal_name'],
            'phone' => $company['phone'],
            'email' => $company['owner_email'],
            'website' => $company['website'],
            'tax_number' => $company['tax_number'],
            'registration_number' => $company['registration_number'],
            'address' => $company['address'],
            'wallet_address_anvil' => null,
            'wallet_type_anvil' => null,
            'wallet_address_sepolia' => null,
            'wallet_type_sepolia' => null,
        ]);

        app(TenantCompanyWalletService::class)->syncFromProfile($profile->fresh() ?? $profile);
        TenantReferenceCache::forget(CompanyProfileService::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array<string, Currency>
     */
    private function seedCurrencies(array $company): array
    {
        $definitions = [
            'USD' => ['name' => 'US Dollar', 'symbol' => '$'],
            'EUR' => ['name' => 'Euro', 'symbol' => '€'],
            'GBP' => ['name' => 'British Pound', 'symbol' => '£'],
        ];

        $currencies = [];
        foreach ($definitions as $code => $row) {
            $currencies[$code] = Currency::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $row['name'],
                    'iso_code' => $code,
                    'symbol' => $row['symbol'],
                    'smallest_unit' => '0.010000',
                    'round_limit' => '0.010000',
                    'acceptable_amount_overdue' => '1.0000',
                    'allowed_difference_in_receipt' => '0.0500',
                    'allowed_difference_in_payment' => '0.0500',
                    'is_active' => true,
                ]
            );
        }

        $rates = [
            ['USD', 'EUR', '0.920000000000'],
            ['EUR', 'USD', '1.086956521739'],
            ['USD', 'GBP', '0.780000000000'],
            ['GBP', 'USD', '1.282051282051'],
            ['EUR', 'GBP', '0.847826086957'],
            ['GBP', 'EUR', '1.179487179487'],
        ];
        foreach ($rates as [$from, $to, $rate]) {
            CurrencyPairRate::query()->updateOrCreate(
                [
                    'from_currency_id' => $currencies[$from]->id,
                    'to_currency_id' => $currencies[$to]->id,
                ],
                [
                    'rate' => $rate,
                    'effective_from' => '2026-01-01 00:00:00',
                ]
            );
        }

        CompanySetting::singleton()->update([
            'primary_currency_id' => $currencies['USD']->id,
            'country' => $company['country'],
            'timezone' => $company['timezone'],
            'tax_enabled' => true,
            'invoice_proofs_enabled' => true,
        ]);
        TenantReferenceCache::forget(CompanySettingService::CACHE_KEY);

        return $currencies;
    }

    private function seedVatGroups(): int
    {
        VatGroup::query()->where('abrv', '!=', 'STD')->update(['is_default' => false]);

        $standard = VatGroup::query()->updateOrCreate(
            ['abrv' => 'STD'],
            [
                'name' => 'Standard VAT',
                'percentage' => '11.00',
                'is_default' => true,
                'is_active' => true,
            ]
        );
        VatGroup::query()->updateOrCreate(
            ['abrv' => 'ZERO'],
            [
                'name' => 'Zero rated',
                'percentage' => '0.00',
                'is_default' => false,
                'is_active' => true,
            ]
        );

        return (int) $standard->id;
    }

    /**
     * @return array{cash: int, card: int, bank: int, due: int, net30: int}
     */
    private function seedPaymentCatalog(Currency $usd): array
    {
        PaymentMethod::query()->where('code', '!=', 'CASH')->update(['is_default' => false]);

        $cash = PaymentMethod::query()->updateOrCreate(
            ['code' => 'CASH'],
            [
                'name' => 'Cash',
                'type' => 'cash',
                'currency_id' => $usd->id,
                'requires_reference' => false,
                'supports_change' => true,
                'is_default' => true,
                'is_active' => true,
                'notes' => 'Counter cash.',
            ]
        );
        $card = PaymentMethod::query()->updateOrCreate(
            ['code' => 'CARD'],
            [
                'name' => 'Card',
                'type' => 'card',
                'currency_id' => $usd->id,
                'requires_reference' => true,
                'supports_change' => false,
                'is_default' => false,
                'is_active' => true,
                'notes' => 'Visa, Mastercard, and local debit cards.',
            ]
        );
        $bank = PaymentMethod::query()->updateOrCreate(
            ['code' => 'BANK'],
            [
                'name' => 'Bank transfer',
                'type' => 'bank_transfer',
                'currency_id' => $usd->id,
                'requires_reference' => true,
                'supports_change' => false,
                'is_default' => false,
                'is_active' => true,
                'notes' => 'Company bank transfer.',
            ]
        );
        PaymentMethod::query()->updateOrCreate(
            ['code' => 'CHQ'],
            [
                'name' => 'Cheque',
                'type' => 'cheque',
                'currency_id' => $usd->id,
                'requires_reference' => true,
                'supports_change' => false,
                'is_default' => false,
                'is_active' => true,
                'notes' => null,
            ]
        );

        PaymentTerm::query()->where('code', '!=', 'DUE0')->update(['is_default' => false]);
        $due = PaymentTerm::query()->updateOrCreate(
            ['code' => 'DUE0'],
            [
                'name' => 'Due on receipt',
                'due_days' => 0,
                'description' => 'Payable when the invoice is received.',
                'is_default' => true,
                'is_active' => true,
            ]
        );
        PaymentTerm::query()->updateOrCreate(
            ['code' => 'NET15'],
            [
                'name' => 'Net 15',
                'due_days' => 15,
                'description' => 'Payable within 15 days.',
                'is_default' => false,
                'is_active' => true,
            ]
        );
        $net30 = PaymentTerm::query()->updateOrCreate(
            ['code' => 'NET30'],
            [
                'name' => 'Net 30',
                'due_days' => 30,
                'description' => 'Payable within 30 days.',
                'is_default' => false,
                'is_active' => true,
            ]
        );
        PaymentTerm::query()->updateOrCreate(
            ['code' => 'NET60'],
            [
                'name' => 'Net 60',
                'due_days' => 60,
                'description' => 'Payable within 60 days.',
                'is_default' => false,
                'is_active' => true,
            ]
        );

        return [
            'cash' => (int) $cash->id,
            'card' => (int) $card->id,
            'bank' => (int) $bank->id,
            'due' => (int) $due->id,
            'net30' => (int) $net30->id,
        ];
    }

    /**
     * @return array{key: int, retail: int, wholesale: int, horeca: int, mfg: int, pkg: int, dist: int}
     */
    private function seedPartyGroups(): array
    {
        $key = CustomerGroup::query()->updateOrCreate(
            ['code' => 'KEY'],
            ['name' => 'Key accounts', 'is_active' => true]
        );
        $retail = CustomerGroup::query()->updateOrCreate(
            ['code' => 'RETAIL'],
            ['name' => 'Retail chains', 'is_active' => true]
        );
        CustomerGroup::query()->updateOrCreate(
            ['code' => 'WHOLESALE'],
            ['name' => 'Wholesalers', 'is_active' => true]
        );
        $horeca = CustomerGroup::query()->updateOrCreate(
            ['code' => 'HORECA'],
            ['name' => 'Hotels and restaurants', 'is_active' => true]
        );
        $mfg = SupplierGroup::query()->updateOrCreate(
            ['code' => 'MFG'],
            ['name' => 'Manufacturers', 'is_active' => true]
        );
        $pkg = SupplierGroup::query()->updateOrCreate(
            ['code' => 'PKG'],
            ['name' => 'Packaging', 'is_active' => true]
        );
        $dist = SupplierGroup::query()->updateOrCreate(
            ['code' => 'DIST'],
            ['name' => 'Distributors', 'is_active' => true]
        );

        return [
            'key' => (int) $key->id,
            'retail' => (int) $retail->id,
            'wholesale' => (int) CustomerGroup::query()->where('code', 'WHOLESALE')->value('id'),
            'horeca' => (int) $horeca->id,
            'mfg' => (int) $mfg->id,
            'pkg' => (int) $pkg->id,
            'dist' => (int) $dist->id,
        ];
    }

    private function seedCategories(): void
    {
        if ($this->catalog === 'dairy') {
            foreach ($this->dairyCategoryTree() as $node) {
                $this->seedCategoryNode($node, null);
            }

            return;
        }

        if ($this->catalog === 'beverage') {
            foreach ($this->beverageCategoryTree() as $node) {
                $this->seedCategoryNode($node, null);
            }

            return;
        }

        $trade = DemoTradeCatalogs::find($this->catalog);
        if ($trade !== null) {
            foreach ($trade['categories'] as $node) {
                $this->seedCategoryNode($node, null);
            }

            return;
        }

        $tree = [
            [
                'code' => 'BEV',
                'name' => 'Beverages',
                'color' => '#1E88E5',
                'description' => 'Drinks sold by the piece or case.',
                'children' => [
                    [
                        'code' => 'BEV-COLA',
                        'name' => 'Cola',
                        'color' => '#5D4037',
                        'description' => 'Cola soft drinks.',
                    ],
                    [
                        'code' => 'BEV-WATER',
                        'name' => 'Water',
                        'color' => '#039BE5',
                        'description' => 'Bottled still and sparkling water.',
                    ],
                    [
                        'code' => 'BEV-COFFEE',
                        'name' => 'Coffee',
                        'color' => '#6D4C41',
                        'description' => 'Instant and roast coffee.',
                    ],
                    [
                        'code' => 'BEV-JUICE',
                        'name' => 'Juice',
                        'color' => '#FDD835',
                        'description' => 'Fruit juices and nectars.',
                    ],
                    [
                        'code' => 'BEV-ENERGY',
                        'name' => 'Energy drinks',
                        'color' => '#8E24AA',
                        'description' => 'Energy and sports drinks.',
                    ],
                ],
            ],
            [
                'code' => 'SNA',
                'name' => 'Snacks',
                'color' => '#FB8C00',
                'description' => 'Sweet and savory snacks.',
                'children' => [
                    [
                        'code' => 'SNA-CHI',
                        'name' => 'Chips',
                        'color' => '#F57C00',
                        'description' => 'Potato and corn chips.',
                    ],
                    [
                        'code' => 'SNA-CHO',
                        'name' => 'Chocolate',
                        'color' => '#8D6E63',
                        'description' => 'Chocolate bars and wafers.',
                    ],
                    [
                        'code' => 'SNA-BIS',
                        'name' => 'Biscuits',
                        'color' => '#D4A373',
                        'description' => 'Biscuits and cookies.',
                    ],
                ],
            ],
            [
                'code' => 'DAI',
                'name' => 'Dairy',
                'color' => '#5C6BC0',
                'description' => 'Milk and milk powders.',
                'children' => [
                    [
                        'code' => 'DAI-PWD',
                        'name' => 'Milk powder',
                        'color' => '#7986CB',
                        'description' => 'Powdered milk.',
                    ],
                    [
                        'code' => 'DAI-MLK',
                        'name' => 'Fresh milk',
                        'color' => '#9FA8DA',
                        'description' => 'Fresh and UHT milk.',
                    ],
                    [
                        'code' => 'DAI-YOG',
                        'name' => 'Yogurt and labneh',
                        'color' => '#C5CAE9',
                        'description' => 'Laban, labneh, and yogurt.',
                    ],
                    [
                        'code' => 'DAI-CHS',
                        'name' => 'Cheese and butter',
                        'color' => '#FFCC80',
                        'description' => 'Cheese, butter, and spreads.',
                    ],
                ],
            ],
            [
                'code' => 'GRO',
                'name' => 'Grocery',
                'color' => '#43A047',
                'description' => 'Dry grocery and pantry staples.',
                'children' => [
                    [
                        'code' => 'GRO-OIL',
                        'name' => 'Oil',
                        'color' => '#F9A825',
                        'description' => 'Cooking oils.',
                    ],
                    [
                        'code' => 'GRO-DRY',
                        'name' => 'Dry goods',
                        'color' => '#7CB342',
                        'description' => 'Rice, pasta, flour, and sugar.',
                    ],
                    [
                        'code' => 'GRO-CAN',
                        'name' => 'Canned goods',
                        'color' => '#8D6E63',
                        'description' => 'Canned fish, tomato, and vegetables.',
                    ],
                    [
                        'code' => 'GRO-TEA',
                        'name' => 'Tea',
                        'color' => '#558B2F',
                        'description' => 'Tea bags and loose tea.',
                    ],
                ],
            ],
            [
                'code' => 'HPC',
                'name' => 'Household and personal care',
                'color' => '#26A69A',
                'description' => 'Cleaning and personal care.',
                'children' => [
                    [
                        'code' => 'HPC-CLN',
                        'name' => 'Cleaning',
                        'color' => '#00897B',
                        'description' => 'Laundry and dishwashing.',
                    ],
                    [
                        'code' => 'HPC-PC',
                        'name' => 'Personal care',
                        'color' => '#4DB6AC',
                        'description' => 'Soap, shampoo, and tissue.',
                    ],
                ],
            ],
            [
                'code' => 'BKY',
                'name' => 'Bakery',
                'color' => '#A1887F',
                'description' => 'Bread and toast.',
            ],
            [
                'code' => 'PKG',
                'name' => 'Packaging',
                'color' => '#78909C',
                'description' => 'Shipping and display packaging.',
            ],
        ];

        foreach ($tree as $node) {
            $this->seedCategoryNode($node, null);
        }
    }

    /**
     * @return list<array{code: string, name: string, color: string, description: string, children?: list<array<string, mixed>>}>
     */
    private function dairyCategoryTree(): array
    {
        return [
            [
                'code' => 'DAI',
                'name' => 'Dairy',
                'color' => '#FDD835',
                'description' => 'Milk and dairy produced by Sahel Mashghara.',
                'children' => [
                    [
                        'code' => 'DAI-MLK',
                        'name' => 'Milk',
                        'color' => '#FFF9C4',
                        'description' => 'Fresh, UHT, and flavored milk.',
                    ],
                    [
                        'code' => 'DAI-YOG',
                        'name' => 'Laban, labneh, and yogurt',
                        'color' => '#FFF59D',
                        'description' => 'Laban, ayran, labneh, and yogurt.',
                    ],
                    [
                        'code' => 'DAI-CHS',
                        'name' => 'Cheese and butter',
                        'color' => '#FFE082',
                        'description' => 'White cheese, halloumi, akkawi, and butter.',
                    ],
                    [
                        'code' => 'DAI-CRM',
                        'name' => 'Cream',
                        'color' => '#FFECB3',
                        'description' => 'Fresh, cooking, and whipping cream.',
                    ],
                    [
                        'code' => 'DAI-ICE',
                        'name' => 'Ice cream',
                        'color' => '#B3E5FC',
                        'description' => 'Packed ice cream.',
                    ],
                ],
            ],
            [
                'code' => 'PKG',
                'name' => 'Packaging',
                'color' => '#78909C',
                'description' => 'Cups and cases used to pack dairy.',
            ],
        ];
    }

    /**
     * @return list<array{code: string, name: string, color: string, description: string, children?: list<array<string, mixed>>}>
     */
    private function beverageCategoryTree(): array
    {
        return [
            [
                'code' => 'BEV',
                'name' => 'Beverages',
                'color' => '#1E88E5',
                'description' => 'Drinks bottled by Sabil.',
                'children' => [
                    [
                        'code' => 'BEV-COLA',
                        'name' => 'Soft drinks',
                        'color' => '#5D4037',
                        'description' => 'Cola, lemon-lime, and flavored soda.',
                    ],
                    [
                        'code' => 'BEV-WATER',
                        'name' => 'Water',
                        'color' => '#039BE5',
                        'description' => 'Still and sparkling water.',
                    ],
                    [
                        'code' => 'BEV-JUICE',
                        'name' => 'Juice',
                        'color' => '#FB8C00',
                        'description' => 'Juices and nectars.',
                    ],
                    [
                        'code' => 'BEV-ENERGY',
                        'name' => 'Energy drinks',
                        'color' => '#E53935',
                        'description' => 'Energy drinks.',
                    ],
                    [
                        'code' => 'BEV-TEA',
                        'name' => 'Iced tea',
                        'color' => '#8D6E63',
                        'description' => 'Iced tea and malt drinks.',
                    ],
                ],
            ],
            [
                'code' => 'PKG',
                'name' => 'Packaging',
                'color' => '#78909C',
                'description' => 'Empty bottles used to pack drinks.',
            ],
        ];
    }

    /**
     * @param  array{code: string, name: string, color: string, description: string, children?: list<array<string, mixed>>}  $node
     */
    private function seedCategoryNode(array $node, ?int $parentId): void
    {
        $category = Category::query()->updateOrCreate(
            ['code' => $node['code']],
            [
                'parent_id' => $parentId,
                'name' => $node['name'],
                'color' => $node['color'],
                'description' => $node['description'],
                'is_active' => true,
            ]
        );

        foreach ($node['children'] ?? [] as $child) {
            $this->seedCategoryNode($child, $category->id);
        }
    }

    private function seedBrands(): void
    {
        $brandSets = [
            'dairy' => [
                'SAHEL' => 'Sahel Mashghara',
                'DAIRYPACK' => 'Bekaa Cup Works',
            ],
            'beverage' => [
                'SABIL' => 'Sabil',
                'BOTTLEPACK' => 'Jbeil Bottle Plant',
            ],
        ];
        $trade = DemoTradeCatalogs::find($this->catalog);
        if ($trade !== null) {
            $brandSets[$this->catalog] = $trade['brands'];
        }

        if (isset($brandSets[$this->catalog])) {
            foreach ($brandSets[$this->catalog] as $code => $name) {
                Brand::query()->updateOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'is_active' => true]
                );
            }

            return;
        }

        $brands = [
            'PEPSI' => 'Pepsi',
            'LAYS' => "Lay's",
            'NESCAFE' => 'Nescafé',
            'KITKAT' => 'KitKat',
            'SPINNEYS' => 'Spinneys',
            'NIDO' => 'Nido',
            'INDEVCO' => 'Indevco',
            '7UP' => '7UP',
            'MIRINDA' => 'Mirinda',
            'CHEETOS' => 'Cheetos',
            'OREO' => 'Oreo',
            'TWIX' => 'Twix',
            'GALAXY' => 'Galaxy',
            'LIBANLAIT' => 'Liban Lait',
            'KASSATLY' => 'Kassatly',
            'HELLMANNS' => "Hellmann's",
            'LIPTON' => 'Lipton',
            'TIDE' => 'Tide',
            'FAIRY' => 'Fairy',
            'PANTENE' => 'Pantene',
            'SAFEGUARD' => 'Safeguard',
            'BARILLA' => 'Barilla',
            'CALVE' => 'Calvé',
        ];

        foreach ($brands as $code => $name) {
            Brand::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true]
            );
        }
    }

    /**
     * @return array<string, Item>
     */
    private function seedItems(int $vatId): array
    {
        $items = [];
        foreach ($this->itemCatalog() as $row) {
            $type = ItemType::query()->where('code', $row['type'])->firstOrFail();
            $baseUom = $this->uom($row['base']);
            $categoryId = isset($row['category'])
                ? Category::query()->where('code', $row['category'])->value('id')
                : null;
            $brandId = isset($row['brand'])
                ? Brand::query()->where('code', $row['brand'])->value('id')
                : null;

            $item = Item::query()->updateOrCreate(
                ['item_code' => $row['item_code']],
                [
                    'name' => $row['name'],
                    'sku' => $row['sku'],
                    'plu_code' => $row['plu'],
                    'item_type_id' => $type->id,
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'unit_group_id' => $baseUom->unit_group_id,
                    'base_uom_id' => $baseUom->id,
                    'vat_group_id' => $row['vat'] ? $vatId : null,
                    'description' => $row['description'],
                    'track_inventory' => $row['track'],
                    'allow_sale' => $row['sale_ok'],
                    'allow_purchase' => $row['purchase_ok'],
                    'is_active' => true,
                ]
            );

            $base = ItemUom::query()->updateOrCreate(
                ['item_id' => $item->id, 'uom_id' => $baseUom->id],
                [
                    'conversion_factor' => '1',
                    'selling_price' => $row['sale'],
                    'cost_price' => $row['cost'],
                    'is_base' => true,
                    'is_default_sale' => true,
                    'is_default_purchase' => $row['alt'] === null,
                ]
            );
            $this->seedBarcode($item, $base, $row['barcode']);

            if ($row['alt'] !== null) {
                $altUom = $this->uom($row['alt']);
                $alt = ItemUom::query()->updateOrCreate(
                    ['item_id' => $item->id, 'uom_id' => $altUom->id],
                    [
                        'conversion_factor' => $row['alt_factor'],
                        'selling_price' => $row['alt_sale'],
                        'cost_price' => $row['alt_cost'],
                        'is_base' => false,
                        'is_default_sale' => false,
                        'is_default_purchase' => true,
                    ]
                );
                $this->seedBarcode($item, $alt, $row['alt_barcode']);
            }

            $items[$row['item_code']] = $item;
        }

        return $items;
    }

    /**
     * Shared catalog. Codes, prices, and UOMs must stay the same in every company.
     *
     * @return list<array<string, mixed>>
     */
    private function itemCatalog(): array
    {
        if ($this->catalog === 'dairy') {
            return array_map(
                fn (array $row): array => $this->withDemoStock($row),
                $this->dairyItemRows()
            );
        }

        if ($this->catalog === 'beverage') {
            return array_map(
                fn (array $row): array => $this->withDemoStock($row),
                $this->beverageItemRows()
            );
        }

        if (DemoTradeCatalogs::find($this->catalog) !== null) {
            return array_map(
                fn (array $row): array => $this->withDemoStock($row),
                $this->tradeItemRows()
            );
        }

        return array_map(
            fn (array $row): array => $this->withDemoStock($row),
            [
            [
                'item_code' => 'PEPSI-330',
                'sku' => 'PEPSI-330',
                'plu' => '1001',
                'name' => 'Pepsi Cola 330ml',
                'brand' => 'PEPSI',
                'category' => 'BEV-COLA',
                'type' => 'INVENTORY',
                'owner' => 'kassatly',
                'base' => 'EA',
                'alt' => 'CS',
                'alt_factor' => '24',
                'sale' => '0.7500',
                'cost' => '0.4200',
                'alt_sale' => '16.8000',
                'alt_cost' => '9.6000',
                'barcode' => '012000013119',
                'alt_barcode' => '012000013126',
                'description' => 'Pepsi cola, 330ml can. Purchase unit is a case of 24.',
                'track' => true,
                'sale_ok' => true,
                'purchase_ok' => true,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => true,
            ],
            [
                'item_code' => 'LAYS-40',
                'sku' => 'LAYS-40',
                'plu' => '1002',
                'name' => "Lay's Classic Potato Chips 40g",
                'brand' => 'LAYS',
                'category' => 'SNA-CHI',
                'type' => 'INVENTORY',
                'owner' => 'kassatly',
                'base' => 'EA',
                'alt' => 'CS',
                'alt_factor' => '24',
                'sale' => '1.2500',
                'cost' => '0.6800',
                'alt_sale' => '28.0000',
                'alt_cost' => '15.5000',
                'barcode' => '002840004021',
                'alt_barcode' => '002840004038',
                'description' => "Lay's classic salted chips, 40g bag. Case of 24.",
                'track' => true,
                'sale_ok' => true,
                'purchase_ok' => true,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => true,
            ],
            [
                'item_code' => 'NESCAFE-200',
                'sku' => 'NESCAFE-200',
                'plu' => '1003',
                'name' => 'Nescafé Classic 200g',
                'brand' => 'NESCAFE',
                'category' => 'BEV-COFFEE',
                'type' => 'INVENTORY',
                'owner' => 'libanlait',
                'base' => 'EA',
                'alt' => 'CS',
                'alt_factor' => '12',
                'sale' => '6.5000',
                'cost' => '4.1000',
                'alt_sale' => '74.0000',
                'alt_cost' => '47.0000',
                'barcode' => '7613035974685',
                'alt_barcode' => '7613035974692',
                'description' => 'Nescafé Classic instant coffee, 200g jar. Case of 12.',
                'track' => true,
                'sale_ok' => true,
                'purchase_ok' => true,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => true,
            ],
            [
                'item_code' => 'KITKAT-4',
                'sku' => 'KITKAT-4',
                'plu' => '1004',
                'name' => 'KitKat 4 Finger',
                'brand' => 'KITKAT',
                'category' => 'SNA-CHO',
                'type' => 'INVENTORY',
                'owner' => 'libanlait',
                'base' => 'EA',
                'alt' => 'BX',
                'alt_factor' => '24',
                'sale' => '1.1000',
                'cost' => '0.6200',
                'alt_sale' => '24.0000',
                'alt_cost' => '14.2000',
                'barcode' => '5000159459228',
                'alt_barcode' => '5000159459235',
                'description' => 'KitKat four-finger milk chocolate wafer. Box of 24.',
                'track' => true,
                'sale_ok' => true,
                'purchase_ok' => true,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => true,
            ],
            [
                'item_code' => 'WATER-150',
                'sku' => 'WATER-150',
                'plu' => '1005',
                'name' => 'Spinneys Natural Spring Water 1.5L',
                'brand' => 'SPINNEYS',
                'category' => 'BEV-WATER',
                'type' => 'INVENTORY',
                'owner' => 'spinneys',
                'base' => 'EA',
                'alt' => 'CS',
                'alt_factor' => '6',
                'sale' => '0.5500',
                'cost' => '0.2200',
                'alt_sale' => '3.0000',
                'alt_cost' => '1.2000',
                'barcode' => '3560070123456',
                'alt_barcode' => '3560070123463',
                'description' => 'Spinneys still spring water, 1.5 liter bottle. Case of 6.',
                'track' => true,
                'sale_ok' => true,
                'purchase_ok' => true,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => true,
            ],
            [
                'item_code' => 'NIDO-400',
                'sku' => 'NIDO-400',
                'plu' => '1006',
                'name' => 'Nido Fortified Milk Powder 400g',
                'brand' => 'NIDO',
                'category' => 'DAI-PWD',
                'type' => 'INVENTORY',
                'owner' => 'libanlait',
                'base' => 'EA',
                'alt' => 'CS',
                'alt_factor' => '12',
                'sale' => '5.7500',
                'cost' => '3.9000',
                'alt_sale' => '66.0000',
                'alt_cost' => '45.0000',
                'barcode' => '7613035381142',
                'alt_barcode' => '7613035381159',
                'description' => 'Nido fortified milk powder, 400g tin. Case of 12.',
                'track' => true,
                'sale_ok' => true,
                'purchase_ok' => true,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => true,
            ],
            [
                'item_code' => 'PKG-SHIP',
                'sku' => 'PKG-SHIP',
                'plu' => '1007',
                'name' => 'Indevco Corrugated Shipper',
                'brand' => 'INDEVCO',
                'category' => 'PKG',
                'type' => 'INVENTORY',
                'owner' => 'indevco',
                'base' => 'EA',
                'alt' => null,
                'alt_factor' => null,
                'sale' => '0.8500',
                'cost' => '0.4500',
                'alt_sale' => null,
                'alt_cost' => null,
                'barcode' => '9300601234507',
                'alt_barcode' => null,
                'description' => 'Indevco corrugated shipping case for mixed grocery.',
                'track' => true,
                'sale_ok' => false,
                'purchase_ok' => true,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => true,
            ],
            [
                'item_code' => 'PEPSI-6PK',
                'sku' => 'PEPSI-6PK',
                'plu' => '1008',
                'name' => 'Pepsi Cola 6 Pack',
                'brand' => 'PEPSI',
                'category' => 'BEV-COLA',
                'type' => 'PRODUCE',
                'owner' => 'kassatly',
                'base' => 'PK',
                'alt' => null,
                'alt_factor' => null,
                'sale' => '4.2000',
                'cost' => '2.5200',
                'alt_sale' => null,
                'alt_cost' => null,
                'barcode' => '012000013133',
                'alt_barcode' => null,
                'description' => 'Pack of six Pepsi 330ml cans. Built from item PEPSI-330.',
                'track' => true,
                'sale_ok' => true,
                'purchase_ok' => false,
                'vat' => true,
                'replenish' => true,
                'supplier_item' => false,
            ],
            [
                'item_code' => 'PANTRY-06',
                'sku' => 'PANTRY-06',
                'plu' => '1009',
                'name' => 'Office Pantry Pack',
                'brand' => null,
                'category' => 'SNA',
                'type' => 'BUNDLE',
                'owner' => null,
                'base' => 'EA',
                'alt' => null,
                'alt_factor' => null,
                'sale' => '12.5000',
                'cost' => '7.4000',
                'alt_sale' => null,
                'alt_cost' => null,
                'barcode' => '2001234500009',
                'alt_barcode' => null,
                'description' => 'Bundle of Pepsi cans, Lay\'s chips, and KitKat bars.',
                'track' => false,
                'sale_ok' => true,
                'purchase_ok' => false,
                'vat' => true,
                'replenish' => false,
                'supplier_item' => false,
            ],
            [
                'item_code' => 'SVC-FREIGHT',
                'sku' => 'SVC-FREIGHT',
                'plu' => '1010',
                'name' => 'Freight and handling',
                'brand' => null,
                'category' => null,
                'type' => 'SERVICE',
                'owner' => null,
                'base' => 'EA',
                'alt' => null,
                'alt_factor' => null,
                'sale' => '25.0000',
                'cost' => '0.0000',
                'alt_sale' => null,
                'alt_cost' => null,
                'barcode' => null,
                'alt_barcode' => null,
                'description' => 'Outbound freight charged on a sales invoice.',
                'track' => false,
                'sale_ok' => true,
                'purchase_ok' => true,
                'vat' => false,
                'replenish' => false,
                'supplier_item' => false,
            ],
            ...$this->extraGroceryCatalog(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withDemoStock(array $row): array
    {
        return array_merge([
            'hq_qty' => ($row['track'] ?? false) ? '180' : '0',
            'dc_qty' => ($row['track'] ?? false) ? '720' : '0',
        ], $row);
    }

    /**
     * Extra SKUs shared by every tenant. Codes stay identical across companies.
     *
     * @return list<array<string, mixed>>
     */
    private function extraGroceryCatalog(): array
    {
        return [
            $this->grocery('7UP-330', '1011', '7UP 330ml', '7UP', 'BEV-COLA', 'kassatly', '0.7500', '0.4000', '5280000001011', '7UP lemon-lime, 330ml can. Case of 24.', '24', '240', '960'),
            $this->grocery('MIRINDA-330', '1012', 'Mirinda Orange 330ml', 'MIRINDA', 'BEV-COLA', 'kassatly', '0.7500', '0.4000', '5280000001012', 'Mirinda orange, 330ml can. Case of 24.', '24', '240', '960'),
            $this->grocery('WATER-050', '1013', 'Spinneys Natural Spring Water 500ml', 'SPINNEYS', 'BEV-WATER', 'spinneys', '0.3500', '0.1400', '5280000001013', 'Spinneys still water, 500ml bottle. Case of 24.', '24', '360', '1440'),
            $this->grocery('JUICE-ORANGE', '1014', 'Kassatly Orange Nectar 1L', 'KASSATLY', 'BEV-JUICE', 'kassatly', '1.8500', '1.1000', '5280000001014', 'Kassatly orange nectar, 1 liter carton. Case of 12.', '12', '144', '576'),
            $this->grocery('JUICE-APPLE', '1015', 'Kassatly Apple Nectar 1L', 'KASSATLY', 'BEV-JUICE', 'kassatly', '1.8500', '1.1000', '5280000001015', 'Kassatly apple nectar, 1 liter carton. Case of 12.', '12', '144', '576'),
            $this->grocery('ENERGY-250', '1016', 'Kassatly Energy Drink 250ml', 'KASSATLY', 'BEV-ENERGY', 'kassatly', '1.5000', '0.8500', '5280000001016', 'Kassatly energy drink, 250ml can. Case of 24.', '24', '120', '480'),
            $this->grocery('LAYS-SALT-40', '1017', "Lay's Salted Potato Chips 40g", 'LAYS', 'SNA-CHI', 'kassatly', '1.2500', '0.6800', '5280000001017', "Lay's extra salted chips, 40g bag. Case of 24.", '24', '180', '720'),
            $this->grocery('CHEETOS-40', '1018', 'Cheetos Crunchy 40g', 'CHEETOS', 'SNA-CHI', 'kassatly', '1.2000', '0.6500', '5280000001018', 'Cheetos crunchy cheese, 40g bag. Case of 24.', '24', '180', '720'),
            $this->grocery('OREO-137', '1019', 'Oreo Original 137g', 'OREO', 'SNA-BIS', 'libanlait', '1.9500', '1.1500', '5280000001019', 'Oreo original sandwich cookies, 137g pack. Case of 12.', '12', '120', '480'),
            $this->grocery('TWIX-50', '1020', 'Twix 50g', 'TWIX', 'SNA-CHO', 'libanlait', '1.0500', '0.5800', '5280000001020', 'Twix caramel chocolate bar, 50g. Box of 24.', '24', '200', '800'),
            $this->grocery('GALAXY-45', '1021', 'Galaxy Smooth Milk 45g', 'GALAXY', 'SNA-CHO', 'libanlait', '1.1500', '0.6400', '5280000001021', 'Galaxy smooth milk chocolate, 45g. Box of 24.', '24', '200', '800'),
            $this->grocery('MILK-1L', '1022', 'Liban Lait Full Cream Milk 1L', 'LIBANLAIT', 'DAI-MLK', 'libanlait', '1.6500', '1.0500', '5280000001022', 'Liban Lait UHT full cream milk, 1 liter. Case of 12.', '12', '160', '640'),
            $this->grocery('LABAN-500', '1023', 'Liban Lait Laban 500g', 'LIBANLAIT', 'DAI-YOG', 'libanlait', '1.2500', '0.7800', '5280000001023', 'Liban Lait laban, 500g pot. Case of 12.', '12', '96', '384'),
            $this->grocery('LABNEH-400', '1024', 'Liban Lait Labneh 400g', 'LIBANLAIT', 'DAI-YOG', 'libanlait', '2.1500', '1.3500', '5280000001024', 'Liban Lait labneh, 400g tub. Case of 12.', '12', '80', '320'),
            $this->grocery('YOG-150', '1025', 'Liban Lait Yogurt 150g', 'LIBANLAIT', 'DAI-YOG', 'libanlait', '0.6500', '0.3800', '5280000001025', 'Liban Lait plain yogurt, 150g cup. Case of 24.', '24', '240', '960'),
            $this->grocery('CHEESE-200', '1026', 'Liban Lait White Cheese 200g', 'LIBANLAIT', 'DAI-CHS', 'libanlait', '2.4500', '1.5500', '5280000001026', 'Liban Lait white cheese, 200g pack. Case of 12.', '12', '72', '288'),
            $this->grocery('BUTTER-200', '1027', 'Liban Lait Butter 200g', 'LIBANLAIT', 'DAI-CHS', 'libanlait', '2.8500', '1.8000', '5280000001027', 'Liban Lait salted butter, 200g pack. Case of 12.', '12', '72', '288'),
            $this->grocery('OIL-SUN-1L', '1028', 'Calvé Sunflower Oil 1L', 'CALVE', 'GRO-OIL', 'unilever', '3.2500', '2.1000', '5280000001028', 'Calvé sunflower cooking oil, 1 liter. Case of 12.', '12', '90', '360'),
            $this->grocery('PASTA-500', '1029', 'Barilla Spaghetti 500g', 'BARILLA', 'GRO-DRY', 'unilever', '1.7500', '1.0500', '5280000001029', 'Barilla spaghetti no.5, 500g. Case of 24.', '24', '160', '640'),
            $this->grocery('RICE-1KG', '1030', 'Spinneys Egyptian Rice 1kg', 'SPINNEYS', 'GRO-DRY', 'spinneys', '1.9500', '1.2000', '5280000001030', 'Spinneys Egyptian white rice, 1kg bag. Case of 10.', '10', '200', '800'),
            $this->grocery('SUGAR-1KG', '1031', 'Spinneys White Sugar 1kg', 'SPINNEYS', 'GRO-DRY', 'spinneys', '1.3500', '0.8500', '5280000001031', 'Spinneys granulated white sugar, 1kg. Case of 10.', '10', '200', '800'),
            $this->grocery('FLOUR-1KG', '1032', 'Spinneys All Purpose Flour 1kg', 'SPINNEYS', 'GRO-DRY', 'spinneys', '1.1500', '0.7000', '5280000001032', 'Spinneys all-purpose flour, 1kg. Case of 10.', '10', '160', '640'),
            $this->grocery('TUNA-160', '1033', "Hellmann's Tuna in Oil 160g", 'HELLMANNS', 'GRO-CAN', 'unilever', '1.8500', '1.1500', '5280000001033', 'Canned tuna in sunflower oil, 160g. Case of 24.', '24', '144', '576'),
            $this->grocery('TOMATO-400', '1034', 'Spinneys Tomato Paste 400g', 'SPINNEYS', 'GRO-CAN', 'spinneys', '1.0500', '0.6200', '5280000001034', 'Spinneys tomato paste, 400g tin. Case of 12.', '12', '120', '480'),
            $this->grocery('TEA-100', '1035', 'Lipton Yellow Label 100s', 'LIPTON', 'GRO-TEA', 'unilever', '4.2500', '2.6000', '5280000001035', 'Lipton yellow label tea bags, box of 100. Case of 12.', '12', '80', '320'),
            $this->grocery('TIDE-1KG', '1036', 'Tide Original 1kg', 'TIDE', 'HPC-CLN', 'pg', '6.5000', '4.2000', '5280000001036', 'Tide original laundry powder, 1kg. Case of 8.', '8', '60', '240'),
            $this->grocery('FAIRY-500', '1037', 'Fairy Dishwashing Liquid 500ml', 'FAIRY', 'HPC-CLN', 'pg', '2.7500', '1.7000', '5280000001037', 'Fairy lemon dishwashing liquid, 500ml. Case of 12.', '12', '90', '360'),
            $this->grocery('SHAMPOO-400', '1038', 'Pantene Classic Clean 400ml', 'PANTENE', 'HPC-PC', 'pg', '5.2500', '3.3000', '5280000001038', 'Pantene classic clean shampoo, 400ml. Case of 12.', '12', '48', '192'),
            $this->grocery('SOAP-125', '1039', 'Safeguard Classic 125g', 'SAFEGUARD', 'HPC-PC', 'pg', '0.8500', '0.4800', '5280000001039', 'Safeguard classic soap bar, 125g. Case of 48.', '48', '240', '960'),
            $this->grocery('TISSUE-200', '1040', 'Spinneys Facial Tissues 200s', 'SPINNEYS', 'HPC-PC', 'spinneys', '1.4500', '0.7800', '5280000001040', 'Spinneys facial tissues, box of 200. Case of 24.', '24', '160', '640'),
            $this->grocery('BREAD-WHT', '1041', 'Spinneys White Arabic Bread', 'SPINNEYS', 'BKY', 'spinneys', '0.7500', '0.3500', '5280000001041', 'Spinneys white Arabic bread pack. Case of 20.', '20', '80', '320'),
            $this->grocery('TOAST-500', '1042', 'Spinneys Toast Bread 500g', 'SPINNEYS', 'BKY', 'spinneys', '1.3500', '0.7200', '5280000001042', 'Spinneys sliced toast bread, 500g. Case of 12.', '12', '72', '288'),
        ];
    }

    /**
     * Identical dairy catalog for Sahel Mashghara and Amleya Hypermarket.
     * Finished goods are owned by Sahel Mashghara so the hypermarket can buy them.
     *
     * @return list<array<string, mixed>>
     */
    private function dairyItemRows(): array
    {
        $lines = [
            ['SM-MILK-FC-1L', '2001', 'Sahel Mashghara Full Cream Milk 1L', 'DAI-MLK', '1.6500', '1.0500', '12', '180', '720'],
            ['SM-MILK-FC-500', '2002', 'Sahel Mashghara Full Cream Milk 500ml', 'DAI-MLK', '0.9500', '0.5800', '12', '160', '640'],
            ['SM-MILK-LF-1L', '2003', 'Sahel Mashghara Low Fat Milk 1L', 'DAI-MLK', '1.6500', '1.0200', '12', '140', '560'],
            ['SM-MILK-SK-1L', '2004', 'Sahel Mashghara Skimmed Milk 1L', 'DAI-MLK', '1.6000', '0.9800', '12', '100', '400'],
            ['SM-MILK-FR-1L', '2005', 'Sahel Mashghara Fresh Milk 1L', 'DAI-MLK', '1.8500', '1.1500', '12', '120', '480'],
            ['SM-MILK-FR-500', '2006', 'Sahel Mashghara Fresh Milk 500ml', 'DAI-MLK', '1.0500', '0.6500', '12', '120', '480'],
            ['SM-MILK-CH-250', '2007', 'Sahel Mashghara Chocolate Milk 250ml', 'DAI-MLK', '0.7500', '0.4200', '24', '200', '800'],
            ['SM-MILK-ST-250', '2008', 'Sahel Mashghara Strawberry Milk 250ml', 'DAI-MLK', '0.7500', '0.4200', '24', '180', '720'],
            ['SM-MILK-BN-250', '2009', 'Sahel Mashghara Banana Milk 250ml', 'DAI-MLK', '0.7500', '0.4200', '24', '160', '640'],
            ['SM-MILK-LFREE-1L', '2010', 'Sahel Mashghara Lactose Free Milk 1L', 'DAI-MLK', '2.1500', '1.4000', '12', '80', '320'],
            ['SM-MILK-KIDS-200', '2011', 'Sahel Mashghara Kids Milk 200ml', 'DAI-MLK', '0.5500', '0.3000', '24', '240', '960'],
            ['SM-LABAN-200', '2012', 'Sahel Mashghara Laban 200g', 'DAI-YOG', '0.6500', '0.3800', '24', '200', '800'],
            ['SM-LABAN-500', '2013', 'Sahel Mashghara Laban 500g', 'DAI-YOG', '1.2500', '0.7800', '12', '160', '640'],
            ['SM-LABAN-1KG', '2014', 'Sahel Mashghara Laban 1kg', 'DAI-YOG', '2.1500', '1.3500', '6', '80', '320'],
            ['SM-AYRAN-180', '2015', 'Sahel Mashghara Ayran 180ml', 'DAI-YOG', '0.5500', '0.3000', '24', '240', '960'],
            ['SM-AYRAN-1L', '2016', 'Sahel Mashghara Ayran 1L', 'DAI-YOG', '1.3500', '0.8200', '12', '120', '480'],
            ['SM-LABNEH-200', '2017', 'Sahel Mashghara Labneh 200g', 'DAI-YOG', '1.4500', '0.9000', '12', '100', '400'],
            ['SM-LABNEH-400', '2018', 'Sahel Mashghara Labneh 400g', 'DAI-YOG', '2.1500', '1.3500', '12', '120', '480'],
            ['SM-LABNEH-1KG', '2019', 'Sahel Mashghara Labneh 1kg', 'DAI-YOG', '4.2500', '2.7000', '6', '60', '240'],
            ['SM-LABNEH-LT-400', '2020', 'Sahel Mashghara Light Labneh 400g', 'DAI-YOG', '2.2500', '1.4000', '12', '80', '320'],
            ['SM-LABNEH-OIL', '2021', 'Sahel Mashghara Labneh Balls in Oil 350g', 'DAI-YOG', '3.4500', '2.1500', '12', '70', '280'],
            ['SM-LABNEH-MINT', '2022', 'Sahel Mashghara Mint Labneh 400g', 'DAI-YOG', '2.3500', '1.4500', '12', '70', '280'],
            ['SM-YOG-150', '2023', 'Sahel Mashghara Plain Yogurt 150g', 'DAI-YOG', '0.6500', '0.3800', '24', '240', '960'],
            ['SM-YOG-500', '2024', 'Sahel Mashghara Plain Yogurt 500g', 'DAI-YOG', '1.4500', '0.9000', '12', '120', '480'],
            ['SM-YOG-1KG', '2025', 'Sahel Mashghara Plain Yogurt 1kg', 'DAI-YOG', '2.4500', '1.5500', '6', '80', '320'],
            ['SM-YOG-STR-150', '2026', 'Sahel Mashghara Strawberry Yogurt 150g', 'DAI-YOG', '0.7500', '0.4200', '24', '200', '800'],
            ['SM-YOG-APR-150', '2027', 'Sahel Mashghara Apricot Yogurt 150g', 'DAI-YOG', '0.7500', '0.4200', '24', '180', '720'],
            ['SM-YOG-BLU-150', '2028', 'Sahel Mashghara Blueberry Yogurt 150g', 'DAI-YOG', '0.8500', '0.4800', '24', '160', '640'],
            ['SM-YOG-GR-150', '2029', 'Sahel Mashghara Greek Yogurt 150g', 'DAI-YOG', '0.9500', '0.5500', '24', '160', '640'],
            ['SM-YOG-GR-500', '2030', 'Sahel Mashghara Greek Yogurt 500g', 'DAI-YOG', '2.1500', '1.3000', '12', '90', '360'],
            ['SM-CHS-WHT-200', '2031', 'Sahel Mashghara White Cheese 200g', 'DAI-CHS', '2.4500', '1.5500', '12', '90', '360'],
            ['SM-CHS-WHT-400', '2032', 'Sahel Mashghara White Cheese 400g', 'DAI-CHS', '4.2500', '2.7000', '12', '70', '280'],
            ['SM-CHS-WHT-1KG', '2033', 'Sahel Mashghara White Cheese 1kg', 'DAI-CHS', '8.5000', '5.4000', '6', '40', '160'],
            ['SM-CHS-AKK-250', '2034', 'Sahel Mashghara Akkawi 250g', 'DAI-CHS', '3.2500', '2.0500', '12', '80', '320'],
            ['SM-CHS-AKK-500', '2035', 'Sahel Mashghara Akkawi 500g', 'DAI-CHS', '5.7500', '3.6500', '12', '60', '240'],
            ['SM-CHS-HAL-200', '2036', 'Sahel Mashghara Halloumi 200g', 'DAI-CHS', '3.4500', '2.1500', '12', '80', '320'],
            ['SM-CHS-HAL-400', '2037', 'Sahel Mashghara Halloumi 400g', 'DAI-CHS', '6.2500', '3.9500', '12', '50', '200'],
            ['SM-CHS-SHANK', '2038', 'Sahel Mashghara Shanklish 200g', 'DAI-CHS', '2.8500', '1.7500', '12', '60', '240'],
            ['SM-CHS-DBL', '2039', 'Sahel Mashghara Double Cream 200g', 'DAI-CHS', '2.6500', '1.6500', '12', '70', '280'],
            ['SM-CHS-KASH', '2040', 'Sahel Mashghara Kashkaval 200g', 'DAI-CHS', '3.1500', '1.9500', '12', '60', '240'],
            ['SM-CHS-SLICE', '2041', 'Sahel Mashghara Cheese Slices 200g', 'DAI-CHS', '2.9500', '1.8500', '12', '80', '320'],
            ['SM-CHS-LIGHT', '2042', 'Sahel Mashghara Light White Cheese 200g', 'DAI-CHS', '2.5500', '1.6000', '12', '70', '280'],
            ['SM-BTR-100', '2043', 'Sahel Mashghara Salted Butter 100g', 'DAI-CHS', '1.6500', '1.0500', '24', '140', '560'],
            ['SM-BTR-200', '2044', 'Sahel Mashghara Salted Butter 200g', 'DAI-CHS', '2.8500', '1.8000', '12', '100', '400'],
            ['SM-BTR-500', '2045', 'Sahel Mashghara Salted Butter 500g', 'DAI-CHS', '6.2500', '3.9500', '12', '50', '200'],
            ['SM-BTR-UNS', '2046', 'Sahel Mashghara Unsalted Butter 200g', 'DAI-CHS', '2.9500', '1.8500', '12', '80', '320'],
            ['SM-BTR-COOK', '2047', 'Sahel Mashghara Cooking Butter 1kg', 'DAI-CHS', '9.5000', '6.2000', '6', '40', '160'],
            ['SM-CRM-200', '2048', 'Sahel Mashghara Fresh Cream 200ml', 'DAI-CRM', '1.4500', '0.9000', '12', '100', '400'],
            ['SM-CRM-500', '2049', 'Sahel Mashghara Fresh Cream 500ml', 'DAI-CRM', '2.7500', '1.7000', '12', '80', '320'],
            ['SM-CRM-COOK', '2050', 'Sahel Mashghara Cooking Cream 1L', 'DAI-CRM', '3.4500', '2.1500', '12', '70', '280'],
            ['SM-CRM-WHIP', '2051', 'Sahel Mashghara Whipping Cream 500ml', 'DAI-CRM', '3.1500', '1.9500', '12', '60', '240'],
            ['SM-ICE-VAN-500', '2052', 'Sahel Mashghara Vanilla Ice Cream 500ml', 'DAI-ICE', '3.2500', '1.9500', '8', '50', '200'],
            ['SM-ICE-VAN-1L', '2053', 'Sahel Mashghara Vanilla Ice Cream 1L', 'DAI-ICE', '5.5000', '3.4000', '6', '40', '160'],
            ['SM-ICE-CHO-500', '2054', 'Sahel Mashghara Chocolate Ice Cream 500ml', 'DAI-ICE', '3.2500', '1.9500', '8', '50', '200'],
            ['SM-ICE-STR-500', '2055', 'Sahel Mashghara Strawberry Ice Cream 500ml', 'DAI-ICE', '3.2500', '1.9500', '8', '45', '180'],
            ['SM-ICE-ASH-500', '2056', 'Sahel Mashghara Ashta Ice Cream 500ml', 'DAI-ICE', '3.4500', '2.1000', '8', '45', '180'],
        ];

        $rows = [];
        foreach ($lines as [$code, $plu, $name, $category, $sale, $cost, $factor, $hq, $dc]) {
            $rows[] = $this->grocery(
                $code,
                $plu,
                $name,
                'SAHEL',
                $category,
                'sahelmashghara',
                $sale,
                $cost,
                '529'.str_pad($plu, 10, '0', STR_PAD_LEFT),
                $name.'. Case of '.$factor.'.',
                $factor,
                $hq,
                $dc,
            );
        }

        $cup = $this->grocery(
            'SM-CUP',
            '2090',
            'Yogurt Cup 150ml',
            'DAIRYPACK',
            'PKG',
            'dairypack',
            '0.0800',
            '0.0350',
            '5290000002090',
            'Empty 150ml yogurt cup. Purchase unit is a case of 500.',
            '500',
            '2000',
            '8000',
        );
        $cup['sale_ok'] = false;
        $rows[] = $cup;

        $rows[] = [
            'item_code' => 'SM-YOG-4PK',
            'sku' => 'SM-YOG-4PK',
            'plu' => '2080',
            'name' => 'Sahel Mashghara Yogurt 4 Pack',
            'brand' => 'SAHEL',
            'category' => 'DAI-YOG',
            'type' => 'PRODUCE',
            'owner' => 'sahelmashghara',
            'base' => 'PK',
            'alt' => null,
            'alt_factor' => null,
            'sale' => '2.4000',
            'cost' => '1.5200',
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => '5290000002080',
            'alt_barcode' => null,
            'description' => 'Pack of four Sahel Mashghara plain yogurt 150g cups. Built from SM-YOG-150.',
            'track' => true,
            'sale_ok' => true,
            'purchase_ok' => false,
            'vat' => true,
            'replenish' => true,
            'supplier_item' => false,
        ];
        $rows[] = [
            'item_code' => 'SM-BREAKFAST',
            'sku' => 'SM-BREAKFAST',
            'plu' => '2081',
            'name' => 'Sahel Mashghara Breakfast Box',
            'brand' => null,
            'category' => 'DAI',
            'type' => 'BUNDLE',
            'owner' => null,
            'base' => 'EA',
            'alt' => null,
            'alt_factor' => null,
            'sale' => '7.5000',
            'cost' => '4.8000',
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => '5290000002081',
            'alt_barcode' => null,
            'description' => 'Bundle of full cream milk 1L, labneh 400g, two plain yogurts, and salted butter 200g.',
            'track' => false,
            'sale_ok' => true,
            'purchase_ok' => false,
            'vat' => true,
            'replenish' => false,
            'supplier_item' => false,
        ];
        $rows[] = [
            'item_code' => 'SVC-FREIGHT',
            'sku' => 'SVC-FREIGHT',
            'plu' => '2098',
            'name' => 'Freight and handling',
            'brand' => null,
            'category' => null,
            'type' => 'SERVICE',
            'owner' => null,
            'base' => 'EA',
            'alt' => null,
            'alt_factor' => null,
            'sale' => '25.0000',
            'cost' => '0.0000',
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => null,
            'alt_barcode' => null,
            'description' => 'Outbound freight charged on a sales invoice.',
            'track' => false,
            'sale_ok' => true,
            'purchase_ok' => true,
            'vat' => false,
            'replenish' => false,
            'supplier_item' => false,
        ];

        return $rows;
    }

    /**
     * @param  array<string, Item>  $items
     */
    private function seedDairyItemStructures(array $items): void
    {
        $pack = $items['SM-YOG-4PK'];
        $recipe = Recipe::query()->updateOrCreate(
            ['item_id' => $pack->id],
            [
                'yield_quantity' => '1',
                'uom_id' => $this->uom('PK')->id,
            ]
        );
        RecipeItem::query()->updateOrCreate(
            [
                'recipe_id' => $recipe->id,
                'item_id' => $items['SM-YOG-150']->id,
            ],
            [
                'quantity' => '4',
                'uom_id' => $this->uom('EA')->id,
            ]
        );

        $bundleId = $items['SM-BREAKFAST']->id;
        $components = [
            'SM-MILK-FC-1L' => '1',
            'SM-LABNEH-400' => '1',
            'SM-YOG-150' => '2',
            'SM-BTR-200' => '1',
        ];
        foreach ($components as $code => $quantity) {
            BundleItem::query()->updateOrCreate(
                [
                    'bundle_item_id' => $bundleId,
                    'child_item_id' => $items[$code]->id,
                ],
                ['quantity' => $quantity]
            );
        }
    }

    /**
     * Identical beverage catalog for Sabil and Baytouna Market.
     * Finished drinks are owned by Sabil so the market can buy them.
     *
     * @return list<array<string, mixed>>
     */
    private function beverageItemRows(): array
    {
        $lines = [
            ['SB-COLA-330', '3001', 'Sabil Cola 330ml', 'BEV-COLA', '0.7500', '0.4000', '24', '240', '960'],
            ['SB-COLA-250', '3002', 'Sabil Cola 250ml', 'BEV-COLA', '0.5500', '0.2800', '24', '200', '800'],
            ['SB-COLA-150', '3003', 'Sabil Cola 1.5L', 'BEV-COLA', '1.2500', '0.6500', '6', '180', '720'],
            ['SB-COLA-2L', '3004', 'Sabil Cola 2L', 'BEV-COLA', '1.4500', '0.7500', '6', '120', '480'],
            ['SB-COLA-ZERO', '3005', 'Sabil Cola Zero 330ml', 'BEV-COLA', '0.7500', '0.4200', '24', '160', '640'],
            ['SB-COLA-DIET', '3006', 'Sabil Diet Cola 330ml', 'BEV-COLA', '0.7500', '0.4200', '24', '140', '560'],
            ['SB-LEMON-330', '3007', 'Sabil Lemon Lime 330ml', 'BEV-COLA', '0.7500', '0.4000', '24', '220', '880'],
            ['SB-LEMON-150', '3008', 'Sabil Lemon Lime 1.5L', 'BEV-COLA', '1.2500', '0.6500', '6', '160', '640'],
            ['SB-LEMON-2L', '3009', 'Sabil Lemon Lime 2L', 'BEV-COLA', '1.4500', '0.7500', '6', '100', '400'],
            ['SB-LEMON-ZERO', '3010', 'Sabil Lemon Lime Zero 330ml', 'BEV-COLA', '0.7500', '0.4200', '24', '140', '560'],
            ['SB-ORANGE-330', '3011', 'Sabil Orange Soda 330ml', 'BEV-COLA', '0.7500', '0.4000', '24', '200', '800'],
            ['SB-ORANGE-150', '3012', 'Sabil Orange Soda 1.5L', 'BEV-COLA', '1.2500', '0.6500', '6', '140', '560'],
            ['SB-ORANGE-2L', '3013', 'Sabil Orange Soda 2L', 'BEV-COLA', '1.4500', '0.7500', '6', '90', '360'],
            ['SB-GINGER-330', '3014', 'Sabil Ginger Ale 330ml', 'BEV-COLA', '0.8500', '0.4500', '24', '120', '480'],
            ['SB-TONIC-330', '3015', 'Sabil Tonic Water 330ml', 'BEV-COLA', '0.8500', '0.4500', '24', '100', '400'],
            ['SB-SODA-330', '3016', 'Sabil Soda Water 330ml', 'BEV-COLA', '0.6500', '0.3200', '24', '140', '560'],
            ['SB-WATER-330', '3017', 'Sabil Still Water 330ml', 'BEV-WATER', '0.3500', '0.1200', '24', '360', '1440'],
            ['SB-WATER-500', '3018', 'Sabil Still Water 500ml', 'BEV-WATER', '0.4500', '0.1600', '24', '320', '1280'],
            ['SB-WATER-750', '3019', 'Sabil Still Water 750ml', 'BEV-WATER', '0.7500', '0.3000', '12', '160', '640'],
            ['SB-WATER-150', '3020', 'Sabil Still Water 1.5L', 'BEV-WATER', '0.6500', '0.2400', '6', '240', '960'],
            ['SB-WATER-5L', '3021', 'Sabil Still Water 5L', 'BEV-WATER', '1.1500', '0.4800', '4', '80', '320'],
            ['SB-SPARK-330', '3022', 'Sabil Sparkling Water 330ml', 'BEV-WATER', '0.5500', '0.2400', '24', '160', '640'],
            ['SB-SPARK-750', '3023', 'Sabil Sparkling Water 750ml', 'BEV-WATER', '1.1500', '0.5500', '12', '90', '360'],
            ['SB-JUICE-OR-1L', '3024', 'Sabil Orange Juice 1L', 'BEV-JUICE', '1.8500', '1.1000', '12', '140', '560'],
            ['SB-JUICE-OR-250', '3025', 'Sabil Orange Juice 250ml', 'BEV-JUICE', '0.7500', '0.4000', '24', '180', '720'],
            ['SB-JUICE-OR-2L', '3026', 'Sabil Orange Juice 2L', 'BEV-JUICE', '2.8500', '1.7500', '6', '80', '320'],
            ['SB-JUICE-AP-1L', '3027', 'Sabil Apple Juice 1L', 'BEV-JUICE', '1.8500', '1.1000', '12', '140', '560'],
            ['SB-JUICE-AP-250', '3028', 'Sabil Apple Juice 250ml', 'BEV-JUICE', '0.7500', '0.4000', '24', '160', '640'],
            ['SB-JUICE-MG-1L', '3029', 'Sabil Mango Juice 1L', 'BEV-JUICE', '1.9500', '1.1500', '12', '120', '480'],
            ['SB-JUICE-PN-1L', '3030', 'Sabil Pineapple Juice 1L', 'BEV-JUICE', '1.9500', '1.1500', '12', '100', '400'],
            ['SB-JUICE-MX-1L', '3031', 'Sabil Cocktail Juice 1L', 'BEV-JUICE', '1.8500', '1.1000', '12', '120', '480'],
            ['SB-JUICE-GR-1L', '3032', 'Sabil Grapefruit Juice 1L', 'BEV-JUICE', '1.9500', '1.2000', '12', '80', '320'],
            ['SB-JUICE-PO-1L', '3033', 'Sabil Pomegranate Juice 1L', 'BEV-JUICE', '2.2500', '1.4000', '12', '80', '320'],
            ['SB-JUICE-STR-1L', '3034', 'Sabil Strawberry Juice 1L', 'BEV-JUICE', '1.9500', '1.1500', '12', '100', '400'],
            ['SB-JUICE-GU-1L', '3035', 'Sabil Guava Juice 1L', 'BEV-JUICE', '1.9500', '1.1500', '12', '90', '360'],
            ['SB-JUICE-LM-250', '3036', 'Sabil Lemon Juice 250ml', 'BEV-JUICE', '0.8500', '0.4800', '24', '140', '560'],
            ['SB-NECT-OR-200', '3037', 'Sabil Orange Nectar 200ml', 'BEV-JUICE', '0.5500', '0.2800', '24', '200', '800'],
            ['SB-NECT-AP-200', '3038', 'Sabil Apple Nectar 200ml', 'BEV-JUICE', '0.5500', '0.2800', '24', '180', '720'],
            ['SB-EN-250', '3039', 'Sabil Energy Drink 250ml', 'BEV-ENERGY', '1.5000', '0.8500', '24', '160', '640'],
            ['SB-EN-SF-250', '3040', 'Sabil Sugar Free Energy 250ml', 'BEV-ENERGY', '1.5000', '0.8800', '24', '120', '480'],
            ['SB-EN-500', '3041', 'Sabil Energy Drink 500ml', 'BEV-ENERGY', '2.2500', '1.2500', '12', '100', '400'],
            ['SB-EN-TROP', '3042', 'Sabil Tropical Energy 250ml', 'BEV-ENERGY', '1.5500', '0.9000', '24', '100', '400'],
            ['SB-EN-BERRY', '3043', 'Sabil Berry Energy 250ml', 'BEV-ENERGY', '1.5500', '0.9000', '24', '90', '360'],
            ['SB-TEA-LM-330', '3044', 'Sabil Lemon Iced Tea 330ml', 'BEV-TEA', '0.8500', '0.4500', '24', '180', '720'],
            ['SB-TEA-PCH-330', '3045', 'Sabil Peach Iced Tea 330ml', 'BEV-TEA', '0.8500', '0.4500', '24', '160', '640'],
            ['SB-TEA-MNG-330', '3046', 'Sabil Mango Iced Tea 330ml', 'BEV-TEA', '0.8500', '0.4500', '24', '140', '560'],
            ['SB-TEA-LM-500', '3047', 'Sabil Lemon Iced Tea 500ml', 'BEV-TEA', '1.1500', '0.6200', '12', '140', '560'],
            ['SB-MALT-330', '3048', 'Sabil Malt Drink 330ml', 'BEV-TEA', '0.9500', '0.5200', '24', '120', '480'],
        ];

        $rows = [];
        foreach ($lines as [$code, $plu, $name, $category, $sale, $cost, $factor, $hq, $dc]) {
            $rows[] = $this->grocery(
                $code,
                $plu,
                $name,
                'SABIL',
                $category,
                'sabil',
                $sale,
                $cost,
                '530'.str_pad($plu, 10, '0', STR_PAD_LEFT),
                $name.'. Case of '.$factor.'.',
                $factor,
                $hq,
                $dc,
            );
        }

        $bottle = $this->grocery(
            'SB-PET-330',
            '3090',
            'Empty PET Bottle 330ml',
            'BOTTLEPACK',
            'PKG',
            'bottlepack',
            '0.0600',
            '0.0250',
            '5300000003090',
            'Empty 330ml PET bottle. Purchase unit is a case of 200.',
            '200',
            '4000',
            '16000',
        );
        $bottle['sale_ok'] = false;
        $rows[] = $bottle;

        $rows[] = [
            'item_code' => 'SB-COLA-6PK',
            'sku' => 'SB-COLA-6PK',
            'plu' => '3080',
            'name' => 'Sabil Cola 6 Pack',
            'brand' => 'SABIL',
            'category' => 'BEV-COLA',
            'type' => 'PRODUCE',
            'owner' => 'sabil',
            'base' => 'PK',
            'alt' => null,
            'alt_factor' => null,
            'sale' => '4.2000',
            'cost' => '2.4000',
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => '5300000003080',
            'alt_barcode' => null,
            'description' => 'Pack of six Sabil Cola 330ml cans. Built from SB-COLA-330.',
            'track' => true,
            'sale_ok' => true,
            'purchase_ok' => false,
            'vat' => true,
            'replenish' => true,
            'supplier_item' => false,
        ];
        $rows[] = [
            'item_code' => 'SB-PARTY',
            'sku' => 'SB-PARTY',
            'plu' => '3081',
            'name' => 'Sabil Party Case',
            'brand' => null,
            'category' => 'BEV',
            'type' => 'BUNDLE',
            'owner' => null,
            'base' => 'EA',
            'alt' => null,
            'alt_factor' => null,
            'sale' => '18.5000',
            'cost' => '10.2000',
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => '5300000003081',
            'alt_barcode' => null,
            'description' => 'Bundle of cola, lemon-lime, orange soda, and still water.',
            'track' => false,
            'sale_ok' => true,
            'purchase_ok' => false,
            'vat' => true,
            'replenish' => false,
            'supplier_item' => false,
        ];
        $rows[] = [
            'item_code' => 'SVC-FREIGHT',
            'sku' => 'SVC-FREIGHT',
            'plu' => '3098',
            'name' => 'Freight and handling',
            'brand' => null,
            'category' => null,
            'type' => 'SERVICE',
            'owner' => null,
            'base' => 'EA',
            'alt' => null,
            'alt_factor' => null,
            'sale' => '25.0000',
            'cost' => '0.0000',
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => null,
            'alt_barcode' => null,
            'description' => 'Outbound freight charged on a sales invoice.',
            'track' => false,
            'sale_ok' => true,
            'purchase_ok' => true,
            'vat' => false,
            'replenish' => false,
            'supplier_item' => false,
        ];

        return $rows;
    }

    /**
     * @param  array<string, Item>  $items
     */
    private function seedBeverageItemStructures(array $items): void
    {
        $pack = $items['SB-COLA-6PK'];
        $recipe = Recipe::query()->updateOrCreate(
            ['item_id' => $pack->id],
            [
                'yield_quantity' => '1',
                'uom_id' => $this->uom('PK')->id,
            ]
        );
        RecipeItem::query()->updateOrCreate(
            [
                'recipe_id' => $recipe->id,
                'item_id' => $items['SB-COLA-330']->id,
            ],
            [
                'quantity' => '6',
                'uom_id' => $this->uom('EA')->id,
            ]
        );

        $bundleId = $items['SB-PARTY']->id;
        $components = [
            'SB-COLA-330' => '6',
            'SB-LEMON-330' => '6',
            'SB-ORANGE-330' => '6',
            'SB-WATER-500' => '6',
        ];
        foreach ($components as $code => $quantity) {
            BundleItem::query()->updateOrCreate(
                [
                    'bundle_item_id' => $bundleId,
                    'child_item_id' => $items[$code]->id,
                ],
                ['quantity' => $quantity]
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tradeItemRows(): array
    {
        $trade = DemoTradeCatalogs::find($this->catalog);
        if ($trade === null) {
            return [];
        }

        $rows = [];
        foreach ($trade['lines'] as [$code, $plu, $name, $category, $sale, $cost, $factor, $hq, $dc]) {
            $rows[] = $this->grocery(
                $code,
                $plu,
                $name,
                $trade['brand'],
                $category,
                $trade['owner'],
                $sale,
                $cost,
                $trade['barcode_prefix'].str_pad($plu, 10, '0', STR_PAD_LEFT),
                $name.'. Case of '.$factor.'.',
                $factor,
                $hq,
                $dc,
            );
        }

        [$code, $plu, $name, $brand, $category, $owner, $sale, $cost, $factor, $hq, $dc, $description] = $trade['packaging'];
        $packaging = $this->grocery(
            $code,
            $plu,
            $name,
            $brand,
            $category,
            $owner,
            $sale,
            $cost,
            $trade['barcode_prefix'].str_pad($plu, 10, '0', STR_PAD_LEFT),
            $description,
            $factor,
            $hq,
            $dc,
        );
        $packaging['sale_ok'] = false;
        $rows[] = $packaging;

        [$code, $plu, $name, $category, $sale, $cost, $description] = $trade['produce'];
        $rows[] = [
            'item_code' => $code,
            'sku' => $code,
            'plu' => $plu,
            'name' => $name,
            'brand' => $trade['brand'],
            'category' => $category,
            'type' => 'PRODUCE',
            'owner' => $trade['owner'],
            'base' => 'PK',
            'alt' => null,
            'alt_factor' => null,
            'sale' => $sale,
            'cost' => $cost,
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => $trade['barcode_prefix'].str_pad($plu, 10, '0', STR_PAD_LEFT),
            'alt_barcode' => null,
            'description' => $description,
            'track' => true,
            'sale_ok' => true,
            'purchase_ok' => false,
            'vat' => true,
            'replenish' => true,
            'supplier_item' => false,
        ];

        [$code, $plu, $name, $category, $sale, $cost, $description] = $trade['bundle'];
        $rows[] = [
            'item_code' => $code,
            'sku' => $code,
            'plu' => $plu,
            'name' => $name,
            'brand' => null,
            'category' => $category,
            'type' => 'BUNDLE',
            'owner' => null,
            'base' => 'EA',
            'alt' => null,
            'alt_factor' => null,
            'sale' => $sale,
            'cost' => $cost,
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => $trade['barcode_prefix'].str_pad($plu, 10, '0', STR_PAD_LEFT),
            'alt_barcode' => null,
            'description' => $description,
            'track' => false,
            'sale_ok' => true,
            'purchase_ok' => false,
            'vat' => true,
            'replenish' => false,
            'supplier_item' => false,
        ];

        $freightPlu = $trade['freight_plu'];
        $rows[] = [
            'item_code' => 'SVC-FREIGHT',
            'sku' => 'SVC-FREIGHT',
            'plu' => $freightPlu,
            'name' => 'Freight and handling',
            'brand' => null,
            'category' => null,
            'type' => 'SERVICE',
            'owner' => null,
            'base' => 'EA',
            'alt' => null,
            'alt_factor' => null,
            'sale' => '25.0000',
            'cost' => '0.0000',
            'alt_sale' => null,
            'alt_cost' => null,
            'barcode' => null,
            'alt_barcode' => null,
            'description' => 'Outbound freight charged on a sales invoice.',
            'track' => false,
            'sale_ok' => true,
            'purchase_ok' => true,
            'vat' => false,
            'replenish' => false,
            'supplier_item' => false,
        ];

        return $rows;
    }

    /**
     * @param  array<string, Item>  $items
     */
    private function seedTradeItemStructures(array $items): void
    {
        $trade = DemoTradeCatalogs::find($this->catalog);
        if ($trade === null) {
            return;
        }

        $produce = $trade['produce'];
        $pack = $items[$produce[0]];
        $recipe = Recipe::query()->updateOrCreate(
            ['item_id' => $pack->id],
            [
                'yield_quantity' => '1',
                'uom_id' => $this->uom('PK')->id,
            ]
        );
        RecipeItem::query()->updateOrCreate(
            [
                'recipe_id' => $recipe->id,
                'item_id' => $items[$produce[7]]->id,
            ],
            [
                'quantity' => $produce[8],
                'uom_id' => $this->uom('EA')->id,
            ]
        );

        $bundle = $trade['bundle'];
        $bundleId = $items[$bundle[0]]->id;
        foreach ($bundle[7] as $code => $quantity) {
            BundleItem::query()->updateOrCreate(
                [
                    'bundle_item_id' => $bundleId,
                    'child_item_id' => $items[$code]->id,
                ],
                ['quantity' => $quantity]
            );
        }
    }

    /**
     * @param  array{key: int, retail: int, wholesale: int, horeca: int, mfg: int, pkg: int, dist: int}  $groups
     * @param  array{cash: int, card: int, bank: int, due: int, net30: int}  $payment
     * @param  array<string, Item>  $items
     */
    private function seedTradeParties(array $groups, array $payment, array $items): void
    {
        $trade = DemoTradeCatalogs::find($this->catalog);
        if ($trade === null) {
            return;
        }

        foreach ($trade['customers'] as [$code, $name, $email, $phone, $group, $pay, $terms, $vat, $notes, $address, $city, $state, $contact]) {
            $this->seedCustomer([
                'code' => $code,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'group_id' => $groups[$group],
                'payment_method_id' => $payment[$pay],
                'payment_terms_id' => $payment[$terms],
                'wallet' => null,
                'vat_number' => $vat,
                'notes' => $notes,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'country' => 'Lebanon',
                'contact' => $contact,
                'contact_email' => $email,
            ]);
        }

        [$code, $name, $company, $email, $phone, $group, $vat, $notes, $address, $city, $state, $contact, $owner] = $trade['supplier'];
        $supplier = $this->seedSupplier([
            'code' => $code,
            'name' => $name,
            'company_name' => $company,
            'email' => $email,
            'phone' => $phone,
            'group_id' => $groups[$group],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => $vat,
            'notes' => $notes,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'country' => 'Lebanon',
            'contact' => $contact,
            'contact_email' => $email,
        ]);
        $this->linkSupplierItems($supplier, $items, $owner);
    }

    /**
     * @return array<string, mixed>
     */
    private function grocery(
        string $code,
        string $plu,
        string $name,
        string $brand,
        string $category,
        string $owner,
        string $sale,
        string $cost,
        string $barcode,
        string $description,
        string $altFactor,
        string $hqQty,
        string $dcQty,
    ): array {
        $factor = (float) $altFactor;

        return [
            'item_code' => $code,
            'sku' => $code,
            'plu' => $plu,
            'name' => $name,
            'brand' => $brand,
            'category' => $category,
            'type' => 'INVENTORY',
            'owner' => $owner,
            'base' => 'EA',
            'alt' => 'CS',
            'alt_factor' => $altFactor,
            'sale' => $sale,
            'cost' => $cost,
            'alt_sale' => number_format((float) $sale * $factor, 4, '.', ''),
            'alt_cost' => number_format((float) $cost * $factor, 4, '.', ''),
            'barcode' => $barcode,
            'alt_barcode' => $barcode.'C',
            'description' => $description,
            'track' => true,
            'sale_ok' => true,
            'purchase_ok' => true,
            'vat' => true,
            'replenish' => true,
            'supplier_item' => true,
            'hq_qty' => $hqQty,
            'dc_qty' => $dcQty,
        ];
    }

    private function uom(string $code): UnitOfMeasurement
    {
        return UnitOfMeasurement::query()->where('code', $code)->firstOrFail();
    }

    private function seedBarcode(Item $item, ItemUom $itemUom, ?string $barcode): void
    {
        if ($barcode === null) {
            return;
        }

        ItemBarcode::query()->updateOrCreate(
            ['barcode' => $barcode],
            [
                'item_id' => $item->id,
                'item_uom_id' => $itemUom->id,
                'is_primary' => (bool) $itemUom->is_base,
            ]
        );
    }

    /**
     * @param  array<string, Item>  $items
     */
    private function seedItemStructures(array $items): void
    {
        if ($this->catalog === 'dairy') {
            $this->seedDairyItemStructures($items);

            return;
        }

        if ($this->catalog === 'beverage') {
            $this->seedBeverageItemStructures($items);

            return;
        }

        if (DemoTradeCatalogs::find($this->catalog) !== null) {
            $this->seedTradeItemStructures($items);

            return;
        }

        $pack = $items['PEPSI-6PK'];
        $recipe = Recipe::query()->updateOrCreate(
            ['item_id' => $pack->id],
            [
                'yield_quantity' => '1',
                'uom_id' => $this->uom('PK')->id,
            ]
        );
        RecipeItem::query()->updateOrCreate(
            [
                'recipe_id' => $recipe->id,
                'item_id' => $items['PEPSI-330']->id,
            ],
            [
                'quantity' => '6',
                'uom_id' => $this->uom('EA')->id,
            ]
        );

        $bundleId = $items['PANTRY-06']->id;
        $components = [
            'PEPSI-330' => '6',
            'LAYS-40' => '4',
            'KITKAT-4' => '2',
        ];
        foreach ($components as $code => $quantity) {
            BundleItem::query()->updateOrCreate(
                [
                    'bundle_item_id' => $bundleId,
                    'child_item_id' => $items[$code]->id,
                ],
                ['quantity' => $quantity]
            );
        }
    }

    /**
     * @param  array<string, Item>  $items
     * @param  array{hq: Warehouse, dc: Warehouse}  $warehouses
     */
    private function seedReplenishment(array $items, array $warehouses): void
    {
        foreach ($this->itemCatalog() as $row) {
            if (! $row['replenish']) {
                continue;
            }
            $item = $items[$row['item_code']];
            foreach ([$warehouses['hq'], $warehouses['dc']] as $warehouse) {
                ItemWarehouseReplenishment::query()->updateOrCreate(
                    [
                        'item_id' => $item->id,
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'safety_stock_qty' => '24',
                        'reorder_point_qty' => '48',
                        'reorder_qty' => '96',
                        'max_qty' => '480',
                        'lead_time_days' => 7,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  list<array<string, mixed>>  $peers
     * @param  array{key: int, retail: int, wholesale: int, horeca: int, mfg: int, pkg: int, dist: int}  $groups
     * @param  array{cash: int, card: int, bank: int, due: int, net30: int}  $payment
     * @param  array<string, Item>  $items
     */
    private function seedParties(array $company, array $peers, array $groups, array $payment, array $items): void
    {
        foreach ($peers as $peer) {
            $this->seedCustomer([
                'code' => 'CUST-'.strtoupper($peer['id']),
                'name' => $peer['name'],
                'email' => $peer['accounts_email'],
                'phone' => $peer['phone'],
                'group_id' => $groups['key'],
                'payment_method_id' => $payment['bank'],
                'payment_terms_id' => $payment['net30'],
                'wallet' => null,
                'vat_number' => $peer['tax_number'],
                'notes' => 'Trading company on this system.',
                'address' => $peer['address'],
                'city' => $peer['city'],
                'state' => $peer['state'],
                'country' => $peer['country_name'],
                'contact' => $peer['name'].' Accounts',
                'contact_email' => $peer['accounts_email'],
            ]);
            $supplier = $this->seedSupplier([
                'code' => 'SUP-'.strtoupper($peer['id']),
                'name' => $peer['name'],
                'company_name' => $peer['legal_name'],
                'email' => $peer['sales_email'],
                'phone' => $peer['phone'],
                'group_id' => $groups['mfg'],
                'payment_method_id' => $payment['bank'],
                'payment_terms_id' => $payment['net30'],
                'wallet' => null,
                'vat_number' => $peer['tax_number'],
                'notes' => 'Trading company on this system.',
                'address' => $peer['address'],
                'city' => $peer['city'],
                'state' => $peer['state'],
                'country' => $peer['country_name'],
                'contact' => $peer['name'].' Sales',
                'contact_email' => $peer['sales_email'],
            ]);
            $this->linkSupplierItems($supplier, $items, (string) $peer['id']);
        }

        if ($this->catalog === 'dairy') {
            $this->seedDairyTradeParties($groups, $payment, $items);

            return;
        }

        if ($this->catalog === 'beverage') {
            $this->seedBeverageTradeParties($groups, $payment, $items);

            return;
        }

        if (DemoTradeCatalogs::find($this->catalog) !== null) {
            $this->seedTradeParties($groups, $payment, $items);

            return;
        }

        $this->seedCustomer([
            'code' => 'CUST-ABC',
            'name' => 'ABC',
            'email' => 'abc.accounts@gmail.com',
            'phone' => '+961 1 444 000',
            'group_id' => $groups['retail'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-567890',
            'notes' => 'Department store. Not a tenant on this system.',
            'address' => 'ABC Dbayeh',
            'city' => 'Dbayeh',
            'state' => 'Mount Lebanon',
            'country' => 'Lebanon',
            'contact' => 'ABC Accounts Payable',
            'contact_email' => 'abc.accounts@gmail.com',
        ]);
        $this->seedCustomer([
            'code' => 'CUST-FAHED',
            'name' => 'Fahed Supermarket',
            'email' => 'fahed.accounts@gmail.com',
            'phone' => '+961 1 555 000',
            'group_id' => $groups['retail'],
            'payment_method_id' => $payment['card'],
            'payment_terms_id' => $payment['due'],
            'wallet' => null,
            'vat_number' => '601-678901',
            'notes' => 'Supermarket chain. Not a tenant on this system.',
            'address' => 'Hazmieh Main Road',
            'city' => 'Hazmieh',
            'state' => 'Mount Lebanon',
            'country' => 'Lebanon',
            'contact' => 'Fahed Accounts Payable',
            'contact_email' => 'fahed.accounts@gmail.com',
        ]);
        $this->seedCustomer([
            'code' => 'CUST-TSC',
            'name' => 'The Sultan Center',
            'email' => 'tsc.accounts@gmail.com',
            'phone' => '+961 1 500 100',
            'group_id' => $groups['retail'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-678910',
            'notes' => 'Regional supermarket. Not a tenant on this system.',
            'address' => 'Dora Highway',
            'city' => 'Dora',
            'state' => 'Mount Lebanon',
            'country' => 'Lebanon',
            'contact' => 'TSC Accounts Payable',
            'contact_email' => 'tsc.accounts@gmail.com',
        ]);
        $this->seedCustomer([
            'code' => 'CUST-BOULEVARD',
            'name' => 'Boulevard Supermarket',
            'email' => 'boulevard.accounts@gmail.com',
            'phone' => '+961 1 500 200',
            'group_id' => $groups['retail'],
            'payment_method_id' => $payment['card'],
            'payment_terms_id' => $payment['due'],
            'wallet' => null,
            'vat_number' => '601-678911',
            'notes' => 'Neighborhood supermarket. Not a tenant on this system.',
            'address' => 'Verdun Street',
            'city' => 'Beirut',
            'state' => 'Beirut',
            'country' => 'Lebanon',
            'contact' => 'Boulevard Accounts',
            'contact_email' => 'boulevard.accounts@gmail.com',
        ]);
        $this->seedCustomer([
            'code' => 'CUST-CASH',
            'name' => 'Bekaa Cash and Carry',
            'email' => 'bekaa.cash@gmail.com',
            'phone' => '+961 8 500 300',
            'group_id' => $groups['wholesale'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-678912',
            'notes' => 'Wholesale cash and carry. Not a tenant on this system.',
            'address' => 'Chtaura Roundabout',
            'city' => 'Chtaura',
            'state' => 'Bekaa',
            'country' => 'Lebanon',
            'contact' => 'Bekaa Purchasing',
            'contact_email' => 'bekaa.cash@gmail.com',
        ]);
        $this->seedCustomer([
            'code' => 'CUST-HOTELDI',
            'name' => 'Hotel-Dieu Cafeteria',
            'email' => 'hoteldieu.catering@gmail.com',
            'phone' => '+961 1 615 300',
            'group_id' => $groups['horeca'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-678913',
            'notes' => 'Hospital catering. Not a tenant on this system.',
            'address' => 'Hotel-Dieu de France',
            'city' => 'Achrafieh',
            'state' => 'Beirut',
            'country' => 'Lebanon',
            'contact' => 'Catering Manager',
            'contact_email' => 'hoteldieu.catering@gmail.com',
        ]);
        $this->seedCustomer([
            'code' => 'CUST-PHOENICIA',
            'name' => 'Phoenicia Hotel F&B',
            'email' => 'phoenicia.fnb@gmail.com',
            'phone' => '+961 1 369 100',
            'group_id' => $groups['horeca'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-678914',
            'notes' => 'Hotel food and beverage. Not a tenant on this system.',
            'address' => 'Minet El Hosn',
            'city' => 'Beirut',
            'state' => 'Beirut',
            'country' => 'Lebanon',
            'contact' => 'F&B Purchasing',
            'contact_email' => 'phoenicia.fnb@gmail.com',
        ]);
        $this->seedCustomer([
            'code' => 'CUST-RASBEIRUT',
            'name' => 'Ras Beirut Grocery',
            'email' => 'rasbeirut.grocery@gmail.com',
            'phone' => '+961 1 343 200',
            'group_id' => $groups['wholesale'],
            'payment_method_id' => $payment['cash'],
            'payment_terms_id' => $payment['due'],
            'wallet' => null,
            'vat_number' => '601-678915',
            'notes' => 'Independent grocer. Not a tenant on this system.',
            'address' => 'Bliss Street',
            'city' => 'Beirut',
            'state' => 'Beirut',
            'country' => 'Lebanon',
            'contact' => 'Store Owner',
            'contact_email' => 'rasbeirut.grocery@gmail.com',
        ]);

        $indevco = $this->seedSupplier([
            'code' => 'SUP-INDEVCO',
            'name' => 'Indevco',
            'company_name' => 'Indevco Group SAL',
            'email' => 'indevco.sales@gmail.com',
            'phone' => '+961 9 640 000',
            'group_id' => $groups['pkg'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-789012',
            'notes' => 'Packaging supplier. Not a tenant on this system.',
            'address' => 'Halat',
            'city' => 'Jbeil',
            'state' => 'Mount Lebanon',
            'country' => 'Lebanon',
            'contact' => 'Indevco Customer Service',
            'contact_email' => 'indevco.sales@gmail.com',
        ]);
        $this->linkSupplierItems($indevco, $items, 'indevco');

        $unilever = $this->seedSupplier([
            'code' => 'SUP-UNILEVER',
            'name' => 'Unilever Levant',
            'company_name' => 'Unilever Levant SAL',
            'email' => 'unilever.levant@gmail.com',
            'phone' => '+961 1 200 400',
            'group_id' => $groups['dist'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-789013',
            'notes' => 'Grocery and oil distributor. Not a tenant on this system.',
            'address' => 'Sin El Fil',
            'city' => 'Sin El Fil',
            'state' => 'Mount Lebanon',
            'country' => 'Lebanon',
            'contact' => 'Unilever Customer Service',
            'contact_email' => 'unilever.levant@gmail.com',
        ]);
        $this->linkSupplierItems($unilever, $items, 'unilever');

        $pg = $this->seedSupplier([
            'code' => 'SUP-PG',
            'name' => 'Procter and Gamble Levant',
            'company_name' => 'Procter & Gamble Levant SAL',
            'email' => 'pg.levant@gmail.com',
            'phone' => '+961 1 200 500',
            'group_id' => $groups['dist'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-789014',
            'notes' => 'Household and personal-care distributor. Not a tenant on this system.',
            'address' => 'Jdeideh',
            'city' => 'Jdeideh',
            'state' => 'Mount Lebanon',
            'country' => 'Lebanon',
            'contact' => 'P&G Customer Service',
            'contact_email' => 'pg.levant@gmail.com',
        ]);
        $this->linkSupplierItems($pg, $items, 'pg');
    }

    /**
     * Buyers and the cup supplier shared by Sahel Mashghara and Amleya Hypermarket.
     *
     * @param  array{key: int, retail: int, wholesale: int, horeca: int, mfg: int, pkg: int, dist: int}  $groups
     * @param  array{cash: int, card: int, bank: int, due: int, net30: int}  $payment
     * @param  array<string, Item>  $items
     */
    private function seedDairyTradeParties(array $groups, array $payment, array $items): void
    {
        $customers = [
            ['CUST-WBCOOP', 'West Bekaa Cooperative', 'westbekaa.coop@gmail.com', '+961 8 660 100', 'wholesale', 'bank', 'net30', '601-820101', 'Wholesale dairy buyer. Not a tenant on this system.', 'Main Road', 'Joub Jannine', 'Bekaa', 'West Bekaa Cooperative Buyer'],
            ['CUST-ZAHLEMKT', 'Zahle Family Market', 'zahle.market@gmail.com', '+961 8 660 110', 'retail', 'card', 'due', '601-820102', 'Supermarket. Not a tenant on this system.', 'Boulevard', 'Zahle', 'Bekaa', 'Zahle Family Market Accounts'],
            ['CUST-MASHBAKE', 'Mashghara Bakery', 'mashghara.bakery@gmail.com', '+961 8 660 120', 'horeca', 'cash', 'due', '601-820103', 'Bakery buying milk and butter. Not a tenant on this system.', 'Souk Street', 'Mashghara', 'Bekaa', 'Mashghara Bakery Owner'],
            ['CUST-JEZZINE', 'Jezzine Hotel Kitchen', 'jezzine.kitchen@gmail.com', '+961 7 660 130', 'horeca', 'bank', 'net30', '601-820104', 'Hotel kitchen. Not a tenant on this system.', 'Main Square', 'Jezzine', 'South Lebanon', 'Jezzine Kitchen Purchasing'],
            ['CUST-BDCORNER', 'Beirut Dairy Corner', 'beirut.dairycorner@gmail.com', '+961 1 660 140', 'retail', 'card', 'due', '601-820105', 'Dairy shop. Not a tenant on this system.', 'Hamra Street', 'Beirut', 'Beirut', 'Beirut Dairy Corner Accounts'],
            ['CUST-CANTEEN', 'School Canteen Network', 'canteen.network@gmail.com', '+961 1 660 150', 'key', 'bank', 'net30', '601-820106', 'School canteens. Not a tenant on this system.', 'Badaro', 'Beirut', 'Beirut', 'Canteen Purchasing'],
            ['CUST-DARELQ', 'Dar El Qamar Restaurant', 'darelqamar.restaurant@gmail.com', '+961 5 660 160', 'horeca', 'bank', 'net30', '601-820107', 'Restaurant. Not a tenant on this system.', 'Midan', 'Deir el Qamar', 'Mount Lebanon', 'Dar El Qamar Chef'],
            ['CUST-SAIDAWH', 'Saida Wholesale Market', 'saida.wholesale@gmail.com', '+961 7 660 170', 'wholesale', 'bank', 'net30', '601-820108', 'Wholesale market. Not a tenant on this system.', 'Port Road', 'Saida', 'South Lebanon', 'Saida Wholesale Buyer'],
            ['CUST-JOUNIEH', 'Jounieh Corner Store', 'jounieh.corner@gmail.com', '+961 9 660 180', 'retail', 'cash', 'due', '601-820109', 'Neighborhood store. Not a tenant on this system.', 'Old Souk', 'Jounieh', 'Mount Lebanon', 'Jounieh Corner Owner'],
            ['CUST-BATROUN', 'Batroun Resort Kitchen', 'batroun.resort@gmail.com', '+961 6 660 190', 'horeca', 'bank', 'net30', '601-820110', 'Resort kitchen. Not a tenant on this system.', 'Coast Road', 'Batroun', 'North Lebanon', 'Batroun Resort Purchasing'],
            ['CUST-NABATIEH', 'Nabatieh Grocery', 'nabatieh.grocery@gmail.com', '+961 7 660 200', 'retail', 'cash', 'due', '601-820111', 'Grocery. Not a tenant on this system.', 'Main Street', 'Nabatieh', 'Nabatieh', 'Nabatieh Grocery Owner'],
            ['CUST-TRIPOLI', 'Tripoli Dairy Depot', 'tripoli.dairy@gmail.com', '+961 6 660 210', 'wholesale', 'bank', 'net30', '601-820112', 'Dairy depot. Not a tenant on this system.', 'Azmi Street', 'Tripoli', 'North Lebanon', 'Tripoli Dairy Buyer'],
        ];

        foreach ($customers as [$code, $name, $email, $phone, $group, $pay, $terms, $vat, $notes, $address, $city, $state, $contact]) {
            $this->seedCustomer([
                'code' => $code,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'group_id' => $groups[$group],
                'payment_method_id' => $payment[$pay],
                'payment_terms_id' => $payment[$terms],
                'wallet' => null,
                'vat_number' => $vat,
                'notes' => $notes,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'country' => 'Lebanon',
                'contact' => $contact,
                'contact_email' => $email,
            ]);
        }

        $packaging = $this->seedSupplier([
            'code' => 'SUP-DAIRYPACK',
            'name' => 'Bekaa Cup Works',
            'company_name' => 'Bekaa Cup Works SAL',
            'email' => 'bekaa.cups@gmail.com',
            'phone' => '+961 8 650 400',
            'group_id' => $groups['pkg'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-820201',
            'notes' => 'Yogurt cup supplier. Not a tenant on this system.',
            'address' => 'Industrial Area',
            'city' => 'Mashghara',
            'state' => 'Bekaa',
            'country' => 'Lebanon',
            'contact' => 'Bekaa Cup Works Sales',
            'contact_email' => 'bekaa.cups@gmail.com',
        ]);
        $this->linkSupplierItems($packaging, $items, 'dairypack');
    }

    /**
     * Buyers and the bottle supplier shared by Sabil and Baytouna Market.
     *
     * @param  array{key: int, retail: int, wholesale: int, horeca: int, mfg: int, pkg: int, dist: int}  $groups
     * @param  array{cash: int, card: int, bank: int, due: int, net30: int}  $payment
     * @param  array<string, Item>  $items
     */
    private function seedBeverageTradeParties(array $groups, array $payment, array $items): void
    {
        $customers = [
            ['CUST-JOUNCAFE', 'Jounieh Sea Cafe', 'jounieh.seacafe@gmail.com', '+961 9 670 100', 'horeca', 'bank', 'net30', '601-830101', 'Cafe. Not a tenant on this system.', 'Sea Road', 'Jounieh', 'Mount Lebanon', 'Jounieh Sea Cafe Buyer'],
            ['CUST-DBAYEH', 'Dbayeh Beach Club', 'dbayeh.beach@gmail.com', '+961 4 670 110', 'horeca', 'bank', 'net30', '601-830102', 'Beach club. Not a tenant on this system.', 'Marina', 'Dbayeh', 'Mount Lebanon', 'Dbayeh Beach Purchasing'],
            ['CUST-HAMRA', 'Hamra Mini Market', 'hamra.minimarket@gmail.com', '+961 1 670 120', 'retail', 'cash', 'due', '601-830103', 'Mini market. Not a tenant on this system.', 'Hamra Street', 'Beirut', 'Beirut', 'Hamra Mini Market Owner'],
            ['CUST-ZAHLECAFE', 'Zahle Garden Cafe', 'zahle.garden@gmail.com', '+961 8 670 130', 'horeca', 'card', 'due', '601-830104', 'Cafe. Not a tenant on this system.', 'Berdawni', 'Zahle', 'Bekaa', 'Zahle Garden Cafe Buyer'],
            ['CUST-BYBLOS', 'Byblos Old Souk Store', 'byblos.souk@gmail.com', '+961 9 670 140', 'retail', 'cash', 'due', '601-830105', 'Souk store. Not a tenant on this system.', 'Old Souk', 'Jbeil', 'Mount Lebanon', 'Byblos Souk Owner'],
            ['CUST-AUBCAFE', 'AUB Cafeteria', 'aub.cafeteria@gmail.com', '+961 1 670 150', 'key', 'bank', 'net30', '601-830106', 'Campus cafeteria. Not a tenant on this system.', 'Bliss Street', 'Beirut', 'Beirut', 'AUB Cafeteria Purchasing'],
            ['CUST-VERDUN', 'Verdun Food Hall', 'verdun.foodhall@gmail.com', '+961 1 670 160', 'retail', 'card', 'due', '601-830107', 'Food hall. Not a tenant on this system.', 'Verdun Street', 'Beirut', 'Beirut', 'Verdun Food Hall Buyer'],
            ['CUST-TRIPCAFE', 'Tripoli Corniche Cafe', 'tripoli.corniche@gmail.com', '+961 6 670 170', 'horeca', 'bank', 'net30', '601-830108', 'Cafe. Not a tenant on this system.', 'Corniche', 'Tripoli', 'North Lebanon', 'Tripoli Corniche Buyer'],
            ['CUST-SAIDACAFE', 'Saida Seafront Cafe', 'saida.seafront@gmail.com', '+961 7 670 180', 'horeca', 'cash', 'due', '601-830109', 'Cafe. Not a tenant on this system.', 'Sea Road', 'Saida', 'South Lebanon', 'Saida Seafront Buyer'],
            ['CUST-METNWH', 'Metn Drinks Wholesale', 'metn.drinks@gmail.com', '+961 4 670 190', 'wholesale', 'bank', 'net30', '601-830110', 'Drinks wholesaler. Not a tenant on this system.', 'Dora Highway', 'Dora', 'Mount Lebanon', 'Metn Drinks Buyer'],
            ['CUST-HARBOR', 'Batroun Harbor Cafe', 'batroun.harbor@gmail.com', '+961 6 670 200', 'horeca', 'card', 'due', '601-830111', 'Harbor cafe. Not a tenant on this system.', 'Harbor', 'Batroun', 'North Lebanon', 'Batroun Harbor Buyer'],
            ['CUST-GEMMAYZEH', 'Gemmayzeh Bar', 'gemmayzeh.bar@gmail.com', '+961 1 670 210', 'horeca', 'bank', 'net30', '601-830112', 'Bar. Not a tenant on this system.', 'Gouraud Street', 'Beirut', 'Beirut', 'Gemmayzeh Bar Buyer'],
        ];

        foreach ($customers as [$code, $name, $email, $phone, $group, $pay, $terms, $vat, $notes, $address, $city, $state, $contact]) {
            $this->seedCustomer([
                'code' => $code,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'group_id' => $groups[$group],
                'payment_method_id' => $payment[$pay],
                'payment_terms_id' => $payment[$terms],
                'wallet' => null,
                'vat_number' => $vat,
                'notes' => $notes,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'country' => 'Lebanon',
                'contact' => $contact,
                'contact_email' => $email,
            ]);
        }

        $packaging = $this->seedSupplier([
            'code' => 'SUP-BOTTLEPACK',
            'name' => 'Jbeil Bottle Plant',
            'company_name' => 'Jbeil Bottle Plant SAL',
            'email' => 'jbeil.bottles@gmail.com',
            'phone' => '+961 9 540 900',
            'group_id' => $groups['pkg'],
            'payment_method_id' => $payment['bank'],
            'payment_terms_id' => $payment['net30'],
            'wallet' => null,
            'vat_number' => '601-830201',
            'notes' => 'Empty bottle supplier. Not a tenant on this system.',
            'address' => 'Industrial Zone',
            'city' => 'Jbeil',
            'state' => 'Mount Lebanon',
            'country' => 'Lebanon',
            'contact' => 'Jbeil Bottle Plant Sales',
            'contact_email' => 'jbeil.bottles@gmail.com',
        ]);
        $this->linkSupplierItems($packaging, $items, 'bottlepack');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function seedCustomer(array $row): void
    {
        $customer = Customer::query()->updateOrCreate(
            ['customer_code' => $row['code']],
            [
                'customer_group_id' => $row['group_id'],
                'payment_method_id' => $row['payment_method_id'],
                'payment_terms_id' => $row['payment_terms_id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'type' => CustomerType::Business,
                'status' => CustomerStatus::Active,
                'is_vat_registered' => $row['vat_number'] !== null,
                'vat_number' => $row['vat_number'],
                'wallet_address' => $row['wallet'],
                'wallet_type' => $row['wallet'] !== null ? WalletType::Wallet : null,
                'notes' => $row['notes'],
                'is_system' => false,
            ]
        );

        foreach (['billing', 'shipping'] as $type) {
            CustomerAddress::query()->updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'address_type' => $type,
                    'is_default' => true,
                ],
                [
                    'address_line_1' => $row['address'],
                    'city' => $row['city'],
                    'state' => $row['state'],
                    'country' => $row['country'],
                    'phone' => $row['phone'],
                ]
            );
        }

        CustomerContact::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'email' => $row['contact_email'],
            ],
            [
                'name' => $row['contact'],
                'phone' => $row['phone'],
                'position' => 'Accounts payable',
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function seedSupplier(array $row): Supplier
    {
        $supplier = Supplier::query()->updateOrCreate(
            ['supplier_code' => $row['code']],
            [
                'supplier_group_id' => $row['group_id'],
                'payment_method_id' => $row['payment_method_id'],
                'payment_terms_id' => $row['payment_terms_id'],
                'name' => $row['name'],
                'company_name' => $row['company_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'is_active' => true,
                'is_vat_registered' => $row['vat_number'] !== null,
                'vat_number' => $row['vat_number'],
                'wallet_address' => $row['wallet'],
                'wallet_type' => $row['wallet'] !== null ? WalletType::Wallet : null,
                'notes' => $row['notes'],
            ]
        );

        foreach (['billing', 'shipping'] as $type) {
            SupplierAddress::query()->updateOrCreate(
                [
                    'supplier_id' => $supplier->id,
                    'address_type' => $type,
                    'is_default' => true,
                ],
                [
                    'address_line_1' => $row['address'],
                    'city' => $row['city'],
                    'state' => $row['state'],
                    'country' => $row['country'],
                ]
            );
        }

        SupplierContact::query()->updateOrCreate(
            [
                'supplier_id' => $supplier->id,
                'email' => $row['contact_email'],
            ],
            [
                'name' => $row['contact'],
                'phone' => $row['phone'],
                'position' => 'Sales',
            ]
        );

        return $supplier;
    }

    /**
     * @param  array<string, Item>  $items
     */
    private function linkSupplierItems(Supplier $supplier, array $items, string $ownerKey): void
    {
        foreach ($this->itemCatalog() as $row) {
            if (! $row['supplier_item'] || $row['owner'] !== $ownerKey) {
                continue;
            }
            $item = $items[$row['item_code']];
            SupplierItem::query()->updateOrCreate(
                [
                    'supplier_id' => $supplier->id,
                    'item_id' => $item->id,
                ],
                [
                    'supplier_item_code' => $row['item_code'],
                    'last_purchase_price' => $row['cost'],
                    'lead_time_days' => 7,
                    'is_preferred' => true,
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array<string, Item>  $items
     * @param  array{hq: Warehouse, dc: Warehouse}  $warehouses
     */
    private function seedOpeningStock(array $company, array $items, array $warehouses): void
    {
        $ownerId = User::query()->where('email', $company['owner_email'])->value('id');
        if ($ownerId === null || $ownerId === '') {
            throw new \RuntimeException('Cannot seed opening stock without the company owner.');
        }

        $service = app(OpeningStockService::class);

        foreach (['hq' => $warehouses['hq'], 'dc' => $warehouses['dc']] as $key => $warehouse) {
            $qtyKey = $key.'_qty';
            $note = 'Demo opening stock '.$warehouse->shortcut_name;
            $alreadyPosted = OpeningStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('status', OpeningStockStatus::Posted)
                ->exists();
            if ($alreadyPosted || OpeningStock::query()->where('warehouse_id', $warehouse->id)->where('notes', $note)->exists()) {
                continue;
            }

            $lines = [];
            foreach ($this->itemCatalog() as $row) {
                if (! ($row['track'] ?? false) || (float) ($row[$qtyKey] ?? 0) <= 0) {
                    continue;
                }
                $item = $items[$row['item_code']];
                $baseUom = ItemUom::query()
                    ->where('item_id', $item->id)
                    ->where('is_base', true)
                    ->first();
                $lines[] = [
                    'item_id' => (string) $item->id,
                    'quantity' => $row[$qtyKey],
                    'item_uom_id' => $baseUom?->id,
                    'unit_cost' => $row['cost'],
                ];
            }

            if ($lines === []) {
                continue;
            }

            $document = $service->create(
                [
                    'warehouse_id' => (int) $warehouse->id,
                    'opening_date' => '2026-01-01',
                    'notes' => $note,
                    'lines' => $lines,
                ],
                $ownerId,
            );
            $service->post($document, $ownerId);
        }
    }

    private function seedSalesmen(array $company, Branch $branch, Warehouse $warehouse): void
    {
        foreach ($company['salesmen'] as $row) {
            Salesman::query()->updateOrCreate(
                ['salesman_code' => $row['code']],
                [
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'full_name' => $row['first_name'].' '.$row['last_name'],
                    'phone' => $row['phone'],
                    'email' => $row['email'],
                    'address' => $company['address'],
                    'commission_type' => CommissionType::Percent,
                    'commission_value' => '2.5000',
                    'target_amount' => '50000.0000',
                    'hire_date' => '2022-03-01',
                    'branch_id' => $branch->id,
                    'warehouse_id' => $warehouse->id,
                    'is_active' => true,
                    'notes' => 'Field sales for key accounts.',
                ]
            );
        }
    }
}
