<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Enums\CustomerType;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Collection;

readonly class CustomerResponseData
{
    /**
     * @param  array<int, array<string, mixed>>  $currencyBalances
     * @param  array<string, mixed>|null  $customerGroup
     * @param  array<string, mixed>|null  $salesman
     * @param  array<string, mixed>|null  $paymentMethod
     * @param  array<string, mixed>|null  $paymentTerm
     * @param  array<string, mixed>|null  $vatGroup
     */
    public function __construct(
        public int $id,
        public string $customerCode,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $type,
        public ?int $customerGroupId,
        public ?array $customerGroup,
        public ?int $salesmanId,
        public ?array $salesman,
        public ?int $paymentMethodId,
        public ?array $paymentMethod,
        public ?int $paymentTermsId,
        public ?array $paymentTerm,
        public ?int $vatGroupId,
        public ?array $vatGroup,
        public string $status,
        public ?string $blacklistReason,
        /** Primary-currency convenience (zeros if no primary or no row). */
        public string $creditLimit,
        public string $openingBalance,
        public bool $isVatRegistered,
        public bool $isExempted,
        public ?string $exemptionReason,
        public ?string $exemptedFrom,
        public ?string $exemptedTo,
        public ?string $vatNumber,
        public ?string $notes,
        /** Ledger balance in primary currency. */
        public string $balance,
        public array $currencyBalances,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {}

    /**
     * @param  array<int, string>  $ledgerByCurrencyId  currency_id => balance
     */
    public static function fromModel(Customer $customer, array $ledgerByCurrencyId, ?int $primaryCurrencyId): self
    {
        $customer->loadMissing([
            'customerGroup',
            'balances.currency',
            'salesman:id,salesman_code,full_name',
            'paymentMethod:id,code,name,type',
            'paymentTerm:id,code,name,due_days',
            'vatGroup:id,abrv,name,percentage',
        ]);

        $group = $customer->customerGroup;
        $sm = $customer->salesman;
        $pm = $customer->paymentMethod;
        $pt = $customer->paymentTerm;
        $vg = $customer->vatGroup;

        $currencyBalances = [];
        foreach ($customer->balances as $cb) {
            $cid = (int) $cb->currency_id;
            $cur = $cb->currency;
            $currencyBalances[] = [
                'currency_id' => $cid,
                'currency_code' => $cur?->code ?? '',
                'opening_balance' => (string) $cb->opening_balance,
                'opening_date' => $cb->opening_date?->toDateString(),
                'credit_limit' => (string) $cb->credit_limit,
                'balance' => $ledgerByCurrencyId[$cid] ?? '0.0000',
            ];
        }

        usort($currencyBalances, static fn (array $a, array $b): int => strcmp((string) $a['currency_code'], (string) $b['currency_code']));

        $primaryCredit = '0.0000';
        $primaryOpening = '0.0000';
        $primaryBalance = '0.0000';
        if ($primaryCurrencyId !== null) {
            foreach ($currencyBalances as $row) {
                if ((int) $row['currency_id'] === $primaryCurrencyId) {
                    $primaryCredit = (string) $row['credit_limit'];
                    $primaryOpening = (string) $row['opening_balance'];
                    $primaryBalance = (string) $row['balance'];

                    break;
                }
            }
        }

        $status = $customer->status instanceof CustomerStatus
            ? $customer->status->value
            : (string) $customer->status;

        return new self(
            id: $customer->id,
            customerCode: (string) $customer->customer_code,
            name: $customer->name,
            email: $customer->email,
            phone: $customer->phone,
            type: $customer->type instanceof CustomerType ? $customer->type->value : (string) $customer->type,
            customerGroupId: $customer->customer_group_id,
            customerGroup: $group ? ['id' => $group->id, 'name' => $group->name] : null,
            salesmanId: $customer->salesman_id,
            salesman: $sm ? [
                'id' => $sm->id,
                'salesman_code' => $sm->salesman_code,
                'full_name' => $sm->full_name,
            ] : null,
            paymentMethodId: $customer->payment_method_id,
            paymentMethod: $pm ? [
                'id' => $pm->id,
                'code' => $pm->code,
                'name' => $pm->name,
                'type' => $pm->type instanceof \BackedEnum ? $pm->type->value : (string) $pm->type,
            ] : null,
            paymentTermsId: $customer->payment_terms_id,
            paymentTerm: $pt ? [
                'id' => $pt->id,
                'code' => $pt->code,
                'name' => $pt->name,
                'due_days' => (int) $pt->due_days,
            ] : null,
            vatGroupId: $customer->vat_group_id,
            vatGroup: $vg ? [
                'id' => $vg->id,
                'abrv' => $vg->abrv,
                'name' => $vg->name,
                'percentage' => (string) $vg->percentage,
            ] : null,
            status: $status,
            blacklistReason: $customer->blacklist_reason,
            creditLimit: $primaryCredit,
            openingBalance: $primaryOpening,
            isVatRegistered: (bool) $customer->is_vat_registered,
            isExempted: (bool) $customer->is_exempted,
            exemptionReason: $customer->exemption_reason,
            exemptedFrom: $customer->exempted_from?->toDateString(),
            exemptedTo: $customer->exempted_to?->toDateString(),
            vatNumber: $customer->vat_number,
            notes: $customer->notes,
            balance: $primaryBalance,
            currencyBalances: $currencyBalances,
            createdAt: (string) $customer->created_at,
            updatedAt: (string) $customer->updated_at,
            deletedAt: $customer->deleted_at?->toIso8601String(),
        );
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<int, array<int, string>>  $ledgerGrouped  customer_id => currency_id => balance
     */
    public static function collectionToArray(Collection $customers, array $ledgerGrouped, ?int $primaryCurrencyId): array
    {
        return $customers
            ->map(function (Customer $c) use ($ledgerGrouped, $primaryCurrencyId): array {
                $byCur = $ledgerGrouped[$c->id] ?? [];

                return self::fromModel($c, $byCur, $primaryCurrencyId)->toArray();
            })
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
            'customer_code' => $this->customerCode,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => $this->type,
            'customer_group_id' => $this->customerGroupId,
            'customer_group' => $this->customerGroup,
            'salesman_id' => $this->salesmanId,
            'salesman' => $this->salesman,
            'payment_method_id' => $this->paymentMethodId,
            'payment_method' => $this->paymentMethod,
            'payment_terms_id' => $this->paymentTermsId,
            'payment_term' => $this->paymentTerm,
            'vat_group_id' => $this->vatGroupId,
            'vat_group' => $this->vatGroup,
            'status' => $this->status,
            'blacklist_reason' => $this->blacklistReason,
            'credit_limit' => $this->creditLimit,
            'opening_balance' => $this->openingBalance,
            'currency_balances' => $this->currencyBalances,
            'is_vat_registered' => $this->isVatRegistered,
            'is_exempted' => $this->isExempted,
            'exemption_reason' => $this->exemptionReason,
            'exempted_from' => $this->exemptedFrom,
            'exempted_to' => $this->exemptedTo,
            'vat_number' => $this->vatNumber,
            'notes' => $this->notes,
            'balance' => $this->balance,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }
}
