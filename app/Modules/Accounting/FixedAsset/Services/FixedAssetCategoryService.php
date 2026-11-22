<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use App\Modules\Accounting\FixedAsset\Models\FixedAsset;
use App\Modules\Accounting\FixedAsset\Models\FixedAssetCategory;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Inventory\Item\Models\Item;
use App\Support\ListPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FixedAssetCategoryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, FixedAssetCategory>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = FixedAssetCategory::query()->with($this->accountRelations())->orderBy('code');
        if (($filters['active'] ?? null) !== null && $filters['active'] !== '') {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['code', 'name']);

        return $query->paginate($perPage);
    }

    public function find(int $id): FixedAssetCategory
    {
        return FixedAssetCategory::query()->with($this->accountRelations())->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FixedAssetCategory
    {
        $this->assertAccounts($data);

        return $this->find((int) FixedAssetCategory::query()->create($this->attributes($data))->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FixedAssetCategory $category, array $data): FixedAssetCategory
    {
        $merged = array_merge($category->only([
            'asset_account_id', 'accumulated_account_id', 'expense_account_id',
            'disposal_gain_account_id', 'disposal_loss_account_id', 'method',
        ]), $data);
        $this->assertAccounts($merged);
        $category->update($this->attributes($data, false));

        return $this->find((int) $category->id);
    }

    public function delete(FixedAssetCategory $category): void
    {
        $used = FixedAsset::query()->where('category_id', $category->id)->exists()
            || Item::query()->where('fixed_asset_category_id', $category->id)->exists();
        if ($used) {
            abort(409, 'This category is used by an asset or an item.', ['X-Error-Code' => 'FIXED_ASSET_CATEGORY_IN_USE']);
        }
        $category->delete();
    }

    /**
     * Mapped accounts a new category can start from. Missing mappings come back as null.
     *
     * @return array<string, array{id: int, code: string, name: string}|null>
     */
    public function defaults(): array
    {
        $roles = [
            'accumulated_account_id' => AccountRole::AccumulatedDepreciation,
            'expense_account_id' => AccountRole::DepreciationExpense,
            'disposal_loss_account_id' => AccountRole::AssetDisposalLoss,
            'disposal_gain_account_id' => AccountRole::AssetDisposalGain,
        ];
        $mappings = AccountMapping::query()->with('account')->whereIn('role', array_map(fn (AccountRole $role): string => $role->value, $roles))->get()->keyBy(fn (AccountMapping $mapping): string => $mapping->role->value);

        $out = [];
        foreach ($roles as $key => $role) {
            $account = $mappings->get($role->value)?->account;
            $out[$key] = $account === null ? null : [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(FixedAssetCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'code' => (string) $category->code,
            'name' => (string) $category->name,
            'method' => $category->method->value,
            'useful_life_months' => (int) $category->useful_life_months,
            'declining_rate' => $category->declining_rate !== null ? (string) $category->declining_rate : null,
            'salvage_percent' => (string) $category->salvage_percent,
            'prorate_first_month' => (bool) $category->prorate_first_month,
            'is_active' => (bool) $category->is_active,
            'asset_account_id' => (int) $category->asset_account_id,
            'accumulated_account_id' => (int) $category->accumulated_account_id,
            'expense_account_id' => (int) $category->expense_account_id,
            'disposal_gain_account_id' => (int) $category->disposal_gain_account_id,
            'disposal_loss_account_id' => (int) $category->disposal_loss_account_id,
            'asset_account' => $this->account($category->assetAccount),
            'accumulated_account' => $this->account($category->accumulatedAccount),
            'expense_account' => $this->account($category->expenseAccount),
            'disposal_gain_account' => $this->account($category->disposalGainAccount),
            'disposal_loss_account' => $this->account($category->disposalLossAccount),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertAccounts(array $data): void
    {
        $this->postingAccount((int) $data['asset_account_id'], AccountType::Asset);
        $this->postingAccount((int) $data['accumulated_account_id'], AccountType::Asset);
        $this->postingAccount((int) $data['expense_account_id'], AccountType::Expense);
        $this->postingAccount((int) $data['disposal_loss_account_id'], AccountType::Expense);
        $this->postingAccount((int) $data['disposal_gain_account_id'], AccountType::Revenue);
    }

    private function postingAccount(int $id, AccountType $type): void
    {
        $account = Account::query()->find($id);
        if ($account === null || ! $account->isPostable() || $account->type !== $type) {
            abort(422, 'Choose an active posting account of the right type.', ['X-Error-Code' => 'FIXED_ASSET_ACCOUNT_INVALID']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $creating = true): array
    {
        $out = [];
        foreach ([
            'name', 'asset_account_id', 'accumulated_account_id', 'expense_account_id',
            'disposal_gain_account_id', 'disposal_loss_account_id', 'useful_life_months', 'declining_rate',
            'salvage_percent', 'prorate_first_month', 'is_active',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        if (array_key_exists('code', $data)) {
            $out['code'] = strtoupper(trim((string) $data['code']));
        }
        if (array_key_exists('method', $data)) {
            $out['method'] = $data['method'] instanceof DepreciationMethod ? $data['method']->value : (string) $data['method'];
        }
        if ($creating && ! array_key_exists('is_active', $out)) {
            $out['is_active'] = true;
        }
        if (array_key_exists('declining_rate', $out) && ($out['declining_rate'] === '' || $out['declining_rate'] === null)) {
            $out['declining_rate'] = null;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function accountRelations(): array
    {
        return ['assetAccount', 'accumulatedAccount', 'expenseAccount', 'disposalGainAccount', 'disposalLossAccount'];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private function account(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => (int) $account->id,
            'code' => (string) $account->code,
            'name' => (string) $account->name,
        ];
    }
}
