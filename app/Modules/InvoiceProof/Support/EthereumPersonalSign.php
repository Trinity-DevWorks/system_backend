<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use Elliptic\EC;
use kornrunner\Keccak;
use Throwable;

/**
 * EIP-191 personal_sign: recover the signer address from a UTF-8 message.
 * Uses kornrunner/keccak and simplito/elliptic-php (requires ext-gmp).
 */
final class EthereumPersonalSign
{
    private static ?EC $secp256k1 = null;

    public static function messageHash(string $message): string
    {
        $prefix = "\x19Ethereum Signed Message:\n".strlen($message);

        return Keccak::hash($prefix.$message, 256);
    }

    /**
     * @return string|null 0x-prefixed lowercase address
     */
    public static function recoverAddress(string $message, string $signature): ?string
    {
        $parsed = self::parseSignature($signature);
        if ($parsed === null) {
            return null;
        }

        try {
            $pub = self::curve()->recoverPubKey(
                self::messageHash($message),
                ['r' => $parsed['r'], 's' => $parsed['s']],
                $parsed['recid'],
            );
        } catch (Throwable) {
            return null;
        }

        return self::addressFromPublic($pub);
    }

    /**
     * @return string|null 0x-prefixed lowercase address
     */
    public static function addressFromPrivateKey(string $privateKey): ?string
    {
        $hex = strtolower(trim($privateKey));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            return null;
        }

        try {
            $pub = self::curve()->keyFromPrivate($hex, 'hex')->getPublic();
        } catch (Throwable) {
            return null;
        }

        return self::addressFromPublic($pub);
    }

    /**
     * Test/helper signer. Production HTTP never sees a private key.
     */
    public static function sign(string $message, string $privateKey): ?string
    {
        $hex = strtolower(trim($privateKey));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            return null;
        }

        try {
            $key = self::curve()->keyFromPrivate($hex, 'hex');
            $signature = $key->sign(self::messageHash($message), ['canonical' => true]);
        } catch (Throwable) {
            return null;
        }

        $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
        $recid = (int) $signature->recoveryParam;
        if ($recid < 0 || $recid > 1) {
            return null;
        }

        return '0x'.$r.$s.str_pad(dechex(27 + $recid), 2, '0', STR_PAD_LEFT);
    }

    private static function addressFromPublic(mixed $pub): ?string
    {
        try {
            $encoded = $pub->encode('hex');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($encoded) || strlen($encoded) < 2) {
            return null;
        }

        $xy = hex2bin(substr($encoded, 2));
        if ($xy === false) {
            return null;
        }

        return '0x'.substr(Keccak::hash($xy, 256), 24);
    }

    /**
     * @return array{r: string, s: string, recid: int}|null
     */
    private static function parseSignature(string $signature): ?array
    {
        $hex = strtolower(trim($signature));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if (strlen($hex) !== 130 || ! ctype_xdigit($hex)) {
            return null;
        }

        $r = substr($hex, 0, 64);
        $s = substr($hex, 64, 64);
        $v = hexdec(substr($hex, 128, 2));
        if ($v >= 27) {
            $recid = ($v - 27) % 2;
        } else {
            $recid = $v % 2;
        }

        return ['r' => $r, 's' => $s, 'recid' => $recid];
    }

    private static function curve(): EC
    {
        return self::$secp256k1 ??= new EC('secp256k1');
    }
}
