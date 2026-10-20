<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\DTOs\AccountData;
use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChartOfAccountsService
{
    /**
     * Copy a template into the tenant. Only missing codes and unmapped roles are inserted,
     * so running it again never overwrites what accountants changed.
     *
     * @return array{template: string, accounts_created: int, mappings_created: int, name_ar_filled: int}
     */
    public function seedFromTemplate(?string $templateKey = null): array
    {
        $key = $this->resolveTemplateKey($templateKey);
        /** @var array{accounts: list<array<string, mixed>>, system_accounts: array<string, string>} $template */
        $template = config('chart_of_accounts.templates.'.$key);

        return DB::transaction(function () use ($key, $template): array {
            /** @var array<string, int> $idsByCode */
            $idsByCode = Account::query()->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();

            $rows = $template['accounts'];
            usort($rows, fn (array $a, array $b): int => [strlen((string) $a['code']), (string) $a['code']]
                <=> [strlen((string) $b['code']), (string) $b['code']]);

            $createdAccounts = 0;
            $now = now();
            foreach (collect($rows)->groupBy(fn (array $row): int => strlen((string) $row['code'])) as $level) {
                $insert = [];
                foreach ($level as $row) {
                    $code = (string) $row['code'];
                    if (isset($idsByCode[$code])) {
                        continue;
                    }
                    $parent = $row['parent'] ?? null;
                    $nameAr = isset($row['name_ar']) ? trim((string) $row['name_ar']) : '';
                    $insert[] = [
                        'code' => $code,
                        'name' => (string) $row['name'],
                        'name_ar' => $nameAr !== '' ? $nameAr : null,
                        'parent_id' => $parent !== null ? ($idsByCode[(string) $parent] ?? null) : null,
                        'type' => $row['type'] ?? null,
                        'normal_balance' => $row['normal_balance'] ?? null,
                        'is_group' => (bool) ($row['is_group'] ?? false),
                        'is_active' => true,
                        'currency_id' => null,
                        'description' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($insert === []) {
                    continue;
                }
                Account::query()->insert($insert);
                $createdAccounts += count($insert);
                $idsByCode = Account::query()->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
            }

            $nameArFilled = 0;
            foreach ($rows as $row) {
                $nameAr = isset($row['name_ar']) ? trim((string) $row['name_ar']) : '';
                $code = (string) $row['code'];
                if ($nameAr === '' || ! isset($idsByCode[$code])) {
                    continue;
                }
                $updated = Account::query()
                    ->whereKey($idsByCode[$code])
                    ->whereNull('name_ar')
                    ->update(['name_ar' => $nameAr, 'updated_at' => $now]);
                $nameArFilled += $updated;
            }

            $mappedRoles = AccountMapping::query()->pluck('role')
                ->map(fn ($role): string => $role instanceof AccountRole ? $role->value : (string) $role)
                ->all();
            $createdMappings = 0;
            foreach ($template['system_accounts'] as $role => $code) {
                if (AccountRole::tryFrom((string) $role) === null
                    || in_array($role, $mappedRoles, true)
                    || ! isset($idsByCode[(string) $code])) {
                    continue;
                }
                AccountMapping::query()->create(['role' => $role, 'account_id' => $idsByCode[(string) $code]]);
                $createdMappings++;
            }

            $settings = CompanySetting::singleton();
            if ($settings->chart_template !== $key) {
                $settings->update(['chart_template' => $key]);
                app(CompanySettingService::class)->forgetCache();
            }

            return [
                'template' => $key,
                'accounts_created' => $createdAccounts,
                'mappings_created' => $createdMappings,
                'name_ar_filled' => $nameArFilled,
            ];
        });
    }

    public function resolveTemplateKey(?string $templateKey = null): string
    {
        $templates = (array) config('chart_of_accounts.templates', []);
        if ($templateKey !== null && $templateKey !== '') {
            if (! isset($templates[$templateKey])) {
                throw new InvalidArgumentException("Unknown chart of accounts template [{$templateKey}].");
            }

            return $templateKey;
        }

        $country = strtoupper((string) (CompanySetting::query()->value('country') ?? ''));
        $byCountry = (array) config('chart_of_accounts.country_templates', []);
        if ($country !== '' && isset($byCountry[$country], $templates[$byCountry[$country]])) {
            return (string) $byCountry[$country];
        }

        return (string) config('chart_of_accounts.default_template');
    }

    /**
     * @param  array{search?: ?string, type?: ?string, active?: ?bool, postable?: bool, prefix?: ?string}  $filters
     * @return Collection<int, Account>
     */
    public function list(array $filters = []): Collection
    {
        $query = Account::query()->with('currency:id,code,name')->orderBy('code');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('code', 'like', $search.'%')
                    ->orWhere('name', 'ilike', '%'.$search.'%')
                    ->orWhere('name_ar', 'ilike', '%'.$search.'%');
            });
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        if (! empty($filters['postable'])) {
            $query->where('is_group', false)->where('is_active', true);
        }
        if (! empty($filters['prefix'])) {
            $query->where('code', 'like', $filters['prefix'].'%');
        }

        return $query->get();
    }

    public function create(AccountData $data): Account
    {
        return DB::transaction(function () use ($data): Account {
            $this->assertStructure($data, null);
            $account = Account::query()->create($data->toArray());

            return $account->load('currency:id,code,name');
        });
    }

    public function update(Account $account, AccountData $data): Account
    {
        return DB::transaction(function () use ($account, $data): Account {
            /** @var Account $account */
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            $this->assertStructure($data, $account);

            $hasChildren = $account->children()->exists();
            if ($hasChildren && ($data->code !== $account->code || ! $data->isGroup)) {
                abort(422, 'An account with sub-accounts cannot change its code or stop being a group.', [
                    'X-Error-Code' => 'ACCOUNT_HAS_CHILDREN',
                ]);
            }

            if ($this->hasLines($account)
                && ($data->type !== $account->type
                    || $data->isGroup !== (bool) $account->is_group
                    || $data->currencyId !== $account->currency_id)) {
                abort(422, 'Type, group and currency cannot change once the account has journal lines.', [
                    'X-Error-Code' => 'ACCOUNT_LOCKED',
                ]);
            }

            if ($account->is_active && ! $data->isActive && $this->isMapped($account)) {
                abort(422, 'This account is used by an account mapping and cannot be deactivated.', [
                    'X-Error-Code' => 'ACCOUNT_MAPPED',
                ]);
            }

            if ($this->isMapped($account) && ($data->isGroup || ! $this->mappedRolesAllow($account, $data))) {
                abort(422, 'This account is used by an account mapping that requires its current type.', [
                    'X-Error-Code' => 'ACCOUNT_MAPPED',
                ]);
            }

            $account->update($data->toArray());

            return $account->refresh()->load('currency:id,code,name');
        });
    }

    public function delete(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            if ($this->hasLines($account)
                || $account->children()->exists()
                || $this->isMapped($account)
                || PaymentMethod::withTrashed()->where('account_id', $account->id)->exists()) {
                abort(422, 'This account is in use and cannot be deleted.', ['X-Error-Code' => 'ACCOUNT_IN_USE']);
            }

            $account->delete();
        });
    }

    public function hasLines(Account $account): bool
    {
        return JournalEntryLine::query()->where('account_id', $account->id)->exists();
    }

    public function isMapped(Account $account): bool
    {
        return AccountMapping::query()->where('account_id', $account->id)->exists();
    }

    private function mappedRolesAllow(Account $account, AccountData $data): bool
    {
        $roles = AccountMapping::query()->where('account_id', $account->id)->pluck('role');
        foreach ($roles as $role) {
            $role = $role instanceof AccountRole ? $role : AccountRole::from((string) $role);
            if (! $role->allows($data->type)) {
                return false;
            }
        }

        return true;
    }

    private function assertStructure(AccountData $data, ?Account $current): void
    {
        if ($data->parentId === null) {
            if (strlen($data->code) !== 1) {
                abort(422, 'A top-level account code must be a single digit.', ['X-Error-Code' => 'ACCOUNT_ROOT_CODE_INVALID']);
            }
        } else {
            $parent = Account::query()->find($data->parentId);
            if ($parent === null || ($current !== null && $parent->id === $current->id)) {
                abort(422, 'The parent account is invalid.', ['X-Error-Code' => 'ACCOUNT_PARENT_INVALID']);
            }
            if (! $parent->is_group) {
                abort(422, 'The parent account must be a group.', ['X-Error-Code' => 'ACCOUNT_PARENT_NOT_GROUP']);
            }
            if (strlen($data->code) <= strlen($parent->code) || ! str_starts_with($data->code, $parent->code)) {
                abort(422, 'The account code must start with the parent account code.', [
                    'X-Error-Code' => 'ACCOUNT_CODE_PARENT_MISMATCH',
                ]);
            }
        }

        if (! $data->isGroup && ($data->type === null || $data->normalBalance === null)) {
            abort(422, 'A posting account needs a type and a normal balance.', ['X-Error-Code' => 'ACCOUNT_TYPE_REQUIRED']);
        }
    }
}
