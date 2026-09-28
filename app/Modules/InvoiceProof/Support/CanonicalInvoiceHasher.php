<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

/**
 * Content hash of a canonical invoice: the salted Merkle root of its leaves.
 * The same JSON and disclosure secret always produce the same root.
 */
final class CanonicalInvoiceHasher
{
    public static function hash(string $canonicalJson, string $disclosureSecret): string
    {
        return CanonicalInvoiceMerkle::build($canonicalJson, $disclosureSecret)->root();
    }
}
