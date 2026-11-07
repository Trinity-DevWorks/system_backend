<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Customer\Enums\CustomerType;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentMethod\Models\PaymentMethodCashBankAccount;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Services\ModuleEntitlementService;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantSnapshot;

/**
 * Shared helpers for tests that need a real tenant (DB schema + owner user + permissions).
 *
 * Call setUpTenant() in setUp() and tearDownTenant() in tearDown(). The tenant is built once
 * per run and reset before each test, so tests stay isolated without rebuilding it.
 * Why transactions are off: Stancl creates Postgres schemas; those are invisible inside
 * RefreshDatabase's default transaction.
 */
trait InteractsWithTenant
{
    /**
     * Stancl creates tenant schemas via DDL. Postgres schemas created inside a
     * RefreshDatabase transaction are invisible to the separate tenant connection.
     *
     * @var list<string|null>
     */
    protected array $connectionsToTransact = [];

    protected Tenant $tenant;

    protected string $tenantDomain;

    protected User $tenantUser;

    /**
     * Gives the test a ready company. It is built (migrated + seeded) only once per test run;
     * later tests get the same company reset to its just-built state (see TenantSnapshot).
     */
    protected function setUpTenant(string $tenantId = 'test_tenant'): void
    {
        $this->tenantDomain = str_replace('_', '-', $tenantId).'.localhost';

        $snapshot = TenantSnapshot::get($tenantId);
        $existing = $snapshot !== null ? Tenant::query()->find($tenantId) : null;
        if ($snapshot !== null && $existing !== null) {
            TenantSnapshot::restore($tenantId);
            $this->tenant = $existing;
            $this->tenant->run(function () use ($snapshot): void {
                $this->tenantUser = User::query()->findOrFail($snapshot['user_id']);
            });

            return;
        }

        $this->buildTenant($tenantId);
        TenantSnapshot::capture($tenantId, (string) $this->tenantUser->id);
    }

    /** The slow path: a brand-new tenant schema, migrations, seeders, owner user and permissions. */
    private function buildTenant(string $tenantId): void
    {
        TenantSnapshot::forget($tenantId);
        TenantSnapshot::dropSnapshot($tenantId);
        $this->destroyTenantIfExists($tenantId);

        app(ModuleEntitlementService::class)->syncCatalog();

        $this->tenant = Tenant::query()->create([
            'id' => $tenantId,
            'name' => 'Test Company',
        ]);
        $this->tenant->domains()->create(['domain' => $this->tenantDomain]);

        app(ModuleEntitlementService::class)->grantAll($this->tenant);

        $this->tenant->run(function (): void {
            $this->tenantUser = User::factory()->create([
                'name' => 'Test Owner',
                'email' => 'owner@test.local',
                'is_active' => true,
            ]);

            BootstrapTenantRbac::dispatchSync($this->tenant, (string) $this->tenantUser->id);
            $this->tenantUser = $this->tenantUser->fresh() ?? $this->tenantUser;
        });
    }

    /** Leaves the company in place for the next test; it is reset at the next setUpTenant(). */
    protected function tearDownTenant(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        unset($this->tenant);
    }

    /**
     * Inside the tenant: a shared cash box / bank account on the given ledger account, linked to the method
     * (money of that method and currency then moves through it).
     */
    protected function linkCashBank(int $paymentMethodId, int $currencyId, string $ledgerCode, string $type = 'cash'): int
    {
        $ledgerId = (int) Account::query()->where('code', $ledgerCode)->value('id');
        $account = CashBankAccount::query()->firstOrCreate(
            ['account_id' => $ledgerId],
            [
                'code' => 'CB'.$ledgerCode,
                'name' => 'Cash/bank '.$ledgerCode,
                'type' => $type,
                'currency_id' => $currencyId,
                'branch_id' => null,
                'opening_balance' => 0,
                'is_active' => true,
            ],
        );
        PaymentMethodCashBankAccount::query()->firstOrCreate([
            'payment_method_id' => $paymentMethodId,
            'cash_bank_account_id' => $account->id,
        ]);

        return (int) $account->id;
    }

    protected function createCustomer(string $name = 'Test Customer'): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'type' => CustomerType::Individual,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function tenantJsonHeaders(?string $bearerToken = null): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($bearerToken !== null) {
            $headers['Authorization'] = 'Bearer '.$bearerToken;
        }

        return $headers;
    }

    /**
     * @return $this
     */
    protected function asTenantRequest(?string $bearerToken = null): static
    {
        return $this->withHeaders($this->tenantJsonHeaders($bearerToken));
    }

    protected function tenantUrl(string $path): string
    {
        return 'http://'.$this->tenantDomain.'/'.ltrim($path, '/');
    }

    protected function tenantBearerToken(): string
    {
        return $this->tenant->run(function (): string {
            return $this->tenantUser->createToken('tests')->plainTextToken;
        });
    }

    private function destroyTenantIfExists(string $tenantId): void
    {
        $existing = Tenant::query()->find($tenantId);
        if ($existing !== null) {
            try {
                $existing->delete();
            } catch (\Throwable) {
                $this->dropTenantSchemaIfExists($tenantId);
                Tenant::query()->whereKey($tenantId)->delete();
            }
        }

        $this->dropTenantSchemaIfExists($tenantId);
    }

    private function dropTenantSchemaIfExists(string $tenantId): void
    {
        try {
            DB::statement('DROP SCHEMA IF EXISTS "'.$tenantId.'" CASCADE');
        } catch (\Throwable) {
            // Central connection may not be ready during early bootstrap failures.
        }
    }
}
