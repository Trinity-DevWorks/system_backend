<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads Safe owners over JSON-RPC. Prefers getOwners() (Safe{Wallet});
 * falls back to owner() (OneOwnerSafe).
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
        $code = $this->rpc('eth_getCode', [$to, 'latest']);
        if (! is_string($code) || $code === '' || $code === '0x' || $code === '0x0') {
            return [];
        }

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

        return [];
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
