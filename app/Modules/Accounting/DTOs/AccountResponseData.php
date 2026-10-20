<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\Account;

readonly class AccountResponseData
{
    /**
     * @param  list<string>  $roles
     * @param  array{debit: string, credit: string, balance: string}|null  $totals
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $nameAr,
        public ?int $parentId,
        public ?string $type,
        public ?string $normalBalance,
        public bool $isGroup,
        public bool $isActive,
        public ?int $currencyId,
        public ?string $currencyCode,
        public ?string $description,
        public array $roles,
        public ?array $totals,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @param  list<AccountRole>  $roles
     * @param  array{debit: string, credit: string}|null  $sum
     */
    public static function fromModel(Account $account, array $roles = [], ?array $sum = null): self
    {
        $currency = $account->relationLoaded('currency') ? $account->currency : null;

        return new self(
            id: (int) $account->id,
            code: (string) $account->code,
            name: (string) $account->name,
            nameAr: $account->name_ar !== null && trim((string) $account->name_ar) !== '' ? (string) $account->name_ar : null,
            parentId: $account->parent_id,
            type: $account->type?->value,
            normalBalance: $account->normal_balance?->value,
            isGroup: (bool) $account->is_group,
            isActive: (bool) $account->is_active,
            currencyId: $account->currency_id,
            currencyCode: $currency?->code,
            description: $account->description,
            roles: array_map(fn (AccountRole $role): string => $role->value, $roles),
            totals: $sum !== null ? [
                'debit' => $sum['debit'],
                'credit' => $sum['credit'],
                'balance' => bcsub($sum['debit'], $sum['credit'], 4),
            ] : null,
            createdAt: (string) $account->created_at,
            updatedAt: (string) $account->updated_at,
        );
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
            'name_ar' => $this->nameAr,
            'parent_id' => $this->parentId,
            'type' => $this->type,
            'normal_balance' => $this->normalBalance,
            'is_group' => $this->isGroup,
            'is_active' => $this->isActive,
            'currency_id' => $this->currencyId,
            'currency_code' => $this->currencyCode,
            'description' => $this->description,
            'roles' => $this->roles,
            'debit' => $this->totals['debit'] ?? null,
            'credit' => $this->totals['credit'] ?? null,
            'balance' => $this->totals['balance'] ?? null,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
