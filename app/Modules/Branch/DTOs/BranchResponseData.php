<?php

declare(strict_types=1);

namespace App\Modules\Branch\DTOs;

use App\Modules\Branch\Models\Branch;
use Illuminate\Support\Collection;

readonly class BranchResponseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $shortcutName,
        public ?string $address,
        public ?string $phone,
        public ?string $timezone,
        public ?string $managerName,
        public bool $isActive,
        public bool $isDefault,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Branch $branch): self
    {
        return new self(
            id: $branch->id,
            name: $branch->name,
            shortcutName: $branch->shortcut_name,
            address: $branch->address,
            phone: $branch->phone,
            timezone: $branch->timezone,
            managerName: $branch->manager_name,
            isActive: (bool) $branch->is_active,
            isDefault: (bool) $branch->is_default,
            createdAt: (string) $branch->created_at,
            updatedAt: (string) $branch->updated_at,
        );
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array{
     *     id:int,
     *     name:string,
     *     shortcut_name:string,
     *     address:?string,
     *     phone:?string,
     *     timezone:?string,
     *     manager_name:?string,
     *     is_active:bool,
     *     is_default:bool,
     *     created_at:string,
     *     updated_at:string
     * }>
     */
    public static function collectionToArray(Collection $branches): array
    {
        return $branches
            ->map(fn (Branch $branch): array => self::fromModel($branch)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     id:int,
     *     name:string,
     *     shortcut_name:string,
     *     address:?string,
     *     phone:?string,
     *     timezone:?string,
     *     manager_name:?string,
     *     is_active:bool,
     *     is_default:bool,
     *     created_at:string,
     *     updated_at:string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shortcut_name' => $this->shortcutName,
            'address' => $this->address,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'manager_name' => $this->managerName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
