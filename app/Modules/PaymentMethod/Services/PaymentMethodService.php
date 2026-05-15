<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Services;

use App\Modules\PaymentMethod\DTOs\PaymentMethodData;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PaymentMethodService
{
    private const CACHE_LIST = 'payment_methods.list';

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function list(): Collection
    {
        $collection = TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            PaymentMethod::class,
            fn (): Collection => PaymentMethod::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
        );
        $collection->load(['currency:id,code,name']);

        return $collection;
    }

    public function create(PaymentMethodData $data): PaymentMethod
    {
        return DB::transaction(function () use ($data): PaymentMethod {
            if ($data->isDefault) {
                PaymentMethod::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $created = PaymentMethod::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);
            $created->load(['currency:id,code,name']);

            return $created;
        });
    }

    public function update(PaymentMethod $paymentMethod, PaymentMethodData $data): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $data): PaymentMethod {
            if ($data->isDefault) {
                PaymentMethod::query()
                    ->where('is_default', true)
                    ->where('id', '!=', $paymentMethod->id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);
            $paymentMethod->load(['currency:id,code,name']);

            return $paymentMethod->refresh();
        });
    }

    public function delete(PaymentMethod $paymentMethod): void
    {
        $paymentMethod->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
