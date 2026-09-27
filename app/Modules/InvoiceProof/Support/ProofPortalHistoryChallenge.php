<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use Illuminate\Support\Facades\Cache;

/**
 * One-use personal_sign challenge for the buyer invoice list.
 * The nonce is the cache key so two buyers can sign in at the same time.
 */
final class ProofPortalHistoryChallenge
{
    public static function ttlSeconds(): int
    {
        return ProofPortalUnlockChallenge::ttlSeconds();
    }

    /**
     * @return array{nonce: string, message: string}
     */
    public static function issue(string $tenantId, string $companyName): array
    {
        $nonce = bin2hex(random_bytes(16));
        Cache::put(self::key($tenantId, $nonce), '1', self::ttlSeconds());

        return [
            'nonce' => $nonce,
            'message' => InvoiceApprovalStatement::history($companyName, $nonce),
        ];
    }

    public static function consume(string $tenantId, string $message): void
    {
        $nonce = self::nonceFromMessage($message);
        if ($nonce === null || Cache::pull(self::key($tenantId, $nonce)) === null) {
            abort(422, 'This unlock challenge has expired. Refresh and try again.', [
                'X-Error-Code' => 'PROOF_UNLOCK_INVALID',
            ]);
        }
    }

    public static function nonceFromMessage(string $message): ?string
    {
        if (! preg_match('/\nnonce:([0-9a-f]{32})$/', $message, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private static function key(string $tenantId, string $nonce): string
    {
        return 'proof-portal-history:'.$tenantId.':'.$nonce;
    }
}
