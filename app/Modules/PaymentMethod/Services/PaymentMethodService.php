<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Services;

use App\Modules\PaymentMethod\DTOs\PaymentMethodData;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentMethod\Models\PaymentMethodCashBankAccount;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Support\ListPagination;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentMethodService
{
    private const CACHE_LIST = 'payment_methods.list';

    public const RELATIONS = [
        'currency:id,code,name',
        'cashBankAccounts.cashBankAccount:id,code,name,currency_id,branch_id',
        'cashBankAccounts.cashBankAccount.currency:id,code',
        'cashBankAccounts.cashBankAccount.branch:id,name',
    ];

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function list(): Collection
    {
        $collection = TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            PaymentMethod::class,
            fn (): Collection => PaymentMethod::query()
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
            $created = PaymentMethod::query()->create($data->toArray());
            $this->setBranchDefault($created, $data->isDefault);
            TenantReferenceCache::forget(self::CACHE_LIST);
            $created->load(self::RELATIONS);

            return $created;
        });
    }

    public function update(PaymentMethod $paymentMethod, PaymentMethodData $data): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $data): PaymentMethod {
            $paymentMethod->update($data->toArray());
            $this->setBranchDefault($paymentMethod, $data->isDefault);
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $paymentMethod->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * Replace the cash boxes / bank accounts the method uses. Each branch may link one account per currency,
     * and so may the shared accounts (no branch), so a sale always finds exactly one.
     *
     * @param  list<mixed>  $accountIds
     */
    public function syncCashBankAccounts(PaymentMethod $paymentMethod, array $accountIds): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $accountIds): PaymentMethod {
            $accounts = CashBankAccount::query()->whereIn('id', array_map('intval', $accountIds))->get(['id', 'branch_id', 'currency_id']);
            $seen = [];
            foreach ($accounts as $account) {
                $key = ($account->branch_id ?? 'shared').'|'.$account->currency_id;
                if (isset($seen[$key])) {
                    abort(422, 'Link only one account per currency for each branch.', ['X-Error-Code' => 'PAYMENT_METHOD_ACCOUNT_DUPLICATE_BRANCH']);
                }
                $seen[$key] = true;
            }

            PaymentMethodCashBankAccount::query()->where('payment_method_id', $paymentMethod->id)->delete();
            foreach ($accounts as $account) {
                PaymentMethodCashBankAccount::query()->create(['payment_method_id' => $paymentMethod->id, 'cash_bank_account_id' => $account->id]);
            }
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $paymentMethod->refresh()->load(self::RELATIONS);
        });
    }

    /**
     * Default payment method of a branch (none when the branch has not chosen one).
     */
    public static function defaultIdForBranch(?int $branchId): ?int
    {
        if ($branchId === null) {
            return null;
        }
        $id = DB::table('branch_default_payment_methods')->where('branch_id', $branchId)->value('payment_method_id');

        return $id !== null ? (int) $id : null;
    }

    /** Branch the user is working in (from the branch header). */
    public static function activeBranchId(): ?int
    {
        $branchId = request()->attributes->get('active_branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }

    /**
     * The "default" switch applies to the branch the user is working in only.
     */
    private function setBranchDefault(PaymentMethod $paymentMethod, bool $isDefault): void
    {
        $branchId = self::activeBranchId();
        if ($branchId === null) {
            return;
        }
        if ($isDefault) {
            DB::table('branch_default_payment_methods')->updateOrInsert(
                ['branch_id' => $branchId],
                ['payment_method_id' => $paymentMethod->id, 'updated_at' => now(), 'created_at' => now()],
            );

            return;
        }
        DB::table('branch_default_payment_methods')
            ->where('branch_id', $branchId)
            ->where('payment_method_id', $paymentMethod->id)
            ->delete();
    }

    public function delete(PaymentMethod $paymentMethod): void
    {
        $paymentMethod->delete();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }
}
