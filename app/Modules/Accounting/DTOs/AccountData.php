<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Http\Requests\StoreAccountRequest;
use App\Modules\Accounting\Http\Requests\UpdateAccountRequest;

readonly class AccountData
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $nameAr,
        public bool $nameArProvided,
        public ?int $parentId,
        public ?AccountType $type,
        public ?NormalBalance $normalBalance,
        public bool $isGroup,
        public bool $isActive,
        public ?int $currencyId,
        public ?string $description,
    ) {}

    public static function fromStoreRequest(StoreAccountRequest $request): self
    {
        return self::fromValidatedArray($request->validated());
    }

    public static function fromUpdateRequest(UpdateAccountRequest $request): self
    {
        return self::fromValidatedArray($request->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidatedArray(array $data): self
    {
        $type = isset($data['type']) && $data['type'] !== '' ? AccountType::from((string) $data['type']) : null;
        $normal = isset($data['normal_balance']) && $data['normal_balance'] !== ''
            ? NormalBalance::from((string) $data['normal_balance'])
            : null;
        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        $nameAr = array_key_exists('name_ar', $data) ? trim((string) ($data['name_ar'] ?? '')) : null;

        return new self(
            code: trim((string) $data['code']),
            name: trim((string) $data['name']),
            nameAr: $nameAr === '' ? null : $nameAr,
            nameArProvided: array_key_exists('name_ar', $data),
            parentId: isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            type: $type,
            normalBalance: $normal,
            isGroup: (bool) ($data['is_group'] ?? false),
            isActive: (bool) ($data['is_active'] ?? true),
            currencyId: isset($data['currency_id']) ? (int) $data['currency_id'] : null,
            description: $description !== '' ? $description : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'parent_id' => $this->parentId,
            'type' => $this->type?->value,
            'normal_balance' => $this->normalBalance?->value,
            'is_group' => $this->isGroup,
            'is_active' => $this->isActive,
            'currency_id' => $this->currencyId,
            'description' => $this->description,
        ] + ($this->nameArProvided ? ['name_ar' => $this->nameAr] : []);
    }
}
