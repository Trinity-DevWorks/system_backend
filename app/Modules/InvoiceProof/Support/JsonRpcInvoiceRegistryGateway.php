<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceChainReceiptData;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * JSON-RPC client for InvoiceRegistry. Anvil registration uses
 * eth_sendTransaction from the unlocked registrar. Sepolia uses a signed
 * eth_sendRawTransaction. Party approvals are signed in the wallet, not Laravel.
 */
final class JsonRpcInvoiceRegistryGateway implements InvoiceRegistryGateway
{
    private const MAX_ATTESTATIONS = 50;

    public function __construct(
        private readonly string $rpcUrl,
        private readonly string $contractAddress,
        private readonly string $registrarAddress,
        private readonly int $timeoutSeconds,
        private readonly int $receiptAttempts,
        private readonly int $chainId = 0,
        private readonly ?string $registrarPrivateKey = null,
    ) {}

    public function contentHashOf(string $proofId): ?string
    {
        return $this->invoiceOf($proofId)?->contentHash;
    }

    public function invoiceOf(string $proofId): ?InvoiceOnChainRecord
    {
        $result = $this->rpc('eth_call', [[
            'to' => InvoiceProofBytes::address($this->contractAddress),
            'data' => InvoiceRegistryAbi::encodeInvoices($proofId),
        ], 'latest']);

        if (! is_string($result) || $result === '' || $result === '0x') {
            return null;
        }

        return InvoiceRegistryAbi::decodeInvoice($result);
    }

    public function registerInvoice(
        string $proofId,
        string $contentHash,
        string $supplierAddress,
        string $buyerAddress,
    ): InvoiceChainReceiptData {
        $existing = $this->invoiceOf($proofId);
        if ($existing !== null) {
            if (! hash_equals($existing->contentHash, InvoiceProofBytes::normalizedContentHash($contentHash))) {
                throw new RuntimeException('Proof is already registered on chain with a different hash.');
            }

            return new InvoiceChainReceiptData(
                txHash: 'already-registered',
                blockNumber: null,
                contractAddress: InvoiceProofBytes::address($this->contractAddress),
            );
        }

        $data = InvoiceRegistryAbi::encodeRegisterInvoice(
            $proofId,
            $contentHash,
            $supplierAddress,
            $buyerAddress,
        );

        try {
            return $this->sendFrom($this->registrarAddress, $data);
        } catch (RuntimeException $exception) {
            if (InvoiceRegistryAbi::isAlreadyRegisteredRevert($exception->getMessage())) {
                $onChain = $this->invoiceOf($proofId);
                if ($onChain !== null && hash_equals($onChain->contentHash, InvoiceProofBytes::normalizedContentHash($contentHash))) {
                    return new InvoiceChainReceiptData(
                        txHash: 'already-registered',
                        blockNumber: null,
                        contractAddress: InvoiceProofBytes::address($this->contractAddress),
                    );
                }
            }

            throw $exception;
        }
    }

    public function setParties(
        string $proofId,
        string $supplierAddress,
        string $buyerAddress,
    ): InvoiceChainReceiptData {
        $data = InvoiceRegistryAbi::encodeSetParties($proofId, $supplierAddress, $buyerAddress);

        try {
            return $this->sendFrom($this->registrarAddress, $data);
        } catch (RuntimeException $exception) {
            if (InvoiceRegistryAbi::isPartyAlreadySetRevert($exception->getMessage())) {
                $onChain = $this->invoiceOf($proofId);
                if ($onChain !== null && $this->partiesAlreadyMatch($onChain, $supplierAddress, $buyerAddress)) {
                    return new InvoiceChainReceiptData(
                        txHash: 'parties-already-set',
                        blockNumber: null,
                        contractAddress: InvoiceProofBytes::address($this->contractAddress),
                    );
                }
            }

            throw $exception;
        }
    }

    public function approveBySupplier(string $proofId, string $supplierAddress): InvoiceChainReceiptData
    {
        throw new RuntimeException('Company approval must be signed in the wallet.');
    }

    public function setVerifier(string $companyAddress, string $verifierAddress, int $role): InvoiceChainReceiptData
    {
        return $this->sendFrom(
            $this->registrarAddress,
            InvoiceRegistryAbi::encodeSetVerifier($companyAddress, $verifierAddress, $role),
        );
    }

    public function attestationsOf(string $proofId): array
    {
        $count = $this->call(InvoiceRegistryAbi::encodeAttestationCount($proofId));
        $total = min($count === null ? 0 : InvoiceRegistryAbi::decodeUint($count), self::MAX_ATTESTATIONS);

        $attestations = [];
        for ($index = 0; $index < $total; $index++) {
            $result = $this->call(InvoiceRegistryAbi::encodeAttestationAt($proofId, $index));
            $record = $result === null ? null : InvoiceRegistryAbi::decodeAttestation($result);
            if ($record !== null) {
                $attestations[] = $record;
            }
        }

        return $attestations;
    }

    public function latestBlockNumber(): int
    {
        $result = $this->rpc('eth_blockNumber', []);

        return is_string($result) ? self::hexToInt($result) : 0;
    }

    public function registeredBySupplier(string $supplierAddress, int $fromBlock, int $toBlock): array
    {
        if ($toBlock < $fromBlock) {
            return [];
        }

        $logs = $this->rpc('eth_getLogs', [[
            'address' => InvoiceProofBytes::address($this->contractAddress),
            'fromBlock' => '0x'.dechex($fromBlock),
            'toBlock' => '0x'.dechex($toBlock),
            'topics' => [
                InvoiceRegistryAbi::INVOICE_REGISTERED_TOPIC,
                null,
                InvoiceRegistryAbi::addressTopic($supplierAddress),
            ],
        ]]);

        if (! is_array($logs)) {
            throw new RuntimeException('Blockchain RPC returned invalid logs.');
        }

        $registered = [];
        foreach ($logs as $log) {
            $decoded = is_array($log) ? InvoiceRegistryAbi::decodeInvoiceRegisteredLog($log) : null;
            if ($decoded !== null) {
                $registered[] = $decoded;
            }
        }

        return $registered;
    }

    private function call(string $data): ?string
    {
        $result = $this->rpc('eth_call', [[
            'to' => InvoiceProofBytes::address($this->contractAddress),
            'data' => $data,
        ], 'latest']);

        return is_string($result) && $result !== '' && $result !== '0x' ? $result : null;
    }

    private function partiesAlreadyMatch(
        InvoiceOnChainRecord $onChain,
        string $supplierAddress,
        string $buyerAddress,
    ): bool {
        $wantSupplier = WalletAddress::normalize($supplierAddress);
        $wantBuyer = WalletAddress::normalize($buyerAddress);
        $haveSupplier = WalletAddress::normalize($onChain->supplierAddress);
        $haveBuyer = WalletAddress::normalize($onChain->buyerAddress);

        if ($wantSupplier !== null && $haveSupplier !== $wantSupplier) {
            return false;
        }

        if ($wantBuyer !== null && $haveBuyer !== $wantBuyer) {
            return false;
        }

        return true;
    }

    private function sendFrom(string $from, string $data): InvoiceChainReceiptData
    {
        $tx = [
            'from' => InvoiceProofBytes::address($from),
            'to' => InvoiceProofBytes::address($this->contractAddress),
            'data' => $data,
        ];

        $gas = $this->rpc('eth_estimateGas', [$tx]);
        if (is_string($gas)) {
            $tx['gas'] = $gas;
        }

        $txHash = $this->registrarPrivateKey !== null && $this->chainId > 0
            ? $this->sendRaw($tx)
            : $this->rpc('eth_sendTransaction', [$tx]);
        if (! is_string($txHash) || $txHash === '') {
            throw new RuntimeException('Chain did not return a transaction hash.');
        }

        $receipt = $this->waitForReceipt($txHash);
        if ($receipt === null) {
            throw new RuntimeException('Timed out waiting for transaction receipt.');
        }
        $status = is_array($receipt) ? ($receipt['status'] ?? null) : null;
        if ($status === '0x0') {
            throw new RuntimeException('Invoice registry transaction reverted.');
        }

        $blockHex = is_array($receipt) && isset($receipt['blockNumber']) && is_string($receipt['blockNumber'])
            ? $receipt['blockNumber']
            : null;

        return new InvoiceChainReceiptData(
            txHash: $txHash,
            blockNumber: $blockHex !== null ? hexdec($blockHex) : null,
            contractAddress: InvoiceProofBytes::address($this->contractAddress),
        );
    }

    /**
     * @param  array{from: string, to: string, data: string, gas?: string}  $tx
     */
    private function sendRaw(array $tx): mixed
    {
        $from = InvoiceProofBytes::address($tx['from']);
        $keyAddress = EthereumPersonalSign::addressFromPrivateKey((string) $this->registrarPrivateKey);
        if ($keyAddress === null || ! hash_equals($from, $keyAddress)) {
            throw new RuntimeException('Registrar private key does not match BLOCKCHAIN registrar address.');
        }

        $nonceHex = $this->rpc('eth_getTransactionCount', [$from, 'pending']);
        $gasPriceHex = $this->rpc('eth_gasPrice', []);
        if (! is_string($nonceHex) || ! is_string($gasPriceHex)) {
            throw new RuntimeException('Could not read nonce or gas price from the RPC.');
        }

        $gasHex = $tx['gas'] ?? '0x493e0';
        $raw = EthereumLegacyTransaction::sign(
            [
                'nonce' => self::hexToInt($nonceHex),
                'gas_price' => self::hexToInt($gasPriceHex),
                'gas' => self::hexToInt($gasHex),
                'to' => $tx['to'],
                'data' => $tx['data'],
            ],
            (string) $this->registrarPrivateKey,
            $this->chainId,
        );

        return $this->rpc('eth_sendRawTransaction', [$raw]);
    }

    private static function hexToInt(string $hex): int
    {
        $value = strtolower(trim($hex));
        if (str_starts_with($value, '0x')) {
            $value = substr($value, 2);
        }
        if ($value === '' || ! ctype_xdigit($value)) {
            return 0;
        }

        return (int) hexdec($value);
    }

    /**
     * @param  list<mixed>  $params
     */
    private function rpc(string $method, array $params): mixed
    {
        try {
            $payload = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => $method,
                    'params' => $params,
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException('Blockchain RPC request failed: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Blockchain RPC returned an invalid payload.');
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $message = isset($payload['error']['message']) && is_string($payload['error']['message'])
                ? $payload['error']['message']
                : 'Blockchain RPC error.';
            $data = $payload['error']['data'] ?? null;
            if (is_string($data) && $data !== '') {
                $message .= ' '.$data;
            }

            throw new RuntimeException($message);
        }

        return $payload['result'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function waitForReceipt(string $txHash): ?array
    {
        for ($attempt = 0; $attempt < $this->receiptAttempts; $attempt++) {
            $result = $this->rpc('eth_getTransactionReceipt', [$txHash]);
            if (is_array($result)) {
                return $result;
            }

            usleep(200_000);
        }

        return null;
    }
}
