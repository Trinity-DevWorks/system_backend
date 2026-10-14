<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Services;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\PaymentMethod\DTOs\PaymentMethodData;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Support\ListPagination;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentMethodService
{
    private const CACHE_LIST = 'payment_methods.list';

    private const RELATIONS = ['currency:id,code,name', 'account:id,code,name'];

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
        $collection->load(self::RELATIONS);

        return $collection;
    }

    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = PaymentMethod::query()
            ->with(self::RELATIONS)
            ->orderByDesc('is_default')
            ->orderBy('name');

        ListPagination::applySearch(
            $query,
            $search,
            ['name'],
            ['currency' => ['code', 'name']],
        );

        return $query->paginate($perPage);
    }

    public function create(PaymentMethodData $data): PaymentMethod
    {
        return DB::transaction(function () use ($data): PaymentMethod {
            $this->assertAccount($data->accountId);

            if ($data->isDefault) {
                PaymentMethod::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $created = PaymentMethod::query()->create($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);
            $created->load(self::RELATIONS);

            return $created;
        });
    }

    public function update(PaymentMethod $paymentMethod, PaymentMethodData $data): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $data): PaymentMethod {
            if ($data->accountId !== null && $data->accountId !== $paymentMethod->account_id) {
                $this->assertAccount($data->accountId);
            }

            if ($data->isDefault) {
                PaymentMethod::query()
                    ->where('is_default', true)
                    ->where('id', '!=', $paymentMethod->id)
                    ->update(['is_default' => false]);
            }

            $paymentMethod->update($data->toArray());
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $paymentMethod->refresh()->load(self::RELATIONS);
        });
    }

    public function delete(PaymentMethod $paymentMethod): void
    {
        $paymentMethod->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    private function assertAccount(?int $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        $account = Account::query()->find($accountId);
        if ($account === null || $account->is_group || ! $account->is_active || $account->type !== AccountType::Asset) {
            abort(422, 'The payment method account must be an active asset posting account.', [
                'X-Error-Code' => 'PAYMENT_METHOD_ACCOUNT_INVALID',
            ]);
        }
    }
}
