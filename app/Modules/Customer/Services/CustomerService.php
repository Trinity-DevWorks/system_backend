<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Models\CustomerBalance;
use App\Modules\Customer\Models\CustomerContact;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        private readonly CustomerLedgerService $ledgerService
    ) {}

    public function listForTable(): Collection
    {
        return Customer::query()
            ->select([
                'id',
                'customer_code',
                'name',
                'customer_group_id',
                'salesman_id',
                'phone',
                'email',
                'status',
                'created_at',
                'updated_at',
            ])
            ->with([
                'customerGroup:id,name',
                'salesman:id,full_name,salesman_code',
            ])
            ->orderBy('name')
            ->get();
    }

    public function names(): Collection
    {
        return Customer::query()
            ->select(['id', 'customer_code', 'name', 'status', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated): Customer
    {
        return DB::transaction(function () use ($validated): Customer {
            $customer = Customer::query()->create([
                'customer_group_id' => $validated['customer_group_id'] ?? null,
                'salesman_id' => $validated['salesman_id'] ?? null,
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'payment_terms_id' => $validated['payment_terms_id'] ?? null,
                'vat_group_id' => $validated['vat_group_id'] ?? null,
                'customer_code' => null,
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'type' => $validated['type'],
                'status' => $validated['status'] ?? CustomerStatus::Active->value,
                'blacklist_reason' => $validated['blacklist_reason'] ?? null,
                'is_vat_registered' => (bool) ($validated['is_vat_registered'] ?? false),
                'is_exempted' => (bool) ($validated['is_exempted'] ?? false),
                'exemption_reason' => $validated['exemption_reason'] ?? null,
                'exempted_from' => $validated['exempted_from'] ?? null,
                'exempted_to' => $validated['exempted_to'] ?? null,
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

            if (($validated['currency_balances'] ?? []) !== []) {
                $this->lockCustomerForBalanceWrites($customer);

                foreach ($validated['currency_balances'] as $row) {
                    $currencyId = (int) $row['currency_id'];
                    $openingStr = isset($row['opening_balance']) ? (string) $row['opening_balance'] : '0';
                    $creditStr = isset($row['credit_limit']) ? (string) $row['credit_limit'] : '0';
                    $openingDate = $this->resolveOpeningBalanceDate($row, null);

                    CustomerBalance::query()->create([
                        'customer_id' => $customer->id,
                        'currency_id' => $currencyId,
                        'opening_balance' => $openingStr,
                        'opening_date' => $openingDate,
                        'credit_limit' => $creditStr,
                    ]);

                    $this->ledgerService->postOpeningBalance($customer, $currencyId, $openingStr, $openingDate);
                }
            }

            return $customer->load([
                'customerGroup',
                'balances.currency',
                'salesman',
                'paymentMethod',
                'paymentTerm',
                'vatGroup',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Customer $customer, array $patch): Customer
    {
        DB::transaction(function () use ($customer, $patch): void {
            $customer = $this->lockCustomerForBalanceWrites($customer);

            $currencyBalances = $patch['currency_balances'] ?? null;
            $scalar = collect($patch)->except(['currency_balances'])->all();

            $customer->fill($scalar);
            if (! $customer->is_vat_registered) {
                $customer->vat_number = null;
            }
            $customer->save();

            if (is_array($currencyBalances)) {
                $this->syncCurrencyBalances($customer, $currencyBalances);
            }
        });

        return $customer->refresh()->load([
            'customerGroup',
            'balances.currency',
            'salesman',
            'paymentMethod',
            'paymentTerm',
            'vatGroup',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncCurrencyBalances(Customer $customer, array $rows): void
    {
        $this->lockCustomerForBalanceWrites($customer);

        foreach ($rows as $row) {
            $currencyId = (int) $row['currency_id'];
            $balanceRow = $this->lockCustomerBalanceRow($customer, $currencyId);

            $prevOpening = $this->normalizeMoneyString((string) $balanceRow->opening_balance);
            $prevOpeningDate = $balanceRow->opening_date?->toDateString();

            $newOpening = array_key_exists('opening_balance', $row)
                ? $this->normalizeMoneyString((string) $row['opening_balance'])
                : $prevOpening;

            $newOpeningDate = $this->resolveOpeningBalanceDate($row, $balanceRow);

            $newCredit = array_key_exists('credit_limit', $row)
                ? $this->normalizeMoneyString((string) $row['credit_limit'])
                : $this->normalizeMoneyString((string) $balanceRow->credit_limit);

            $balanceRow->update([
                'opening_balance' => $newOpening,
                'opening_date' => $newOpeningDate,
                'credit_limit' => $newCredit,
            ]);

            if ($newOpening !== $prevOpening || $newOpeningDate !== $prevOpeningDate) {
                $this->ledgerService->replaceOpeningBalancePosting($customer, $currencyId, $newOpening, $newOpeningDate);
            }
        }
    }

    private function lockCustomerForBalanceWrites(Customer $customer): Customer
    {
        return Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
    }

    private function lockCustomerBalanceRow(Customer $customer, int $currencyId): CustomerBalance
    {
        $balance = CustomerBalance::query()
            ->where('customer_id', $customer->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        CustomerBalance::query()->create([
            'customer_id' => $customer->id,
            'currency_id' => $currencyId,
            'opening_balance' => '0.0000',
            'opening_date' => now()->toDateString(),
            'credit_limit' => '0.0000',
        ]);

        return CustomerBalance::query()
            ->where('customer_id', $customer->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveOpeningBalanceDate(array $row, ?CustomerBalance $existing): string
    {
        if (array_key_exists('opening_date', $row) && $row['opening_date'] !== null && $row['opening_date'] !== '') {
            return Carbon::parse((string) $row['opening_date'])->toDateString();
        }
        if ($existing?->opening_date !== null) {
            return $existing->opening_date->toDateString();
        }

        return now()->toDateString();
    }

    private function normalizeMoneyString(string $value): string
    {
        return number_format((float) $value, 4, '.', '');
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
