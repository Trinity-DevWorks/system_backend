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
            if (! empty($data['is_default'])) {
                $customer->addresses()->update(['is_default' => false]);
            }

            $address = $customer->addresses()->create([
                'address_line_1' => $data['address_line_1'],
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'],
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);

            if (! $customer->addresses()->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            $this->collapseDuplicateDefaults($customer);

            return $address->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($address, $data): CustomerAddress {
            if (! empty($data['is_default'])) {
                $address->customer->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            if (! $address->customer->addresses()->where('is_default', true)->exists()) {
                $address->update(['is_default' => true]);
            }

            $this->collapseDuplicateDefaults($address->customer);

            return $address->refresh();
        });
    }

    public function delete(CustomerAddress $address): void
    {
        $customer = $address->customer;
        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $next = $customer->addresses()->orderBy('id')->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }
    }

    private function collapseDuplicateDefaults(Customer $customer): void
    {
        $defaultIds = $customer->addresses()->where('is_default', true)->pluck('id');
        if ($defaultIds->count() <= 1) {
            return;
        }

        $keep = $defaultIds->first();
        $customer->addresses()
            ->where('is_default', true)
            ->where('id', '!=', $keep)
            ->update(['is_default' => false]);
    }
}
