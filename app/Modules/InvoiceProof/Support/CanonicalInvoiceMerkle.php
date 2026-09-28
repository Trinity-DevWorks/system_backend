<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use InvalidArgumentException;
use JsonException;

/**
 * Salted Merkle tree over the leaves of a canonical invoice document.
 *
 * Every scalar is one leaf, named by its dot path in document order
 * (`buyer.tax_number`, `lines.0.unit_price`). A null object or an empty list is
 * a single leaf. An odd node at the end of a level is promoted, never duplicated.
 *
 *   salt = HMAC-SHA256(secret, path)
 *   leaf = SHA-256(0x00 || salt || path || 0x00 || value)
 *   node = SHA-256(0x01 || left || right)
 *
 * value is `n` (null), `t` / `f` (bool), `i<decimal>` (int), `s<utf-8>` (string),
 * or `a` (empty list). The salt keeps undisclosed low-entropy values (tax rates,
 * nulls) from being guessed out of the sibling hashes in a disclosure proof.
 */
final class CanonicalInvoiceMerkle
{
    private const LEAF_PREFIX = "\x00";

    private const NODE_PREFIX = "\x01";

    private const PATH_TERMINATOR = "\x00";

    /**
     * @param  list<array{path: string, value: string|int|bool|array{}|null}>  $leaves
     * @param  list<list<string>>  $levels  binary hashes; `$levels[0]` are the leaves
     */
    private function __construct(
        private readonly array $leaves,
        private readonly array $levels,
        private readonly string $secret,
    ) {}

    public static function build(string $canonicalJson, string $secretHex): self
    {
        $secret = self::secretBytes($secretHex);
        $leaves = self::leavesOf($canonicalJson);

        $level = [];
        foreach ($leaves as $leaf) {
            $level[] = self::leafHash($leaf['path'], $leaf['value'], self::saltBytes($secret, $leaf['path']));
        }

        $levels = [$level];
        while (count($level) > 1) {
            $next = [];
            $count = count($level);
            for ($i = 0; $i < $count; $i += 2) {
                $next[] = $i + 1 < $count ? self::nodeHash($level[$i], $level[$i + 1]) : $level[$i];
            }
            $levels[] = $next;
            $level = $next;
        }

        return new self($leaves, $levels, $secret);
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function root(): string
    {
        return bin2hex($this->levels[count($this->levels) - 1][0]);
    }

    public function leafCount(): int
    {
        return count($this->leaves);
    }

    /**
     * @return list<array{path: string, value: string|int|bool|array{}|null}>
     */
    public function leaves(): array
    {
        return $this->leaves;
    }

    public function indexOf(string $path): ?int
    {
        foreach ($this->leaves as $index => $leaf) {
            if ($leaf['path'] === $path) {
                return $index;
            }
        }

        return null;
    }

    public function saltAt(int $index): string
    {
        return bin2hex(self::saltBytes($this->secret, $this->leafAt($index)['path']));
    }

    /**
     * Sibling hashes from the leaf up to the root. `position` is the side the
     * sibling sits on; promoted levels contribute no step.
     *
     * @return list<array{position: 'left'|'right', hash: string}>
     */
    public function proof(int $index): array
    {
        $this->leafAt($index);

        $steps = [];
        $top = count($this->levels) - 1;
        for ($depth = 0; $depth < $top; $depth++) {
            $level = $this->levels[$depth];
            if ($index % 2 === 1) {
                $steps[] = ['position' => 'left', 'hash' => bin2hex($level[$index - 1])];
            } elseif ($index + 1 < count($level)) {
                $steps[] = ['position' => 'right', 'hash' => bin2hex($level[$index + 1])];
            }
            $index = intdiv($index, 2);
        }

        return $steps;
    }

    /**
     * @param  list<array{position: string, hash: string}>  $proof
     */
    public static function verify(string $path, mixed $value, string $saltHex, array $proof, string $rootHex): bool
    {
        try {
            $hash = self::leafHash($path, $value, self::hexBytes($saltHex));
            foreach ($proof as $step) {
                $sibling = self::hexBytes($step['hash']);
                $hash = match ($step['position']) {
                    'left' => self::nodeHash($sibling, $hash),
                    'right' => self::nodeHash($hash, $sibling),
                    default => throw new InvalidArgumentException('Proof step position must be left or right.'),
                };
            }

            return hash_equals(InvoiceProofBytes::normalizedContentHash($rootHex), bin2hex($hash));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return array{path: string, value: string|int|bool|array{}|null}
     */
    private function leafAt(int $index): array
    {
        if (! isset($this->leaves[$index])) {
            throw new InvalidArgumentException('Merkle leaf index is out of range.');
        }

        return $this->leaves[$index];
    }

    /**
     * @return list<array{path: string, value: string|int|bool|array{}|null}>
     */
    private static function leavesOf(string $canonicalJson): array
    {
        try {
            $document = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Canonical invoice JSON is invalid.', 0, $exception);
        }

        if (! is_array($document) || $document === [] || array_is_list($document)) {
            throw new InvalidArgumentException('Canonical invoice JSON must be an object.');
        }

        $leaves = [];
        self::flatten($document, '', $leaves);

        return $leaves;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<array{path: string, value: string|int|bool|array{}|null}>  $leaves
     */
    private static function flatten(array $node, string $prefix, array &$leaves): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value) && $value !== []) {
                self::flatten($value, $path, $leaves);

                continue;
            }

            self::encodeValue($value);
            $leaves[] = ['path' => $path, 'value' => $value];
        }
    }

    private static function leafHash(string $path, mixed $value, string $salt): string
    {
        if (strlen($salt) !== 32) {
            throw new InvalidArgumentException('Merkle leaf salt must be 32 bytes.');
        }

        if ($path === '' || str_contains($path, self::PATH_TERMINATOR)) {
            throw new InvalidArgumentException('Merkle leaf path is invalid.');
        }

        return hash(
            CanonicalInvoiceSchema::HASH_ALGO,
            self::LEAF_PREFIX.$salt.$path.self::PATH_TERMINATOR.self::encodeValue($value),
            true,
        );
    }

    private static function nodeHash(string $left, string $right): string
    {
        return hash(CanonicalInvoiceSchema::HASH_ALGO, self::NODE_PREFIX.$left.$right, true);
    }

    private static function encodeValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'n',
            $value === true => 't',
            $value === false => 'f',
            is_int($value) => 'i'.$value,
            is_string($value) => 's'.$value,
            $value === [] => 'a',
            default => throw new InvalidArgumentException('Canonical invoice leaves must be null, bool, int, string, or an empty list.'),
        };
    }

    private static function saltBytes(string $secret, string $path): string
    {
        return hash_hmac(CanonicalInvoiceSchema::HASH_ALGO, $path, $secret, true);
    }

    private static function secretBytes(string $secretHex): string
    {
        $secret = self::hexBytes($secretHex);
        if (strlen($secret) !== 32) {
            throw new InvalidArgumentException('Merkle secret must be 32 bytes.');
        }

        return $secret;
    }

    private static function hexBytes(string $hex): string
    {
        $hex = InvoiceProofBytes::strip0x($hex);
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Expected 32 bytes hex.');
        }

        return (string) hex2bin($hex);
    }
}
