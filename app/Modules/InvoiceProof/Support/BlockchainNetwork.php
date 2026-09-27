<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

/**
 * Active InvoiceRegistry network (Anvil vs Sepolia).
 */
final class BlockchainNetwork
{
    public const ANVIL = 'anvil';

    public const SEPOLIA = 'sepolia';

    public static function key(): string
    {
        $raw = strtolower(trim((string) config('blockchain.network', self::ANVIL)));

        return $raw === self::SEPOLIA ? self::SEPOLIA : self::ANVIL;
    }

    public static function isSepolia(): bool
    {
        return self::key() === self::SEPOLIA;
    }

    public static function walletColumn(): string
    {
        return self::isSepolia() ? 'wallet_address_sepolia' : 'wallet_address_anvil';
    }

    public static function safeTxServiceUrl(): ?string
    {
        $url = trim((string) config('blockchain.safe_tx_service_url', ''));

        return $url === '' ? null : $url;
    }

    public static function safeApiKey(): ?string
    {
        $key = trim((string) config('blockchain.safe_api_key', ''));

        return $key === '' ? null : $key;
    }

    public static function registrarPrivateKey(): ?string
    {
        $key = trim((string) config('blockchain.registrar_private_key', ''));
        if ($key === '') {
            return null;
        }
        if (str_starts_with(strtolower($key), '0x')) {
            $key = substr($key, 2);
        }
        if (strlen($key) !== 64 || ! ctype_xdigit($key)) {
            return null;
        }

        return strtolower($key);
    }
}
