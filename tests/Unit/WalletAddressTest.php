<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\WalletAddress;
use InvalidArgumentException;
use Tests\TestCase;

class WalletAddressTest extends TestCase
{
    public function test_empty_normalizes_to_null(): void
    {
        $this->assertNull(WalletAddress::normalize(null));
        $this->assertNull(WalletAddress::normalize(''));
        $this->assertNull(WalletAddress::normalize('   '));
    }

    public function test_checksum_address_is_lowercased(): void
    {
        $this->assertSame(
            '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
            WalletAddress::normalize('0x70997970C51812dc3A010C7d01b50e0d17dc79C8'),
        );
    }

    public function test_invalid_address_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WalletAddress::normalize('not-an-address');
    }

    public function test_eip7702_delegation_code_is_not_deployed_contract(): void
    {
        $delegation = '0xef010063c0c19a282a1b52b07dd5a65b58948a07dae32b';

        $this->assertTrue(WalletAddress::isEip7702DelegationCode($delegation));
        $this->assertTrue(WalletAddress::isEip7702DelegationCode(strtoupper($delegation)));
        $this->assertFalse(WalletAddress::hasDeployedContractCode($delegation));
        $this->assertFalse(WalletAddress::hasDeployedContractCode('0x'));
        $this->assertFalse(WalletAddress::hasDeployedContractCode('0x0'));
        $this->assertFalse(WalletAddress::hasDeployedContractCode(null));
        $this->assertTrue(WalletAddress::hasDeployedContractCode('0x6080604052'));
        $this->assertFalse(WalletAddress::isEip7702DelegationCode('0x6080604052'));
        $this->assertFalse(WalletAddress::isEip7702DelegationCode('0xef010063c0c19a282a1b52b07dd5a65b58948a07dae3'));
    }
}
