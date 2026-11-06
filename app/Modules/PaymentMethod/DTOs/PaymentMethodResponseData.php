<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\DTOs;

use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentMethod\Services\PaymentMethodService;
use Illuminate\Support\Collection;

readonly class PaymentMethodResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $type,
        public ?int $currencyId,
        public ?string $currencyCode,
        public ?string $currencyName,
        public bool $requiresReference,
        public bool $supportsChange,
        public bool $isDefault,
        public bool $isActive,
        public ?string $notes,
        /** @var list<array<string, mixed>> */
        public array $cashBankAccounts,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(PaymentMethod $paymentMethod): self
    {
        $type = $paymentMethod->type instanceof PaymentMethodType
            ? $paymentMethod->type
            : PaymentMethodType::from((string) $paymentMethod->type);

        $currency = $paymentMethod->relationLoaded('currency') ? $paymentMethod->currency : null;

        return new self(
            id: $paymentMethod->id,
            code: (string) $paymentMethod->code,
            name: (string) $paymentMethod->name,
            type: $type->value,
            currencyId: $paymentMethod->currency_id,
            currencyCode: $currency?->code,
            currencyName: $currency?->name,
            requiresReference: (bool) $paymentMethod->requires_reference,
            supportsChange: (bool) $paymentMethod->supports_change,
            isDefault: self::branchDefaultId() === (int) $paymentMethod->id,
            isActive: (bool) $paymentMethod->is_active,
            notes: $paymentMethod->notes,
            cashBankAccounts: $paymentMethod->relationLoaded('cashBankAccounts')
                ? $paymentMethod->cashBankAccounts->map(fn ($row): array => [
                    'cash_bank_account_id' => (int) $row->cash_bank_account_id,
                    'cash_bank_account_code' => $row->cashBankAccount?->code,
                    'cash_bank_account_name' => $row->cashBankAccount?->name,
                    'currency_id' => $row->cashBankAccount?->currency_id !== null ? (int) $row->cashBankAccount->currency_id : null,
                    'currency_code' => $row->cashBankAccount?->currency?->code,
                    'branch_id' => $row->cashBankAccount?->branch_id !== null ? (int) $row->cashBankAccount->branch_id : null,
                    'branch_name' => $row->cashBankAccount?->branch?->name,
                ])->values()->all()
                : [],
            createdAt: (string) $paymentMethod->created_at,
            updatedAt: (string) $paymentMethod->updated_at,
        );
    }

    /**
     * @param  Collection<int, PaymentMethod>  $paymentMethods
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $paymentMethods): array
    {
        return $paymentMethods
            ->map(fn (PaymentMethod $paymentMethod): array => self::fromModel($paymentMethod)->toArray())
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
            'type' => $this->type,
            'currency_id' => $this->currencyId,
            'currency_code' => $this->currencyCode,
            'currency_name' => $this->currencyName,
            'requires_reference' => $this->requiresReference,
            'supports_change' => $this->supportsChange,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
            'cash_bank_accounts' => $this->cashBankAccounts,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /** Default method of the active branch, read once per request. */
    private static function branchDefaultId(): ?int
    {
        $attributes = request()->attributes;
        if (! $attributes->has('payment_method_branch_default')) {
            $attributes->set('payment_method_branch_default', PaymentMethodService::defaultIdForBranch(PaymentMethodService::activeBranchId()));
        }
        $id = $attributes->get('payment_method_branch_default');

        return is_int($id) ? $id : null;
    }
}
