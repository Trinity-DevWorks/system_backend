<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;
use Tests\TestCase;

/**
 * Checks the content hash is the salted Merkle root and is stable.
 */
class CanonicalInvoiceHasherTest extends TestCase
{
    private const SECRET = 'abababababababababababababababababababababababababababababababab';

    public function test_same_canonical_json_always_produces_the_same_hash(): void
    {
        $json = '{"schema_version":2,"invoice_number":"INV-0001"}';

        $this->assertSame(
            CanonicalInvoiceHasher::hash($json, self::SECRET),
            CanonicalInvoiceHasher::hash($json, self::SECRET),
        );
        $this->assertSame(64, strlen(CanonicalInvoiceHasher::hash($json, self::SECRET)));
        $this->assertSame(
            CanonicalInvoiceMerkle::build($json, self::SECRET)->root(),
            CanonicalInvoiceHasher::hash($json, self::SECRET),
        );
    }

    public function test_different_json_produces_a_different_hash(): void
    {
        $this->assertNotSame(
            CanonicalInvoiceHasher::hash('{"unit_price":"10.0000"}', self::SECRET),
            CanonicalInvoiceHasher::hash('{"unit_price":"11.0000"}', self::SECRET),
        );
    }

    public function test_different_secret_produces_a_different_hash(): void
    {
        $this->assertNotSame(
            CanonicalInvoiceHasher::hash('{"unit_price":"10.0000"}', self::SECRET),
            CanonicalInvoiceHasher::hash('{"unit_price":"10.0000"}', str_repeat('cd', 32)),
        );
    }
}
