<?php

declare(strict_types=1);

namespace App\Modules\PaymentTerm\Services;

use App\Modules\PaymentTerm\DTOs\PaymentTermData;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PaymentTermService
{
    private const CACHE_LIST = 'payment_terms.list';

    /**
     * @return Collection<int, PaymentTerm>
     */
    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            PaymentTerm::class,
            fn (): Collection => PaymentTerm::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
        );
    }

    public function create(PaymentTermData $data): PaymentTerm
    {
        return DB::transaction(function () use ($data): PaymentTerm {
            if ($data->isDefault) {
                PaymentTerm::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $created = PaymentTerm::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $created;
        });
    }

    public function update(PaymentTerm $paymentTerm, PaymentTermData $data): PaymentTerm
    {
        return DB::transaction(function () use ($paymentTerm, $data): PaymentTerm {
            if ($data->isDefault) {
                PaymentTerm::query()
                    ->where('is_default', true)
                    ->where('id', '!=', $paymentTerm->id)
                    ->update(['is_default' => false]);
            }

            $paymentTerm->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $paymentTerm->refresh();
        });
    }

    public function delete(PaymentTerm $paymentTerm): void
    {
        $paymentTerm->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
