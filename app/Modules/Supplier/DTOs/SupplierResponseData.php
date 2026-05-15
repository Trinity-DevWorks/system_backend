<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Collection;

readonly class SupplierResponseData
{
    /**
     * @param  array<int, array<string, mixed>>  $currencyBalances
     * @param  array<string, mixed>|null  $supplierGroup
     * @param  array<string, mixed>|null  $paymentMethod
     * @param  array<string, mixed>|null  $paymentTerm
     * @param  array<string, mixed>|null  $vatGroup
     */
    public function __construct(
        public int $id,
        public string $supplierCode,
        public string $name,
        public ?string $companyName,
        public ?string $email,
        public ?string $phone,
        public ?int $supplierGroupId,
        public ?array $supplierGroup,
        public ?int $paymentMethodId,
        public ?array $paymentMethod,
        public ?int $paymentTermsId,
        public ?array $paymentTerm,
        public ?int $vatGroupId,
        public ?array $vatGroup,
        public string $creditLimit,
        public string $openingBalance,
        public bool $isActive,
        public bool $isVatRegistered,
        public bool $isExempted,
        public ?string $exemptionReason,
        public ?string $exemptedFrom,
        public ?string $exemptedTo,
        public ?string $vatNumber,
        public ?string $notes,
        public string $balance,
        public array $currencyBalances,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {}

    /**
     * @param  array<int, string>  $ledgerByCurrencyId  currency_id => balance
     */
    public static function fromModel(Supplier $supplier, array $ledgerByCurrencyId, ?int $primaryCurrencyId): self
    {
        $supplier->loadMissing([
            'supplierGroup',
            'balances.currency',
            'paymentMethod:id,code,name,type',
            'paymentTerm:id,code,name,due_days',
            'vatGroup:id,abrv,name,percentage',
        ]);

        $group = $supplier->supplierGroup;
        $pm = $supplier->paymentMethod;
        $pt = $supplier->paymentTerm;
        $vg = $supplier->vatGroup;

        $currencyBalances = [];
        foreach ($supplier->balances as $sb) {
            $cid = (int) $sb->currency_id;
            $cur = $sb->currency;
            $currencyBalances[] = [
                'currency_id' => $cid,
                'currency_code' => $cur?->code ?? '',
                'opening_balance' => (string) $sb->opening_balance,
                'opening_date' => $sb->opening_date?->toDateString(),
                'credit_limit' => (string) $sb->credit_limit,
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

        return new self(
            id: $supplier->id,
            supplierCode: (string) $supplier->supplier_code,
            name: $supplier->name,
            companyName: $supplier->company_name,
            email: $supplier->email,
            phone: $supplier->phone,
            supplierGroupId: $supplier->supplier_group_id,
            supplierGroup: $group ? ['id' => $group->id, 'name' => $group->name] : null,
            paymentMethodId: $supplier->payment_method_id,
            paymentMethod: $pm ? [
                'id' => $pm->id,
                'code' => $pm->code,
                'name' => $pm->name,
                'type' => $pm->type instanceof \BackedEnum ? $pm->type->value : (string) $pm->type,
            ] : null,
            paymentTermsId: $supplier->payment_terms_id,
            paymentTerm: $pt ? [
                'id' => $pt->id,
                'code' => $pt->code,
                'name' => $pt->name,
                'due_days' => (int) $pt->due_days,
            ] : null,
            vatGroupId: $supplier->vat_group_id,
            vatGroup: $vg ? [
                'id' => $vg->id,
                'abrv' => $vg->abrv,
                'name' => $vg->name,
                'percentage' => (string) $vg->percentage,
            ] : null,
            creditLimit: $primaryCredit,
            openingBalance: $primaryOpening,
            isActive: (bool) $supplier->is_active,
            isVatRegistered: (bool) $supplier->is_vat_registered,
            isExempted: (bool) $supplier->is_exempted,
            exemptionReason: $supplier->exemption_reason,
            exemptedFrom: $supplier->exempted_from?->toDateString(),
            exemptedTo: $supplier->exempted_to?->toDateString(),
            vatNumber: $supplier->vat_number,
            notes: $supplier->notes,
            balance: $primaryBalance,
            currencyBalances: $currencyBalances,
            createdAt: (string) $supplier->created_at,
            updatedAt: (string) $supplier->updated_at,
            deletedAt: $supplier->deleted_at?->toIso8601String(),
        );
    }

    /**
     * @param  Collection<int, Supplier>  $suppliers
     * @param  array<int, array<int, string>>  $ledgerGrouped  supplier_id => currency_id => balance
     */
    public static function collectionToArray(Collection $suppliers, array $ledgerGrouped, ?int $primaryCurrencyId): array
    {
        return $suppliers
            ->map(function (Supplier $s) use ($ledgerGrouped, $primaryCurrencyId): array {
                $byCur = $ledgerGrouped[$s->id] ?? [];

                return self::fromModel($s, $byCur, $primaryCurrencyId)->toArray();
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
            'supplier_code' => $this->supplierCode,
            'name' => $this->name,
            'company_name' => $this->companyName,
            'email' => $this->email,
            'phone' => $this->phone,
            'supplier_group_id' => $this->supplierGroupId,
            'supplier_group' => $this->supplierGroup,
            'payment_method_id' => $this->paymentMethodId,
            'payment_method' => $this->paymentMethod,
            'payment_terms_id' => $this->paymentTermsId,
            'payment_term' => $this->paymentTerm,
            'vat_group_id' => $this->vatGroupId,
            'vat_group' => $this->vatGroup,
            'credit_limit' => $this->creditLimit,
            'opening_balance' => $this->openingBalance,
            'currency_balances' => $this->currencyBalances,
            'is_active' => $this->isActive,
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
