<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customer\Enums\CustomerType;
use App\Modules\Customer\Models\Customer;
use App\Services\ModuleEntitlementService;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers to create a real tenant (DB schema + owner user + permissions) in tests.
 *
 * Used by: AttachmentServiceTest, CustomerAttachmentApiTest (and any future tenant feature tests).
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

    protected function setUpTenant(string $tenantId = 'attach_test'): void
    {
        $this->tenantDomain = str_replace('_', '-', $tenantId).'.localhost';

        $this->destroyTenantIfExists($tenantId);

        app(ModuleEntitlementService::class)->syncCatalog();

        $this->tenant = Tenant::query()->create([
            'id' => $tenantId,
            'name' => 'Attachment Test Tenant',
        ]);
        $this->tenant->domains()->create(['domain' => $this->tenantDomain]);

        app(ModuleEntitlementService::class)->grantAll($this->tenant);

        $this->tenant->run(function (): void {
            $this->tenantUser = User::factory()->create([
                'name' => 'Attachment Owner',
                'email' => 'owner@attach-test.local',
                'is_active' => true,
            ]);

            BootstrapTenantRbac::dispatchSync($this->tenant, (string) $this->tenantUser->id);
            $this->tenantUser = $this->tenantUser->fresh() ?? $this->tenantUser;
        });
    }

    protected function tearDownTenant(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        if (isset($this->tenant)) {
            $tenantId = (string) $this->tenant->getTenantKey();

            try {
                $this->tenant->delete();
            } catch (\Throwable) {
                $this->dropTenantSchemaIfExists($tenantId);
                Tenant::query()->whereKey($tenantId)->delete();
            }

            unset($this->tenant);
        }
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
            return $this->tenantUser->createToken('attachment-tests')->plainTextToken;
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
