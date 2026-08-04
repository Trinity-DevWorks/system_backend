<?php

declare(strict_types=1);

namespace App\Modules\Branch\DTOs;

use App\Modules\Branch\Http\Requests\StoreBranchRequest;
use App\Modules\Branch\Http\Requests\UpdateBranchRequest;

readonly class BranchData
{
    public function __construct(
        public string $name,
        public string $shortcutName,
        public ?string $address,
        public ?string $phone,
        public ?string $timezone,
        public ?string $managerName,
        public bool $isActive,
        public bool $isDefault,
    ) {}

    public static function fromStoreRequest(StoreBranchRequest $request): self
    {
        return self::fromValidated($request->validated());
    }

    public static function fromUpdateRequest(UpdateBranchRequest $request): self
    {
        return self::fromValidated($request->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromValidated(array $data): self
    {
        return new self(
            name: $data['name'],
            shortcutName: $data['shortcut_name'],
            address: self::nullableString($data['address'] ?? null),
            phone: self::nullableString($data['phone'] ?? null),
            timezone: self::nullableString($data['timezone'] ?? null),
            managerName: self::nullableString($data['manager_name'] ?? null),
            isActive: (bool) $data['is_active'],
            isDefault: (bool) $data['is_default'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{
     *     name:string,
     *     shortcut_name:string,
     *     address:?string,
     *     phone:?string,
     *     timezone:?string,
     *     manager_name:?string,
     *     is_active:bool,
     *     is_default:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'shortcut_name' => $this->shortcutName,
            'address' => $this->address,
            'phone' => $this->phone,
            'timezone' => $this->timezone,
            'manager_name' => $this->managerName,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
        ];
    }
}
