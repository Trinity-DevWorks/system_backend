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
        public ?string $email,
        public ?string $timezone,
        public ?string $openingTime,
        public ?string $closingTime,
        public ?string $managerId,
        public ?string $managerName,
        public bool $isActive,
        public bool $isDefault,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Branch $branch): self
    {
        $branch->loadMissing('manager:id,name');
        $manager = $branch->manager;

        return new self(
            id: $branch->id,
            name: $branch->name,
            shortcutName: $branch->shortcut_name,
            address: $branch->address,
            phone: $branch->phone,
            email: $branch->email,
            timezone: $branch->timezone,
            openingTime: self::formatTime($branch->opening_time),
            closingTime: self::formatTime($branch->closing_time),
            managerId: $branch->manager_id,
            managerName: $manager?->name,
            isActive: (bool) $branch->is_active,
            isDefault: (bool) $branch->is_default,
            createdAt: (string) $branch->created_at,
            updatedAt: (string) $branch->updated_at,
        );
    }

    private static function formatTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = (string) $value;

        if (preg_match('/^(\d{2}:\d{2})/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return $raw;
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $branches): array
    {
        return $branches
            ->map(fn (Branch $branch): array => self::fromModel($branch)->toArray())
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
            'shortcut_name' => $this->shortcutName,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'opening_time' => $this->openingTime,
            'closing_time' => $this->closingTime,
            'manager_id' => $this->managerId,
            'manager_name' => $this->managerName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
