<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Enums\TenantStatus;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Database\Models\Domain;

readonly class TenantResponseData
{
    /**
     * @param  list<string>  $domains
     * @param  list<string>|null  $modules
     * @param  array{id: string, name: string, email: string|null}|null  $owner
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public string $status,
        public ?string $suspendedAt,
        public ?string $suspensionReason,
        public array $domains,
        public ?string $primaryDomain,
        public ?array $modules,
        public ?array $owner,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /**
     * @param  list<string>|null  $modules  Explicit module codes (detail); falls back to the loaded relation.
     * @param  array{id: string, name: string, email: string|null}|null  $owner
     */
    public static function fromModel(Tenant $tenant, ?array $modules = null, ?array $owner = null): self
    {
        $domains = $tenant->relationLoaded('domains')
            ? $tenant->domains->map(fn (Domain $d): string => (string) $d->domain)->values()->all()
            : [];

        if ($modules === null && $tenant->relationLoaded('modules')) {
            $modules = $tenant->modules->map(fn (Module $m): string => (string) $m->code)->sort()->values()->all();
        }

        $status = $tenant->status instanceof TenantStatus
            ? $tenant->status->value
            : (string) ($tenant->status ?? TenantStatus::Active->value);

        return new self(
            id: (string) $tenant->id,
            name: $tenant->name !== null ? (string) $tenant->name : null,
            status: $status,
            suspendedAt: $tenant->suspended_at !== null ? (string) $tenant->suspended_at : null,
            suspensionReason: $tenant->suspension_reason !== null ? (string) $tenant->suspension_reason : null,
            domains: $domains,
            primaryDomain: $domains[0] ?? null,
            modules: $modules,
            owner: $owner,
            createdAt: $tenant->created_at !== null ? (string) $tenant->created_at : null,
            updatedAt: $tenant->updated_at !== null ? (string) $tenant->updated_at : null,
        );
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $tenants): array
    {
        return $tenants
            ->map(fn (Tenant $t): array => self::fromModel($t)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'suspended_at' => $this->suspendedAt,
            'suspension_reason' => $this->suspensionReason,
            'domains' => $this->domains,
            'primary_domain' => $this->primaryDomain,
            'modules' => $this->modules,
            'owner' => $this->owner,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
