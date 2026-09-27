<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Customer\Models\Customer;
use App\Modules\InvoiceProof\Support\InvoicePartyWallets;
use App\Modules\InvoiceProof\Support\WalletAddress;
use Tests\TestCase;

class InvoicePartyWalletsTest extends TestCase
{
    public function test_missing_company_wallet_is_zero(): void
    {
        $this->assertSame(WalletAddress::ZERO, InvoicePartyWallets::supplier(null));
        $this->assertNull(InvoicePartyWallets::optionalSupplier(null));
    }

    public function test_empty_company_wallet_is_zero(): void
    {
        $company = new CompanyProfile;
        $company->wallet_address = '';

        $this->assertSame(WalletAddress::ZERO, InvoicePartyWallets::supplier($company));
        $this->assertNull(InvoicePartyWallets::optionalSupplier($company));
    }

    public function test_stored_company_wallet_is_used(): void
    {
        $company = new CompanyProfile;
        $company->wallet_address = '0x90F79bf6EB2c4f870365E785982E1f101E93b906';

        $this->assertSame(
            '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
            InvoicePartyWallets::supplier($company),
        );
    }

    public function test_stored_customer_wallet_is_used(): void
    {
        $customer = new Customer;
        $customer->wallet_address = '0x15d34AAf54267DB7D7c367839AAf71A00a2C6A65';

        $this->assertSame(
            '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            InvoicePartyWallets::buyer($customer),
        );
    }

    public function test_missing_customer_wallet_is_zero(): void
    {
        $this->assertSame(WalletAddress::ZERO, InvoicePartyWallets::buyer(null));
        $this->assertNull(InvoicePartyWallets::optionalBuyer(null));
    }
}
