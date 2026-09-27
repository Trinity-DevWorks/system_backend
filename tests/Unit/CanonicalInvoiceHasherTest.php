<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\CanonicalInvoiceHasher;
use Tests\TestCase;

/**
 * Checks SHA-256 of canonical JSON is stable and matches PHP hash().
 */
class CanonicalInvoiceHasherTest extends TestCase
{
    public function test_same_canonical_json_always_produces_the_same_hash(): void
    {
        $json = '{"schema_version":1,"invoice_number":"INV-0001"}';

        $this->assertSame(
            CanonicalInvoiceHasher::sha256($json),
            CanonicalInvoiceHasher::sha256($json),
        );
        $this->assertSame(64, strlen(CanonicalInvoiceHasher::sha256($json)));
        $this->assertSame(
            hash('sha256', $json),
            CanonicalInvoiceHasher::sha256($json),
        );
    }

    public function test_different_json_produces_a_different_hash(): void
    {
        $this->assertNotSame(
            CanonicalInvoiceHasher::sha256('{"unit_price":"10.0000"}'),
            CanonicalInvoiceHasher::sha256('{"unit_price":"11.0000"}'),
        );
    }
}
