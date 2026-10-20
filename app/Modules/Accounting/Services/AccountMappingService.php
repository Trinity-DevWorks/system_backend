<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Support\Facades\DB;

class AccountMappingService
{
    /** @var array<string, Account>|null */
    private ?array $resolved = null;

    /**
     * One row per role, mapped or not.
     *
     * @return list<array{role: AccountRole, account: ?Account}>
     */
    public function list(): array
    {
        $mappings = AccountMapping::query()
            ->with('account:id,code,name,name_ar,type,is_group,is_active')
            ->get()
            ->keyBy(fn (AccountMapping $m): string => $m->role->value);

        $rows = [];
        foreach (AccountRole::cases() as $role) {
            $rows[] = ['role' => $role, 'account' => $mappings->get($role->value)?->account];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $accountIdsByRole
     */
    public function update(array $accountIdsByRole): void
    {
        DB::transaction(function () use ($accountIdsByRole): void {
            $accounts = Account::query()->whereIn('id', array_values($accountIdsByRole))->get()->keyBy('id');

            foreach (AccountRole::cases() as $role) {
                $accountId = (int) ($accountIdsByRole[$role->value] ?? 0);
                /** @var Account|null $account */
                $account = $accounts->get($accountId);
                if ($account === null || $account->is_group || ! $account->is_active || ! $role->allows($account->type)) {
                    abort(422, "The account for [{$role->value}] must be an active posting account of an allowed type.", [
                        'X-Error-Code' => 'ACCOUNT_MAPPING_INVALID',
                    ]);
                }

                $mapping = AccountMapping::query()->firstOrNew(['role' => $role->value]);
                if ($mapping->account_id !== $account->id) {
                    $mapping->account_id = $account->id;
                    $mapping->save();
                }
            }

            $this->resolved = null;
        });
    }

    public function accountFor(AccountRole $role): Account
    {
        if ($this->resolved === null) {
            $this->resolved = AccountMapping::query()->with('account')->get()
                ->filter(fn (AccountMapping $m): bool => $m->account !== null)
                ->mapWithKeys(fn (AccountMapping $m): array => [$m->role->value => $m->account])
                ->all();
        }

        $account = $this->resolved[$role->value] ?? null;
        if ($account === null) {
            abort(422, "No account is mapped for [{$role->value}].", ['X-Error-Code' => 'ACCOUNT_MAPPING_MISSING']);
        }

        return $account;
    }

    /**
     * Roles mapped to the account, keyed by account id.
     *
     * @return array<int, list<AccountRole>>
     */
    public function rolesByAccount(): array
    {
        $out = [];
        foreach (AccountMapping::query()->get(['role', 'account_id']) as $mapping) {
            $out[(int) $mapping->account_id][] = $mapping->role;
        }

        return $out;
    }

    public function forget(): void
    {
        $this->resolved = null;
    }
}
