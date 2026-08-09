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
        public ?string $email,
        public ?string $timezone,
        public ?string $openingTime,
        public ?string $closingTime,
        public ?string $managerId,
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
            email: self::nullableString($data['email'] ?? null),
            timezone: self::nullableString($data['timezone'] ?? null),
            openingTime: self::nullableTime($data['opening_time'] ?? null),
            closingTime: self::nullableTime($data['closing_time'] ?? null),
            managerId: isset($data['manager_id']) && $data['manager_id'] !== ''
                ? (string) $data['manager_id']
                : null,
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

    private static function nullableTime(mixed $value): ?string
    {
        $trimmed = self::nullableString($value);
        if ($trimmed === null) {
            return null;
        }

        // Normalize H:i → H:i:s for MySQL TIME columns.
        if (preg_match('/^\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed.':00';
        }

        return $trimmed;
    }

    /**
     * @return array{
     *     name:string,
     *     shortcut_name:string,
     *     address:?string,
     *     phone:?string,
     *     email:?string,
     *     timezone:?string,
     *     opening_time:?string,
     *     closing_time:?string,
     *     manager_id:?string,
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
            'email' => $this->email,
            'timezone' => $this->timezone,
            'opening_time' => $this->openingTime,
            'closing_time' => $this->closingTime,
            'manager_id' => $this->managerId,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
        ];
    }
}
