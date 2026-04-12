<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Models\CustomerContact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        private readonly CustomerLedgerService $ledgerService
    ) {}

    public function list(): Collection
    {
        return Customer::query()
            ->with('customerGroup')
            ->orderBy('name')
            ->get();
    }

    public function names(): Collection
    {
        return Customer::query()
            ->select(['id', 'customer_code', 'name', 'is_active', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated): Customer
    {
        return DB::transaction(function () use ($validated): Customer {
            $opening = isset($validated['opening_balance']) ? (string) $validated['opening_balance'] : '0';

            $customer = Customer::query()->create([
                'customer_group_id' => $validated['customer_group_id'] ?? null,
                'customer_code' => null,
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'type' => $validated['type'],
                'credit_limit' => $validated['credit_limit'] ?? 0,
                'opening_balance' => $opening,
                'is_active' => $validated['is_active'] ?? true,
                'is_vat_registered' => (bool) ($validated['is_vat_registered'] ?? false),
                'vat_number' => $validated['vat_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $customer->update([
                'customer_code' => 'CUST-'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT),
            ]);
            $customer->refresh();

            $addresses = $validated['addresses'] ?? [];
            if ($addresses !== []) {
                $hasDefault = collect($addresses)->contains(fn (array $a): bool => ! empty($a['is_default']));
                foreach ($addresses as $index => $row) {
                    $isDefault = (bool) ($row['is_default'] ?? false);
                    if (! $hasDefault && $index === 0) {
                        $isDefault = true;
                    }
                    CustomerAddress::query()->create([
                        'customer_id' => $customer->id,
                        'address_line_1' => $row['address_line_1'],
                        'address_line_2' => $row['address_line_2'] ?? null,
                        'city' => $row['city'],
                        'state' => $row['state'],
                        'country' => $row['country'],
                        'is_default' => $isDefault,
                    ]);
                }
                $this->normalizeDefaultAddresses($customer);
            }

            foreach ($validated['contacts'] ?? [] as $row) {
                CustomerContact::query()->create([
                    'customer_id' => $customer->id,
                    'name' => $row['name'],
                    'phone' => $row['phone'] ?? null,
                    'email' => $row['email'] ?? null,
                    'position' => $row['position'] ?? null,
                ]);
            }

            $this->ledgerService->postOpeningBalance($customer, $opening);

            return $customer->load('customerGroup');
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Customer $customer, array $patch): Customer
    {
        DB::transaction(function () use ($customer, $patch): void {
            $customer->fill($patch);
            if (! $customer->is_vat_registered) {
                $customer->vat_number = null;
            }
            $customer->save();
        });

        return $customer->refresh()->load('customerGroup');
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    public function normalizeDefaultAddresses(Customer $customer): void
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
