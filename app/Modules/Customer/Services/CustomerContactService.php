<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerContact;
use Illuminate\Support\Facades\DB;

class CustomerContactService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Customer $customer, array $data): CustomerContact
    {
        return DB::transaction(function () use ($customer, $data): CustomerContact {
            return $customer->contacts()->create([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'position' => $data['position'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerContact $contact, array $data): CustomerContact
    {
        return DB::transaction(function () use ($contact, $data): CustomerContact {
            $contact->update($data);

            return $contact->refresh();
        });
    }

    public function delete(CustomerContact $contact): void
    {
        $contact->delete();
    }
}
