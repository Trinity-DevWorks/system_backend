<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\Plan;
use Illuminate\Support\Collection;

readonly class PlanResponseData
{
    /**
     * @param  list<string>|null  $modules
     * @param  array<string, int|null>  $limits
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public string $currency,
        public string $billingCycle,
        public array $limits,
        public ?array $modules,
        public bool $isActive,
        public int $sortOrder,
        public ?int $tenantsCount,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Plan $plan): self
    {
        $tenantsCount = $plan->getAttribute('tenants_count');

        return new self(
            id: (int) $plan->id,
            name: (string) $plan->name,
            description: $plan->description !== null ? (string) $plan->description : null,
            price: (string) $plan->price,
            currency: (string) $plan->currency,
            billingCycle: $plan->billing_cycle->value,
            limits: array_combine(Plan::LIMITS, array_map(fn (string $key): ?int => $plan->limit($key), Plan::LIMITS)),
            modules: $plan->relationLoaded('modules') ? $plan->moduleCodes() : null,
            isActive: (bool) $plan->is_active,
            sortOrder: (int) $plan->sort_order,
            tenantsCount: $tenantsCount !== null ? (int) $tenantsCount : null,
            createdAt: (string) $plan->created_at,
            updatedAt: (string) $plan->updated_at,
        );
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $plans): array
    {
        return $plans->map(fn (Plan $p): array => self::fromModel($p)->toArray())->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing_cycle' => $this->billingCycle,
            'max_users' => $this->limits['users'],
            'max_branches' => $this->limits['branches'],
            'max_warehouses' => $this->limits['warehouses'],
            'max_storage_mb' => $this->limits['storage_mb'],
            'modules' => $this->modules,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
            'tenants_count' => $this->tenantsCount,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
