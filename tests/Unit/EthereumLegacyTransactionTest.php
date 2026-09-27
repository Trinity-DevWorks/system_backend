<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\EthereumLegacyTransaction;
use App\Modules\InvoiceProof\Support\EthereumPersonalSign;
use Tests\TestCase;

class EthereumLegacyTransactionTest extends TestCase
{
    public function test_signed_raw_tx_is_even_length_hex(): void
    {
        $raw = EthereumLegacyTransaction::sign(
            [
                'nonce' => 0,
                'gas_price' => 1_000_000_000,
                'gas' => 21_000,
                'to' => '0x70997970C51812dc3A010C7d01b50e0d17dc79C8',
                'data' => '0x',
            ],
            '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80',
            11155111,
        );

        $this->assertMatchesRegularExpression('/^0x[0-9a-f]+$/', $raw);
        $this->assertSame(0, strlen(substr($raw, 2)) % 2);
        $this->assertSame(
            '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266',
            EthereumPersonalSign::addressFromPrivateKey(
                '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80',
            ),
        );
    }
}
