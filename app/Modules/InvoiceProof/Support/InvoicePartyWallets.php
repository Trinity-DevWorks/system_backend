<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Customer\Models\Customer;

/**
 * Public addresses stamped on InvoiceRegistry. Missing wallets become the zero
 * address so registration can seal the hash without parties; approvals require
 * a later setParties fill.
 */
final class InvoicePartyWallets
{
    public const ZERO = WalletAddress::ZERO;

    public static function supplier(?CompanyProfile $company): string
    {
        return self::optionalSupplier($company) ?? WalletAddress::ZERO;
    }

    public static function buyer(?Customer $customer): string
    {
        return self::optionalBuyer($customer) ?? WalletAddress::ZERO;
    }

    public static function optionalSupplier(?CompanyProfile $company): ?string
    {
        return WalletAddress::normalize($company?->wallet_address);
    }

    public static function optionalBuyer(?Customer $customer): ?string
    {
        return WalletAddress::normalize($customer?->wallet_address);
    }
}
