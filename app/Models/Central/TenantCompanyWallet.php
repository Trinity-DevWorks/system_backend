<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Modules\InvoiceProof\Enums\WalletType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Published company wallets for one tenant. Other tenants use this to recognize a party.
 *
 * @property string $tenant_id
 * @property string $company_name
 * @property string|null $wallet_address_anvil
 * @property string|null $wallet_address_sepolia
 */
#[Fillable([
    'tenant_id',
    'company_name',
    'wallet_address_anvil',
    'wallet_type_anvil',
    'wallet_address_sepolia',
    'wallet_type_sepolia',
])]
class TenantCompanyWallet extends Model
{
    use CentralConnection;
    use HasUuids;

    protected $table = 'tenant_company_wallets';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wallet_type_anvil' => WalletType::class,
            'wallet_type_sepolia' => WalletType::class,
        ];
    }
}
