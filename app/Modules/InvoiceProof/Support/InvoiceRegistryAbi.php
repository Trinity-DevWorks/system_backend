<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\DTOs\InvoiceAttestationRecord;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use InvalidArgumentException;

/**
 * ABI helpers for InvoiceRegistry. Selectors from `cast sig`.
 */
final class InvoiceRegistryAbi
{
    public const REGISTER_INVOICE = '0x8c80e273';

    public const CONTENT_HASH_OF = '0x3dda9d51';

    public const APPROVE_BY_SUPPLIER = '0x1d97ab7e';

    public const APPROVE_BY_BUYER = '0x0a3e2d36';

    public const INVOICES = '0xf8a8a076';

    public const SET_PARTIES = '0xe35129b1';

    public const ATTESTATION_COUNT = '0xd65ddd52';

    public const ATTESTATION_AT = '0x4ed051db';

    public const SET_VERIFIER = '0x2b70a025';

    public const REVOKE_INVOICE = '0x3b1da35c';

    public const DISPUTE_BY_BUYER = '0xaad824ff';

    public const ALREADY_REGISTERED = '3a81d6fc';

    public const PARTY_ALREADY_SET = '8cfb1c1a';

    public const INVOICE_IS_REVOKED = '049eeccb';

    public const INVOICE_IS_DISPUTED = '5b9fb0e0';

    /** keccak256("InvoiceRegistered(bytes32,bytes32,address,address)") */
    public const INVOICE_REGISTERED_TOPIC = '0x20c1b4728816be48a6716ede4f3c00aca2675981eb46872a60c0b957685ad840';

    public const EIP712_NAME = 'InvoiceRegistry';

    public const EIP712_VERSION = '1';

    public static function encodeRegisterInvoice(
        string $proofId,
        string $contentHash,
        string $supplier,
        string $buyer,
    ): string {
        return self::REGISTER_INVOICE
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($proofId))
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::contentHashToBytes32($contentHash))
            .self::padAddress($supplier)
            .self::padAddress($buyer);
    }

    public static function encodeSetParties(string $proofId, string $supplier, string $buyer): string
    {
        return self::SET_PARTIES
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($proofId))
            .self::padAddress($supplier)
            .self::padAddress($buyer);
    }

    public static function encodeRevokeInvoice(string $proofId, ?string $replacementProofId): string
    {
        return self::encodeProofIdCall(self::REVOKE_INVOICE, $proofId)
            .($replacementProofId !== null
                ? InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($replacementProofId))
                : str_repeat('0', 64));
    }

    public static function encodeContentHashOf(string $proofId): string
    {
        return self::encodeProofIdCall(self::CONTENT_HASH_OF, $proofId);
    }

    public static function encodeApproveBySupplier(
        string $proofId,
        string $contentHash,
        string $invoiceNumber,
        string $statement,
    ): string {
        $encodedNumber = self::encodeDynamic(bin2hex($invoiceNumber));
        $encodedStatement = self::encodeDynamic(bin2hex($statement));
        $headSize = 128;
        $statementOffset = $headSize + intdiv(strlen($encodedNumber), 2);

        return self::APPROVE_BY_SUPPLIER
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($proofId))
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::contentHashToBytes32($contentHash))
            .self::padUint($headSize)
            .self::padUint($statementOffset)
            .$encodedNumber
            .$encodedStatement;
    }

    public static function encodeApproveByBuyer(
        string $proofId,
        string $contentHash,
        string $invoiceNumber,
        string $statement,
        string $signature,
    ): string {
        return self::encodeTypedApproval(
            self::APPROVE_BY_BUYER,
            $proofId,
            $contentHash,
            $invoiceNumber,
            $statement,
            $signature,
        );
    }

    public static function encodeDisputeByBuyer(
        string $proofId,
        string $contentHash,
        string $reasonHash,
        string $invoiceNumber,
        string $statement,
        string $signature,
    ): string {
        $encodedNumber = self::encodeDynamic(bin2hex($invoiceNumber));
        $encodedStatement = self::encodeDynamic(bin2hex($statement));
        $encodedSignature = self::encodeDynamic(InvoiceProofBytes::strip0x($signature));
        $headSize = 192;
        $statementOffset = $headSize + intdiv(strlen($encodedNumber), 2);
        $signatureOffset = $statementOffset + intdiv(strlen($encodedStatement), 2);

        return self::DISPUTE_BY_BUYER
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($proofId))
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::contentHashToBytes32($contentHash))
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::contentHashToBytes32($reasonHash))
            .self::padUint($headSize)
            .self::padUint($statementOffset)
            .self::padUint($signatureOffset)
            .$encodedNumber
            .$encodedStatement
            .$encodedSignature;
    }

    /**
     * EIP-712 payload for MetaMask `eth_signTypedData_v4`. Domain keys are
     * snake_case on the wire; type field names match the Solidity struct.
     *
     * @return array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array<string, list<array{name: string, type: string}>>,
     *     message: array{proof_id: string, content_hash: string, invoice_number: string, statement: string}
     * }
     */
    public static function supplierApprovalTypedData(
        int $chainId,
        string $contractAddress,
        string $proofId,
        string $contentHash,
        string $invoiceNumber,
        string $statement,
    ): array {
        return self::partyApprovalTypedData(
            'SupplierApproval',
            $chainId,
            $contractAddress,
            $proofId,
            $contentHash,
            $invoiceNumber,
            $statement,
        );
    }

    /**
     * @return array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array<string, list<array{name: string, type: string}>>,
     *     message: array{proof_id: string, content_hash: string, invoice_number: string, statement: string}
     * }
     */
    public static function buyerApprovalTypedData(
        int $chainId,
        string $contractAddress,
        string $proofId,
        string $contentHash,
        string $invoiceNumber,
        string $statement,
    ): array {
        return self::partyApprovalTypedData(
            'BuyerApproval',
            $chainId,
            $contractAddress,
            $proofId,
            $contentHash,
            $invoiceNumber,
            $statement,
        );
    }

    /**
     * Typed data for `disputeByBuyer`. `reason_hash` is filled by the buyer after they type the reason.
     *
     * @return array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array<string, list<array{name: string, type: string}>>,
     *     message: array{proof_id: string, content_hash: string, invoice_number: string, statement: string}
     * }
     */
    public static function buyerDisputeTypedData(
        int $chainId,
        string $contractAddress,
        string $proofId,
        string $contentHash,
        string $invoiceNumber,
        string $statement,
    ): array {
        $typed = self::partyApprovalTypedData(
            'BuyerDispute',
            $chainId,
            $contractAddress,
            $proofId,
            $contentHash,
            $invoiceNumber,
            $statement,
        );
        array_splice($typed['types']['BuyerDispute'], 2, 0, [['name' => 'reasonHash', 'type' => 'bytes32']]);

        return $typed;
    }

    public static function encodeInvoices(string $proofId): string
    {
        return self::encodeProofIdCall(self::INVOICES, $proofId);
    }

    public static function encodeSetVerifier(string $company, string $verifier, int $role): string
    {
        return self::SET_VERIFIER
            .self::padAddress($company)
            .self::padAddress($verifier)
            .self::padUint($role);
    }

    public static function encodeAttestationCount(string $proofId): string
    {
        return self::encodeProofIdCall(self::ATTESTATION_COUNT, $proofId);
    }

    public static function encodeAttestationAt(string $proofId, int $index): string
    {
        return self::encodeProofIdCall(self::ATTESTATION_AT, $proofId).self::padUint($index);
    }

    public static function decodeUint(string $data): int
    {
        $word = substr(InvoiceProofBytes::strip0x($data), 0, 64);
        if (! preg_match('/^[0-9a-fA-F]{64}$/', $word)) {
            return 0;
        }

        return (int) hexdec($word);
    }

    /**
     * Decodes `(address verifier, uint8 role, bytes32 referenceHash, uint256 attestedAt)`.
     */
    public static function decodeAttestation(string $data): ?InvoiceAttestationRecord
    {
        $hex = InvoiceProofBytes::strip0x($data);
        if (strlen($hex) < 256) {
            return null;
        }

        $role = InvoiceVerifierRole::fromChain((int) hexdec(substr($hex, 64, 64)));
        if ($role === null) {
            return null;
        }

        return new InvoiceAttestationRecord(
            verifier: InvoiceProofBytes::address('0x'.substr($hex, 24, 40)),
            role: $role,
            referenceHash: self::decodeBytes32('0x'.substr($hex, 128, 64)),
            attestedAt: self::decodeTimestamp(substr($hex, 192, 64)),
        );
    }

    public static function addressTopic(string $address): string
    {
        return '0x'.self::padAddress($address);
    }

    /**
     * Decodes one `InvoiceRegistered` log from `eth_getLogs`. Topics: signature, proofId, supplier.
     * Data: contentHash, buyer.
     *
     * @param  array<string, mixed>  $log
     * @return array{proof_id: string, content_hash: string, block_number: int}|null
     */
    public static function decodeInvoiceRegisteredLog(array $log): ?array
    {
        $topics = $log['topics'] ?? null;
        $data = $log['data'] ?? null;
        $block = $log['blockNumber'] ?? null;
        if (! is_array($topics) || count($topics) < 3 || ! is_string($data) || ! is_string($block)) {
            return null;
        }

        if (strtolower((string) $topics[0]) !== self::INVOICE_REGISTERED_TOPIC) {
            return null;
        }

        $proofId = InvoiceProofBytes::strip0x((string) $topics[1]);
        $contentHash = substr(InvoiceProofBytes::strip0x($data), 0, 64);
        if (! preg_match('/^[0-9a-f]{64}$/', $proofId) || ! preg_match('/^[0-9a-f]{64}$/', $contentHash)) {
            return null;
        }

        return [
            'proof_id' => '0x'.$proofId,
            'content_hash' => '0x'.$contentHash,
            'block_number' => (int) hexdec(InvoiceProofBytes::strip0x($block)),
        ];
    }

    public static function decodeBytes32(string $data): ?string
    {
        $hex = InvoiceProofBytes::strip0x($data);
        if (strlen($hex) < 64) {
            return null;
        }

        $word = substr($hex, 0, 64);
        if ($word === str_repeat('0', 64)) {
            return null;
        }

        return $word;
    }

    public static function isAlreadyRegisteredRevert(string $message): bool
    {
        return str_contains(strtolower($message), self::ALREADY_REGISTERED);
    }

    public static function isPartyAlreadySetRevert(string $message): bool
    {
        return str_contains(strtolower($message), self::PARTY_ALREADY_SET);
    }

    public static function isInvoiceRevokedRevert(string $message): bool
    {
        return str_contains(strtolower($message), self::INVOICE_IS_REVOKED);
    }

    public static function isInvoiceDisputedRevert(string $message): bool
    {
        return str_contains(strtolower($message), self::INVOICE_IS_DISPUTED);
    }

    public static function decodeInvoice(string $data): ?InvoiceOnChainRecord
    {
        $hex = InvoiceProofBytes::strip0x($data);
        if (strlen($hex) < 320) {
            return null;
        }

        $contentHash = self::decodeBytes32('0x'.substr($hex, 0, 64));
        if ($contentHash === null) {
            return null;
        }

        $supplierApproved = self::wordIsTrue(substr($hex, 192, 64));
        $buyerApproved = self::wordIsTrue(substr($hex, 256, 64));
        $revokedAt = self::decodeTimestamp(substr($hex, 512, 64));
        $replacedBy = self::decodeBytes32('0x'.substr($hex, 576, 64));
        $disputedAt = strlen($hex) >= 704 ? self::decodeTimestamp(substr($hex, 640, 64)) : null;
        $disputeReasonHash = strlen($hex) >= 768 ? self::decodeBytes32('0x'.substr($hex, 704, 64)) : null;
        $status = match (true) {
            $revokedAt !== null => InvoiceOnChainStatus::Revoked,
            $disputedAt !== null => InvoiceOnChainStatus::Disputed,
            $buyerApproved => InvoiceOnChainStatus::FullyApproved,
            $supplierApproved => InvoiceOnChainStatus::SupplierApproved,
            default => InvoiceOnChainStatus::Registered,
        };

        return new InvoiceOnChainRecord(
            contentHash: $contentHash,
            supplierAddress: InvoiceProofBytes::address('0x'.substr($hex, 64 + 24, 40)),
            buyerAddress: InvoiceProofBytes::address('0x'.substr($hex, 128 + 24, 40)),
            supplierApproved: $supplierApproved,
            buyerApproved: $buyerApproved,
            status: $status,
            registeredAt: self::decodeTimestamp(substr($hex, 320, 64)),
            supplierApprovedAt: self::decodeTimestamp(substr($hex, 384, 64)),
            buyerApprovedAt: self::decodeTimestamp(substr($hex, 448, 64)),
            revokedAt: $revokedAt,
            replacedBy: $replacedBy !== null ? InvoiceProofBytes::bytes32ToProofId($replacedBy) : null,
            disputedAt: $disputedAt,
            disputeReasonHash: $disputeReasonHash !== null ? '0x'.$disputeReasonHash : null,
        );
    }

    private static function decodeTimestamp(string $word): ?int
    {
        if (! preg_match('/^[0-9a-fA-F]{64}$/', $word)) {
            return null;
        }

        $timestamp = hexdec($word);

        return $timestamp > 0 ? $timestamp : null;
    }

    /**
     * @return array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array<string, list<array{name: string, type: string}>>,
     *     message: array{proof_id: string, content_hash: string, invoice_number: string, statement: string}
     * }
     */
    private static function partyApprovalTypedData(
        string $primaryType,
        int $chainId,
        string $contractAddress,
        string $proofId,
        string $contentHash,
        string $invoiceNumber,
        string $statement,
    ): array {
        return [
            'domain' => [
                'name' => self::EIP712_NAME,
                'version' => self::EIP712_VERSION,
                'chain_id' => $chainId,
                'verifying_contract' => InvoiceProofBytes::address($contractAddress),
            ],
            'primary_type' => $primaryType,
            'types' => [
                $primaryType => [
                    ['name' => 'proofId', 'type' => 'bytes32'],
                    ['name' => 'contentHash', 'type' => 'bytes32'],
                    ['name' => 'invoiceNumber', 'type' => 'string'],
                    ['name' => 'statement', 'type' => 'string'],
                ],
            ],
            'message' => [
                'proof_id' => InvoiceProofBytes::proofIdToBytes32($proofId),
                'content_hash' => InvoiceProofBytes::contentHashToBytes32($contentHash),
                'invoice_number' => $invoiceNumber,
                'statement' => $statement,
            ],
        ];
    }

    private static function encodeTypedApproval(
        string $selector,
        string $proofId,
        string $contentHash,
        string $invoiceNumber,
        string $statement,
        string $signature,
    ): string {
        $encodedNumber = self::encodeDynamic(bin2hex($invoiceNumber));
        $encodedStatement = self::encodeDynamic(bin2hex($statement));
        $encodedSignature = self::encodeDynamic(InvoiceProofBytes::strip0x($signature));
        $headSize = 160;
        $statementOffset = $headSize + intdiv(strlen($encodedNumber), 2);
        $signatureOffset = $statementOffset + intdiv(strlen($encodedStatement), 2);

        return $selector
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($proofId))
            .InvoiceProofBytes::strip0x(InvoiceProofBytes::contentHashToBytes32($contentHash))
            .self::padUint($headSize)
            .self::padUint($statementOffset)
            .self::padUint($signatureOffset)
            .$encodedNumber
            .$encodedStatement
            .$encodedSignature;
    }

    private static function encodeProofIdCall(string $selector, string $proofId): string
    {
        return $selector.InvoiceProofBytes::strip0x(InvoiceProofBytes::proofIdToBytes32($proofId));
    }

    private static function encodeDynamic(string $hex): string
    {
        $hex = strtolower($hex);
        if (strlen($hex) % 2 !== 0 || ($hex !== '' && ! ctype_xdigit($hex))) {
            throw new InvalidArgumentException('Dynamic bytes must be even-length hex.');
        }

        $padded = $hex === '' ? '' : $hex.str_repeat('0', (64 - (strlen($hex) % 64)) % 64);

        return self::padUint(intdiv(strlen($hex), 2)).$padded;
    }

    private static function padUint(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    private static function padAddress(string $address): string
    {
        return str_repeat('0', 24).InvoiceProofBytes::strip0x(InvoiceProofBytes::address($address));
    }

    private static function wordIsTrue(string $word): bool
    {
        return hexdec($word) === 1;
    }
}
