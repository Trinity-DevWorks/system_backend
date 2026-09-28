<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Http\Responses\ApiResponse;
use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ModuleEntitlementService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Creates a tenant: schema + migrations (TenantCreated pipeline), domain, owner user,
 * RBAC bootstrap and default modules.
 */
final class TenantProvisioningService
{
    /** Subdomains that cannot be tenant workspace ids (central app + common hosts). */
    public const RESERVED_TENANT_SLUGS = ['app', 'www', 'api', 'admin', 'mail', 'ftp', 'cdn', 'static'];

    public function __construct(
        private readonly ModuleEntitlementService $modules,
    ) {}

    public static function isValidSlug(string $slug): bool
    {
        return $slug !== ''
            && ! in_array($slug, self::RESERVED_TENANT_SLUGS, true)
            && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) === 1
            && strlen($slug) <= 63;
    }

    /**
     * @param  array{name: string, domain: string, email: string, password: string}  $data
     * @return array{tenant: Tenant, owner: array{id: string, name: string, email: string}}
     */
    public function provision(array $data): array
    {
        $domain = strtolower(trim($data['domain']));
        $tenantId = $this->tenantIdFromHost($domain);

        if ($tenantId === '') {
            throw ValidationException::withMessages([
                'domain' => ['Could not derive a tenant id from this domain (use e.g. tenant.localhost).'],
            ]);
        }

        if (in_array($tenantId, self::RESERVED_TENANT_SLUGS, true)) {
            throw ValidationException::withMessages([
                'domain' => ['This subdomain is reserved.'],
            ]);
        }

        if (! self::isValidSlug($tenantId)) {
            throw ValidationException::withMessages([
                'domain' => ['Subdomain must start with a letter or digit, then letters, digits, underscores, or hyphens (max 63 chars).'],
            ]);
        }

        if (Tenant::query()->whereKey($tenantId)->exists()) {
            throw ValidationException::withMessages([
                'domain' => ['A tenant with this subdomain already exists.'],
            ]);
        }

        if (Domain::query()->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages([
                'domain' => ['This domain is already registered.'],
            ]);
        }

        $tenant = Tenant::create([
            'id' => $tenantId,
            'name' => $data['name'],
        ]);

        $tenant->domains()->create(['domain' => $domain]);

        $ownerName = "{$data['name']}_owner";
        $ownerUserId = null;

        $tenant->run(function () use ($data, $ownerName, &$ownerUserId): void {
            $user = User::query()->create([
                'name' => $ownerName,
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $ownerUserId = $user->id;
        });

        if ($ownerUserId === null) {
            throw new HttpResponseException(
                ApiResponse::error('Failed to create tenant owner user.', 500, null, [], null, null, 'TENANT_OWNER_CREATE_FAILED')
            );
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);
        $this->modules->assignDefaults($tenant);
        CentralOverviewService::forget();

        return [
            'tenant' => $tenant->refresh()->load(['domains', 'modules']),
            'owner' => [
                'id' => (string) $ownerUserId,
                'name' => $ownerName,
                'email' => $data['email'],
            ],
        ];
    }

    /**
     * First DNS label of the host is the tenant id / PostgreSQL schema name (same as legacy backend).
     */
    private function tenantIdFromHost(string $host): string
    {
        if ($host === '') {
            return '';
        }

        $first = strstr($host, '.', true);

        return $first !== false ? $first : $host;
    }
}
