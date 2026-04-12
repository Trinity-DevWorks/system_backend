<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierAddress;
use App\Modules\Supplier\Models\SupplierContact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function __construct(
        private readonly SupplierLedgerService $ledgerService
    ) {}

    public function list(): Collection
    {
        return Supplier::query()
            ->with('supplierGroup')
            ->orderBy('name')
            ->get();
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
            $opening = isset($validated['opening_balance']) ? (string) $validated['opening_balance'] : '0';

            $supplier = Supplier::query()->create([
                'supplier_group_id' => $validated['supplier_group_id'] ?? null,
                'supplier_code' => null,
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'credit_limit' => $validated['credit_limit'] ?? 0,
                'opening_balance' => $opening,
                'is_active' => $validated['is_active'] ?? true,
                'is_vat_registered' => (bool) ($validated['is_vat_registered'] ?? false),
                'vat_number' => $validated['vat_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $supplier->update([
                'supplier_code' => 'SUP-'.str_pad((string) $supplier->id, 4, '0', STR_PAD_LEFT),
            ]);
            $supplier->refresh();

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

            $this->ledgerService->postOpeningBalance($supplier, $opening);

            return $supplier->load('supplierGroup');
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Supplier $supplier, array $patch): Supplier
    {
        DB::transaction(function () use ($supplier, $patch): void {
            $supplier->fill($patch);
            if (! $supplier->is_vat_registered) {
                $supplier->vat_number = null;
            }
            $supplier->save();
        });

        return $supplier->refresh()->load('supplierGroup');
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
