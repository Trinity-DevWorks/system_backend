<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers a wallet that already signed the buyer or supplier portal.
 * The browser keeps the token for the tab session. A later invoice for the
 * same wallet can be opened without another personal_sign.
 */
final class ProofPortalSession
{
    public const ROLE_BUYER = 'buyer';

    public const ROLE_VENDOR = 'vendor';

    /**
     * @return array{tenant_id: string, signer: string, role: string}|null
     */
    public static function find(string $token): ?array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $stored = Cache::get(self::key($token));
        if (! is_array($stored)) {
            return null;
        }

        $tenantId = $stored['tenant_id'] ?? null;
        $signer = $stored['signer'] ?? null;
        $role = $stored['role'] ?? null;
        if (! is_string($tenantId) || $tenantId === '' || ! is_string($signer) || ! is_string($role)) {
            return null;
        }
        if ($role !== self::ROLE_BUYER && $role !== self::ROLE_VENDOR) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'signer' => $signer,
            'role' => $role,
        ];
    }

    public static function issue(string $tenantId, string $signer, string $role): string
    {
        $token = bin2hex(random_bytes(32));
        Cache::put(self::key($token), [
            'tenant_id' => $tenantId,
            'signer' => WalletAddress::normalize($signer) ?? strtolower($signer),
            'role' => $role,
        ], self::ttlSeconds());

        return $token;
    }

    public static function ttlSeconds(): int
    {
        return 12 * 60 * 60;
    }

    private static function key(string $token): string
    {
        return 'proof-portal-session:'.$token;
    }
}
