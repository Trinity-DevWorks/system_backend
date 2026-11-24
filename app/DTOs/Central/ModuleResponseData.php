<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Module;
use Illuminate\Support\Collection;

readonly class ModuleResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public bool $isCore,
        public int $sortOrder,
        public ?int $tenantsCount,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Module $module): self
    {
        $count = $module->getAttribute('tenants_count');

        return new self(
            id: (int) $module->id,
            code: (string) $module->code,
            name: (string) $module->name,
            description: $module->description !== null ? (string) $module->description : null,
            isCore: (bool) $module->is_core,
            sortOrder: (int) $module->sort_order,
            tenantsCount: $count !== null ? (int) $count : null,
            createdAt: (string) $module->created_at,
            updatedAt: (string) $module->updated_at,
        );
    }

    /**
     * @param  Collection<int, Module>  $modules
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $modules): array
    {
        return $modules
            ->map(fn (Module $m): array => self::fromModel($m)->toArray())
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
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_core' => $this->isCore,
            'sort_order' => $this->sortOrder,
            'tenants_count' => $this->tenantsCount,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
