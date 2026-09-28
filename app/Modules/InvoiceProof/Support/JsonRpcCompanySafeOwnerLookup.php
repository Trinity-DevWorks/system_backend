<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\DTOs\WalletInspectionData;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads Safe owners (and whether an address is a wallet, Safe, or other contract)
 * over JSON-RPC. Prefers getOwners() (Safe{Wallet}); falls back to owner() (OneOwnerSafe).
 */
final class JsonRpcCompanySafeOwnerLookup implements CompanySafeOwnerLookup
{
    public function __construct(
        private readonly string $rpcUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function ownersOf(string $safeAddress): array
    {
        $to = InvoiceProofBytes::address($safeAddress);

        return $this->hasCode($to) ? ($this->readOwners($to) ?? []) : [];
    }

    public function inspect(string $address): WalletInspectionData
    {
        $to = InvoiceProofBytes::address($address);
        if (! $this->hasCode($to)) {
            return WalletInspectionData::wallet($to);
        }

        $owners = $this->readOwners($to);
        if ($owners === null || $owners === []) {
            return WalletInspectionData::contract($to);
        }

        return WalletInspectionData::safe($to, $owners, $this->readThreshold($to, $owners));
    }

    private function hasCode(string $address): bool
    {
        $code = $this->rpc('eth_getCode', [$address, 'latest']);

        return is_string($code) && $code !== '' && $code !== '0x' && $code !== '0x0';
    }

    /**
     * OneOwnerSafe has no getThreshold(); a single owner means 1.
     *
     * @param  list<string>  $owners
     */
    private function readThreshold(string $to, array $owners): ?int
    {
        try {
            $raw = $this->rpc('eth_call', [['to' => $to, 'data' => CompanySafeAbi::GET_THRESHOLD], 'latest']);
            $threshold = is_string($raw) ? CompanySafeAbi::decodeUint($raw) : null;
            if ($threshold !== null && $threshold > 0) {
                return $threshold;
            }
        } catch (Throwable) {
            // Fall through to the single-owner default.
        }

        return count($owners) === 1 ? 1 : null;
    }

    /**
     * @return list<string>|null Null when the contract is not a Safe we can read.
     */
    private function readOwners(string $to): ?array
    {
        try {
            $ownersRaw = $this->rpc('eth_call', [[
                'to' => $to,
                'data' => CompanySafeAbi::GET_OWNERS,
            ], 'latest']);
            if (is_string($ownersRaw) && $ownersRaw !== '' && $ownersRaw !== '0x') {
                $owners = CompanySafeAbi::decodeOwners($ownersRaw);
                if ($owners !== null) {
                    return $owners;
                }
            }
        } catch (Throwable) {
            // OneOwnerSafe has no getOwners(); try owner() next.
        }

        try {
            $ownerRaw = $this->rpc('eth_call', [[
                'to' => $to,
                'data' => CompanySafeAbi::OWNER,
            ], 'latest']);
            if (is_string($ownerRaw) && $ownerRaw !== '' && $ownerRaw !== '0x') {
                $owner = CompanySafeAbi::decodeOwner($ownerRaw);
                if ($owner !== null) {
                    return [$owner];
                }
            }
        } catch (Throwable) {
            // Not a Safe we can read.
        }

        return null;
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

            throw new RuntimeException($message);
        }

        return $payload['result'] ?? null;
    }
}
