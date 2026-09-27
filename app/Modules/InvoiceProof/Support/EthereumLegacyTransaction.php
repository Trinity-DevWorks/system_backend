<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use Elliptic\EC;
use InvalidArgumentException;
use kornrunner\Keccak;
use Throwable;

/**
 * EIP-155 legacy eth_sendRawTransaction. Used when the RPC has no unlocked
 * registrar (Sepolia). Anvil keeps eth_sendTransaction.
 */
final class EthereumLegacyTransaction
{
    /**
     * @param  array{
     *     nonce: int,
     *     gas_price: int,
     *     gas: int,
     *     to: string,
     *     value?: int,
     *     data: string
     * }  $tx
     * @return string 0x-prefixed raw tx
     */
    public static function sign(array $tx, string $privateKey, int $chainId): string
    {
        $key = self::normalizePrivateKey($privateKey);
        if ($chainId <= 0) {
            throw new InvalidArgumentException('Chain id must be positive.');
        }

        $nonce = self::intToBin($tx['nonce']);
        $gasPrice = self::intToBin($tx['gas_price']);
        $gas = self::intToBin($tx['gas']);
        $to = hex2bin(InvoiceProofBytes::strip0x(InvoiceProofBytes::address($tx['to'])));
        if ($to === false) {
            throw new InvalidArgumentException('Invalid to address.');
        }
        $value = self::intToBin($tx['value'] ?? 0);
        $data = hex2bin(InvoiceProofBytes::strip0x($tx['data'] === '' ? '0x' : $tx['data']));
        if ($data === false) {
            $data = '';
        }

        $unsigned = self::rlp([
            $nonce,
            $gasPrice,
            $gas,
            $to,
            $value,
            $data,
            self::intToBin($chainId),
            '',
            '',
        ]);

        [$r, $s, $recid] = self::signKeccak($unsigned, $key);
        $v = $chainId * 2 + 35 + $recid;

        return '0x'.bin2hex(self::rlp([
            $nonce,
            $gasPrice,
            $gas,
            $to,
            $value,
            $data,
            self::intToBin($v),
            hex2bin($r) ?: '',
            hex2bin($s) ?: '',
        ]));
    }

    /**
     * @param  list<string>  $items  binary strings
     */
    public static function rlp(array $items): string
    {
        $out = '';
        foreach ($items as $item) {
            $out .= self::rlpBytes($item);
        }

        return self::rlpLength($out, 0xC0).$out;
    }

    private static function rlpBytes(string $bytes): string
    {
        if (strlen($bytes) === 1 && ord($bytes) < 0x80) {
            return $bytes;
        }

        return self::rlpLength($bytes, 0x80).$bytes;
    }

    private static function rlpLength(string $payload, int $offset): string
    {
        $len = strlen($payload);
        if ($len < 56) {
            return chr($offset + $len);
        }

        $lenBin = self::intToBin($len);
        if ($lenBin === '') {
            $lenBin = "\x00";
        }

        return chr($offset + 55 + strlen($lenBin)).$lenBin;
    }

    private static function intToBin(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('RLP integers must be unsigned.');
        }
        if ($value === 0) {
            return '';
        }

        $hex = dechex($value);
        if (strlen($hex) % 2 === 1) {
            $hex = '0'.$hex;
        }

        $bin = hex2bin($hex);

        return $bin === false ? '' : $bin;
    }

    /**
     * @return array{0: string, 1: string, 2: int} r hex, s hex, recid
     */
    private static function signKeccak(string $payload, string $privateKey): array
    {
        try {
            $hash = Keccak::hash($payload, 256);
            $signature = (new EC('secp256k1'))->keyFromPrivate($privateKey, 'hex')->sign($hash, ['canonical' => true]);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Could not sign the registrar transaction.', 0, $exception);
        }

        $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
        $recid = (int) $signature->recoveryParam;
        if ($recid < 0 || $recid > 1) {
            throw new InvalidArgumentException('Invalid transaction signature recovery id.');
        }

        return [$r, $s, $recid];
    }

    private static function normalizePrivateKey(string $privateKey): string
    {
        $hex = strtolower(trim($privateKey));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Registrar private key must be 32 bytes hex.');
        }

        return $hex;
    }
}
