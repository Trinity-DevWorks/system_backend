<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierAddress;
use App\Modules\Supplier\Models\SupplierBalance;
use App\Modules\Supplier\Models\SupplierContact;
use App\Support\ListPagination;
use App\Support\OpeningDocumentService;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function __construct(
        private readonly OpeningDocumentService $openingDocuments,
    ) {}

    private function userId(): ?string
    {
        $id = auth()->id();

        return $id !== null ? (string) $id : null;
    }

    public function list(): Collection
    {
        return Supplier::query()
            ->with(['supplierGroup', 'balances.currency'])
            ->orderBy('name')
            ->get();
    }

    public function listForTable(): Collection
    {
        return Supplier::query()
            ->select([
                'id',
                'supplier_code',
                'name',
                'company_name',
                'supplier_group_id',
                'phone',
                'email',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->with(['supplierGroup:id,name'])
            ->orderBy('name')
            ->get();
    }

    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Supplier::query()
            ->select([
                'id',
                'supplier_code',
                'name',
                'company_name',
                'supplier_group_id',
                'phone',
                'email',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->with(['supplierGroup:id,name'])
            ->orderBy('name');

        ListPagination::applySearch(
            $query,
            $search,
            ['supplier_code', 'name', 'company_name', 'phone', 'email'],
            ['supplierGroup' => ['name']],
        );

        return $query->paginate($perPage);
    }

    public function names(): Collection
    {
        return Supplier::query()
            ->select(['id', 'supplier_code', 'name', 'is_active', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated): Supplier
    {
        return DB::transaction(function () use ($validated): Supplier {
            $supplier = Supplier::query()->create([
                'supplier_group_id' => $validated['supplier_group_id'] ?? null,
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'payment_terms_id' => $validated['payment_terms_id'] ?? null,
                'vat_group_id' => $validated['vat_group_id'] ?? null,
                'supplier_code' => SequentialCodeGenerator::next(Supplier::class, 'supplier_code', 'SUP-', 6),
                'name' => $validated['name'],
                'company_name' => $validated['company_name'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'is_vat_registered' => (bool) ($validated['is_vat_registered'] ?? false),
                'is_exempted' => (bool) ($validated['is_exempted'] ?? false),
                'exemption_reason' => $validated['exemption_reason'] ?? null,
                'exempted_from' => $validated['exempted_from'] ?? null,
                'exempted_to' => $validated['exempted_to'] ?? null,
                'vat_number' => $validated['vat_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $addresses = $validated['addresses'] ?? [];
            if ($addresses !== []) {
                $hasDefault = collect($addresses)->contains(fn (array $a): bool => ! empty($a['is_default']));
                foreach ($addresses as $index => $row) {
                    $isDefault = (bool) ($row['is_default'] ?? false);
                    if (! $hasDefault && $index === 0) {
                        $isDefault = true;
                    }
                    SupplierAddress::query()->create([
                        'supplier_id' => $supplier->id,
                        'address_line_1' => $row['address_line_1'],
                        'address_line_2' => $row['address_line_2'] ?? null,
                        'city' => $row['city'],
                        'state' => $row['state'],
                        'country' => $row['country'],
                        'is_default' => $isDefault,
                    ]);
                }
                $this->normalizeDefaultAddresses($supplier);
            }

            foreach ($validated['contacts'] ?? [] as $row) {
                SupplierContact::query()->create([
                    'supplier_id' => $supplier->id,
                    'name' => $row['name'],
                    'phone' => $row['phone'] ?? null,
                    'email' => $row['email'] ?? null,
                    'position' => $row['position'] ?? null,
                ]);
            }

            if (($validated['currency_balances'] ?? []) !== []) {
                $this->lockSupplierForBalanceWrites($supplier);

                foreach ($validated['currency_balances'] as $row) {
                    SupplierBalance::query()->create([
                        'supplier_id' => $supplier->id,
                        'currency_id' => (int) $row['currency_id'],
                        'credit_limit' => isset($row['credit_limit']) ? (string) $row['credit_limit'] : '0',
                    ]);
                }
            }

            if (($validated['opening_documents'] ?? []) !== []) {
                $this->syncOpeningDocuments($supplier, $validated['opening_documents']);
            }

            return $supplier->load([
                'supplierGroup',
                'balances.currency',
                'paymentMethod',
                'paymentTerm',
                'vatGroup',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Supplier $supplier, array $patch): Supplier
    {
        DB::transaction(function () use ($supplier, $patch): void {
            $supplier = $this->lockSupplierForBalanceWrites($supplier);

            $currencyBalances = $patch['currency_balances'] ?? null;
            $openingDocuments = $patch['opening_documents'] ?? null;
            $scalar = collect($patch)->except(['currency_balances', 'opening_documents'])->all();

            $supplier->fill($scalar);
            if (! $supplier->is_vat_registered) {
                $supplier->vat_number = null;
            }
            if (! $supplier->is_exempted) {
                $supplier->exemption_reason = null;
                $supplier->exempted_from = null;
                $supplier->exempted_to = null;
            }
            $supplier->save();

            if (is_array($currencyBalances)) {
                $this->syncCurrencyBalances($supplier, $currencyBalances);
            }

            if (is_array($openingDocuments)) {
                $this->syncOpeningDocuments($supplier, $openingDocuments);
            }
        });

        return $supplier->refresh()->load([
            'supplierGroup',
            'balances.currency',
            'paymentMethod',
            'paymentTerm',
            'vatGroup',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncCurrencyBalances(Supplier $supplier, array $rows): void
    {
        $this->lockSupplierForBalanceWrites($supplier);

        foreach ($rows as $row) {
            $balanceRow = $this->lockSupplierBalanceRow($supplier, (int) $row['currency_id']);
            if (array_key_exists('credit_limit', $row)) {
                $balanceRow->update(['credit_limit' => $this->normalizeMoneyString((string) $row['credit_limit'])]);
            }
        }
    }

    /**
     * Old open balances as opening invoices / credits. Each currency used also gets a balance row,
     * so the supplier's balances list shows it.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncOpeningDocuments(Supplier $supplier, array $rows): void
    {
        $this->lockSupplierForBalanceWrites($supplier);
        foreach (array_unique(array_map(fn (array $row): int => (int) $row['currency_id'], $rows)) as $currencyId) {
            $this->lockSupplierBalanceRow($supplier, $currencyId);
        }
        $this->openingDocuments->syncSupplier($supplier, $rows, $this->userId());
    }

    private function lockSupplierForBalanceWrites(Supplier $supplier): Supplier
    {
        return Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
    }

    private function lockSupplierBalanceRow(Supplier $supplier, int $currencyId): SupplierBalance
    {
        $balance = SupplierBalance::query()
            ->where('supplier_id', $supplier->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        SupplierBalance::query()->create([
            'supplier_id' => $supplier->id,
            'currency_id' => $currencyId,
            'credit_limit' => '0.0000',
        ]);

        return SupplierBalance::query()
            ->where('supplier_id', $supplier->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizeMoneyString(string $value): string
    {
        return PriceMath::normalize($value);
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    public function normalizeDefaultAddresses(Supplier $supplier): void
    {
        $defaultIds = $supplier->addresses()->where('is_default', true)->pluck('id');
        if ($defaultIds->count() <= 1) {
            return;
        }

        $keep = $defaultIds->first();
        $supplier->addresses()
            ->where('is_default', true)
            ->where('id', '!=', $keep)
            ->update(['is_default' => false]);
    }
}
