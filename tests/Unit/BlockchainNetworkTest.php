<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;
use App\Modules\InvoiceProof\Support\EthereumPersonalSign;
use App\Modules\InvoiceProof\Support\InvoicePartyWallets;
use Tests\TestCase;

class BlockchainNetworkTest extends TestCase
{
    public function test_defaults_to_anvil(): void
    {
        config(['blockchain.network' => 'anvil']);

        $this->assertSame(BlockchainNetwork::ANVIL, BlockchainNetwork::key());
        $this->assertFalse(BlockchainNetwork::isSepolia());
        $this->assertSame('wallet_address_anvil', BlockchainNetwork::walletColumn());
    }

    public function test_sepolia_wallet_column(): void
    {
        config([
            'blockchain.network' => 'sepolia',
            'blockchain.safe_tx_service_url' => 'https://api.safe.global/tx-service/sep/api',
        ]);

        $this->assertTrue(BlockchainNetwork::isSepolia());
        $this->assertSame('wallet_address_sepolia', BlockchainNetwork::walletColumn());
        $this->assertSame(
            'https://api.safe.global/tx-service/sep/api',
            BlockchainNetwork::safeTxServiceUrl(),
        );
    }

    public function test_company_wallet_follows_active_network(): void
    {
        $anvil = '0x90F79bf6EB2c4f870365E785982E1f101E93b906';
        $sepolia = '0x15d34AAf54267DB7D7c367839AAf71A00a2C6A65';

        config(['blockchain.network' => 'anvil']);
        $company = new CompanyProfile;
        $company->wallet_address_anvil = $anvil;
        $company->wallet_address_sepolia = $sepolia;

        $this->assertSame(
            '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
            InvoicePartyWallets::supplier($company),
        );

        config(['blockchain.network' => 'sepolia']);
        $this->assertSame(
            '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
            InvoicePartyWallets::supplier($company),
        );
    }

    public function test_anvil_account_zero_address_from_private_key(): void
    {
        $this->assertSame(
            '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            EthereumPersonalSign::addressFromPrivateKey(
                '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80',
            ),
        );
    }
}
