<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierContact;
use Illuminate\Support\Facades\DB;

class SupplierContactService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Supplier $supplier, array $data): SupplierContact
    {
        return DB::transaction(function () use ($supplier, $data): SupplierContact {
            return $supplier->contacts()->create([
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
    public function update(SupplierContact $contact, array $data): SupplierContact
    {
        return DB::transaction(function () use ($contact, $data): SupplierContact {
            $contact->update($data);

            return $contact->refresh();
        });
    }

    public function delete(SupplierContact $contact): void
    {
        $contact->delete();
    }
}
