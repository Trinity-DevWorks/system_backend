<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Support;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Deferral\Enums\DeferralKind;

/**
 * A deferral account and its recognition account must be postable, active,
 * the right type, and not a controlled role (receivable, payable, inventory, VAT).
 */
final class DeferralAccountRules
{
    public function assertAccounts(DeferralKind $kind, Account $deferral, Account $recognition, ?int $currencyId = null): void
    {
        $this->assertOne($deferral, $kind->deferralAccountType()->value, 'deferral', $currencyId);
        $this->assertOne($recognition, $kind->recognitionAccountType()->value, 'recognition', null);
    }

    private function assertOne(Account $account, string $type, string $which, ?int $currencyId): void
    {
        if (! $account->isPostable() || $account->type === null || $account->type->value !== $type) {
            abort(422, "Choose an active, non-group {$type} account for the {$which} side.", [
                'X-Error-Code' => 'DEFERRAL_ACCOUNT_INVALID',
            ]);
        }
        if ($currencyId !== null && $account->currency_id !== null && (int) $account->currency_id !== $currencyId) {
            abort(422, "Account {$account->code} uses a different currency.", [
                'X-Error-Code' => 'DEFERRAL_ACCOUNT_INVALID',
            ]);
        }

        $roles = AccountMapping::query()->where('account_id', $account->id)->pluck('role');
        foreach ($roles as $roleValue) {
            $role = $roleValue instanceof AccountRole ? $roleValue : AccountRole::tryFrom((string) $roleValue);
            if ($role?->isControlled()) {
                abort(422, "Account {$account->code} is controlled by documents and cannot be used on a deferral.", [
                    'X-Error-Code' => 'DEFERRAL_ACCOUNT_INVALID',
                ]);
            }
        }
    }
}
