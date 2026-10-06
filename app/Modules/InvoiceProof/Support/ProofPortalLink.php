<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

/**
 * HMAC capability for the public buyer portal. Payload is tenant_id|invoice_id|exp.
 */
final class ProofPortalLink
{
    public static function ttlDays(): int
    {
        return max(1, (int) config('blockchain.proof_portal_ttl_days', 7));
    }

    public static function secret(): string
    {
        $secret = trim((string) config('blockchain.proof_portal_secret', ''));
        if ($secret !== '') {
            return $secret;
        }

        return (string) config('app.key');
    }

    public static function sign(string $tenantId, string $invoiceId, int $exp): string
    {
        return hash_hmac('sha256', self::payload($tenantId, $invoiceId, $exp), self::secret());
    }

    public static function payload(string $tenantId, string $invoiceId, int $exp): string
    {
        return $tenantId.'|'.$invoiceId.'|'.$exp;
    }

    /**
     * @return array{url: string, exp: int, sig: string}
     */
    public static function issue(string $tenantId, string $invoiceId, string $pathPrefix = '/proofs/'): array
    {
        $exp = time() + (self::ttlDays() * 86400);
        $sig = self::sign($tenantId, $invoiceId, $exp);
        $prefix = str_ends_with($pathPrefix, '/') ? $pathPrefix : $pathPrefix.'/';

        return [
            'url' => $prefix.$invoiceId.'?exp='.$exp.'&sig='.$sig,
            'exp' => $exp,
            'sig' => $sig,
        ];
    }

    public static function assertValid(string $tenantId, string $invoiceId, mixed $expRaw, mixed $sigRaw): void
    {
        if (! is_numeric($expRaw)) {
            abort(404, 'Resource not found.', ['X-Error-Code' => 'PROOF_LINK_INVALID']);
        }

        $exp = (int) $expRaw;
        $sig = is_string($sigRaw) ? strtolower(trim($sigRaw)) : '';
        $expected = self::sign($tenantId, $invoiceId, $exp);

        if ($exp <= 0 || $sig === '' || strlen($sig) !== strlen($expected) || ! hash_equals($expected, $sig)) {
            abort(404, 'Resource not found.', ['X-Error-Code' => 'PROOF_LINK_INVALID']);
        }

        if ($exp < time()) {
            abort(403, 'This buyer link has expired.', ['X-Error-Code' => 'PROOF_LINK_EXPIRED']);
        }
    }
}
