<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\CanonicalInvoiceSchema;

/**
 * SHA-256 of canonical invoice JSON. Hash the stored string; do not decode and re-encode first.
 */
final class CanonicalInvoiceHasher
{
    public static function sha256(string $canonicalJson): string
    {
        return hash(CanonicalInvoiceSchema::HASH_ALGO, $canonicalJson);
    }
}
