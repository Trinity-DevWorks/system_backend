<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;

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

    public static function optionalVendor(?Supplier $supplier): ?string
    {
        return WalletAddress::normalize($supplier?->wallet_address);
    }

    /**
     * Purchase invoice: vendor is on-chain supplier, company Safe is buyer.
     *
     * @return array{supplier: string, buyer: string}
     */
    public static function forPurchase(?CompanyProfile $company, ?Supplier $vendor): array
    {
        return [
            'supplier' => self::optionalVendor($vendor) ?? WalletAddress::ZERO,
            'buyer' => self::optionalSupplier($company) ?? WalletAddress::ZERO,
        ];
    }

    /**
     * Sales invoice: company Safe is on-chain supplier, customer is buyer.
     *
     * @return array{supplier: string, buyer: string}
     */
    public static function forSales(?CompanyProfile $company, ?Customer $customer): array
    {
        return [
            'supplier' => self::supplier($company),
            'buyer' => self::buyer($customer),
        ];
    }
}
