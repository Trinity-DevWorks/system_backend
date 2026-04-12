<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierAddress;
use Illuminate\Support\Facades\DB;

class SupplierAddressService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Supplier $supplier, array $data): SupplierAddress
    {
        return DB::transaction(function () use ($supplier, $data): SupplierAddress {
            if (! empty($data['is_default'])) {
                $supplier->addresses()->update(['is_default' => false]);
            }

            $address = $supplier->addresses()->create([
                'address_line_1' => $data['address_line_1'],
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'],
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            if (! $supplier->addresses()->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            $this->collapseDuplicateDefaults($supplier);

            return $address->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierAddress $address, array $data): SupplierAddress
    {
        return DB::transaction(function () use ($address, $data): SupplierAddress {
            if (! empty($data['is_default'])) {
                $address->supplier->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            if (! $address->supplier->addresses()->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            $this->collapseDuplicateDefaults($address->supplier);

            return $address->refresh();
        });
    }

    public function delete(SupplierAddress $address): void
    {
        $supplier = $address->supplier;
        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $next = $supplier->addresses()->orderBy('id')->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }
    }

    private function collapseDuplicateDefaults(Supplier $supplier): void
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
