<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

class CustomerAddressService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data): CustomerAddress {
            $addressType = (string) $data['address_type'];

            if (! empty($data['is_default'])) {
                $customer->addresses()
                    ->where('address_type', $addressType)
                    ->update(['is_default' => false]);
            }

            $address = $customer->addresses()->create([
                'address_type' => $addressType,
                'address_line_1' => $data['address_line_1'],
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'],
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            if (! $customer->addresses()->where('address_type', $addressType)->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            $this->collapseDuplicateDefaults($customer, $addressType);

            return $address->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($address, $data): CustomerAddress {
            $customer = $address->customer;
            $originalType = (string) $address->address_type;
            $newType = (string) ($data['address_type'] ?? $originalType);
            $isDefaultRequested = ! empty($data['is_default']);

            if ($newType !== $originalType && $address->is_default) {
                $data['is_default'] = true;
            }

            if (! empty($data['is_default'])) {
                $customer->addresses()
                    ->where('id', '!=', $address->id)
                    ->where('address_type', $newType)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            if (! $customer->addresses()->where('address_type', $newType)->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            if ($newType !== $originalType && ! $customer->addresses()->where('address_type', $originalType)->where('is_default', true)->exists()) {
                $customer->addresses()
                    ->where('address_type', $originalType)
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_default' => true]);
            }

            if ($isDefaultRequested || $address->is_default) {
                $this->collapseDuplicateDefaults($customer, $newType);
            }

            if ($newType !== $originalType) {
                $this->collapseDuplicateDefaults($customer, $originalType);
            }

            return $address->refresh();
        });
    }

    public function delete(CustomerAddress $address): void
    {
        $customer = $address->customer;
        $addressType = (string) $address->address_type;
        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $next = $customer->addresses()
                ->where('address_type', $addressType)
                ->orderBy('id')
                ->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }
    }

    private function collapseDuplicateDefaults(Customer $customer, string $addressType): void
    {
        $defaultIds = $customer->addresses()
            ->where('address_type', $addressType)
            ->where('is_default', true)
            ->pluck('id');

        if ($defaultIds->count() <= 1) {
            return;
        }

        $keep = $defaultIds->first();
        $customer->addresses()
            ->where('address_type', $addressType)
            ->where('is_default', true)
            ->where('id', '!=', $keep)
            ->update(['is_default' => false]);
    }
}
