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
            $addressType = (string) $data['address_type'];

            if (! empty($data['is_default'])) {
                $supplier->addresses()
                    ->where('address_type', $addressType)
                    ->update(['is_default' => false]);
            }

            $address = $supplier->addresses()->create([
                'address_type' => $addressType,
                'address_line_1' => $data['address_line_1'],
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'],
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            if (! $supplier->addresses()->where('address_type', $addressType)->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            $this->collapseDuplicateDefaults($supplier, $addressType);

            return $address->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierAddress $address, array $data): SupplierAddress
    {
        return DB::transaction(function () use ($address, $data): SupplierAddress {
            $supplier = $address->supplier;
            $originalType = (string) $address->address_type;
            $newType = (string) ($data['address_type'] ?? $originalType);
            $isDefaultRequested = ! empty($data['is_default']);

            if ($newType !== $originalType && $address->is_default) {
                $data['is_default'] = true;
            }

            if (! empty($data['is_default'])) {
                $supplier->addresses()
                    ->where('id', '!=', $address->id)
                    ->where('address_type', $newType)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            if (! $supplier->addresses()->where('address_type', $newType)->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            if ($newType !== $originalType && ! $supplier->addresses()->where('address_type', $originalType)->where('is_default', true)->exists()) {
                $supplier->addresses()
                    ->where('address_type', $originalType)
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_default' => true]);
            }

            if ($isDefaultRequested || $address->is_default) {
                $this->collapseDuplicateDefaults($supplier, $newType);
            }

            if ($newType !== $originalType) {
                $this->collapseDuplicateDefaults($supplier, $originalType);
            }

            return $address->refresh();
        });
    }

    public function delete(SupplierAddress $address): void
    {
        $supplier = $address->supplier;
        $addressType = (string) $address->address_type;
        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $next = $supplier->addresses()
                ->where('address_type', $addressType)
                ->orderBy('id')
                ->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }
    }

    private function collapseDuplicateDefaults(Supplier $supplier, string $addressType): void
    {
        $defaultIds = $supplier->addresses()
            ->where('address_type', $addressType)
            ->where('is_default', true)
            ->pluck('id');

        if ($defaultIds->count() <= 1) {
            return;
        }

        $keep = $defaultIds->first();
        $supplier->addresses()
            ->where('address_type', $addressType)
            ->where('is_default', true)
            ->where('id', '!=', $keep)
            ->update(['is_default' => false]);
    }
}
