<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\EthereumPersonalSign;
use kornrunner\Keccak;
use Tests\TestCase;

class EthereumPersonalSignTest extends TestCase
{
    public function test_keccak256_matches_ethereum_vectors(): void
    {
        $this->assertSame(
            'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470',
            Keccak::hash('', 256),
        );
        $this->assertSame(
            '1c8aff950685c2ed4bc3174f3472287b56d9517b9c948127319a09a7a36deac8',
            Keccak::hash('hello', 256),
        );
    }

    public function test_personal_sign_roundtrip_anvil_buyer(): void
    {
        $key = '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a';
        $expected = '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc';
        $message = "InvoiceProofPortal\ntenant:t\ninvoice:i\nnonce:n";

        $signature = EthereumPersonalSign::sign($message, $key);
        $this->assertIsString($signature);
        $this->assertSame($expected, EthereumPersonalSign::recoverAddress($message, $signature));
        $this->assertNotSame($expected, EthereumPersonalSign::recoverAddress($message.'x', $signature));
    }
}
