<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use Illuminate\Support\Facades\Cache;

/**
 * One-use personal_sign challenge for unlocking the public buyer portal.
 */
final class ProofPortalUnlockChallenge
{
    public static function ttlSeconds(): int
    {
        return max(60, (int) config('blockchain.proof_portal_unlock_ttl_seconds', 300));
    }

    /**
     * @return array{nonce: string, message: string}
     */
    public static function issue(string $tenantId, string $invoiceId, string $companyName, string $invoiceNumber): array
    {
        $nonce = bin2hex(random_bytes(16));
        Cache::put(self::key($tenantId, $invoiceId), $nonce, self::ttlSeconds());

        return [
            'nonce' => $nonce,
            'message' => self::message($companyName, $invoiceNumber, $nonce),
        ];
    }

    public static function current(string $tenantId, string $invoiceId): ?string
    {
        $nonce = Cache::get(self::key($tenantId, $invoiceId));

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    public static function consume(string $tenantId, string $invoiceId, string $nonce): void
    {
        $key = self::key($tenantId, $invoiceId);
        $stored = Cache::get($key);
        if (! is_string($stored) || $stored === '' || ! hash_equals($stored, $nonce)) {
            abort(422, 'This unlock challenge has expired. Refresh and try again.', [
                'X-Error-Code' => 'PROOF_UNLOCK_INVALID',
            ]);
        }

        Cache::forget($key);
    }

    public static function message(string $companyName, string $invoiceNumber, string $nonce): string
    {
        return InvoiceApprovalStatement::view($companyName, $invoiceNumber, $nonce);
    }

    private static function key(string $tenantId, string $invoiceId): string
    {
        return 'proof-portal-unlock:'.$tenantId.':'.$invoiceId;
    }
}
