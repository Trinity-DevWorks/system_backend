<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\BootstrapTenantRbac;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;

class TenantController extends Controller
{
    /** Subdomains that cannot be tenant workspace ids (central app + common hosts). */
    private const RESERVED_TENANT_SLUGS = ['app', 'www', 'api', 'admin', 'mail', 'ftp', 'cdn', 'static'];

    public function lookupByName(string $name): JsonResponse
    {
        $name = strtolower(trim($name));

        if ($name === '' || in_array($name, self::RESERVED_TENANT_SLUGS, true)) {
            return ApiResponse::notFound('Tenant not found.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) || strlen($name) > 63) {
            return ApiResponse::notFound('Tenant not found.');
        }

        $tenant = Tenant::query()->whereKey($name)->first();

        if ($tenant === null) {
            return ApiResponse::notFound('Tenant not found.');
        }

        return ApiResponse::success([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
        ], 'Tenant found.');
    }

    public function index(): JsonResponse
    {
        $tenants = Tenant::query()
            ->with('domains')
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success($tenants, 'Tenants fetched successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $domain = strtolower(trim($validated['domain']));
        $tenantId = $this->tenantIdFromHost($domain);

        if ($tenantId === '') {
            throw ValidationException::withMessages([
                'domain' => ['Could not derive a tenant id from this domain (use e.g. tenant.localhost).'],
            ]);
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $tenantId) || strlen($tenantId) > 63) {
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
            'name' => $validated['name'],
        ]);

        $tenant->domains()->create([
            'domain' => $domain,
        ]);

        $ownerUserId = null;

        $tenant->run(function () use ($validated, &$ownerUserId): void {
            $user = User::query()->create([
                'name' => "{$validated['name']}_owner",
                'email' => $validated['email'],
                'password' => $validated['password'],
                'active' => true,
            ]);
            $ownerUserId = $user->id;
        });

        if ($ownerUserId === null) {
            return ApiResponse::error('Failed to create tenant owner user.', 500);
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerUserId);

        return ApiResponse::created([
            'tenant' => $tenant->load('domains'),
            'owner' => [
                'name' => "{$validated['name']}_owner",
                'email' => $validated['email'],
            ],
        ], 'Tenant created successfully.');
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
