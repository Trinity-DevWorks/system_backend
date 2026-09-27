<?php

$network = strtolower(trim((string) env('BLOCKCHAIN_NETWORK', 'anvil')));
if ($network !== 'sepolia') {
    $network = 'anvil';
}

$networks = [
    'anvil' => [
        'rpc_url' => env('BLOCKCHAIN_ANVIL_RPC_URL', env('BLOCKCHAIN_RPC_URL', 'http://127.0.0.1:8545')),
        'chain_id' => (int) env('BLOCKCHAIN_ANVIL_CHAIN_ID', env('BLOCKCHAIN_CHAIN_ID', 31337)),
        'contract_address' => env('BLOCKCHAIN_ANVIL_CONTRACT_ADDRESS', env('BLOCKCHAIN_CONTRACT_ADDRESS')),
        'registrar_address' => env('BLOCKCHAIN_ANVIL_REGISTRAR_ADDRESS', env('BLOCKCHAIN_REGISTRAR_ADDRESS')),
        'registrar_private_key' => env('BLOCKCHAIN_ANVIL_REGISTRAR_PRIVATE_KEY'),
        'safe_tx_service_url' => null,
        'safe_api_key' => null,
    ],
    'sepolia' => [
        'rpc_url' => env('BLOCKCHAIN_SEPOLIA_RPC_URL'),
        'chain_id' => (int) env('BLOCKCHAIN_SEPOLIA_CHAIN_ID', 11155111),
        'contract_address' => env('BLOCKCHAIN_SEPOLIA_CONTRACT_ADDRESS'),
        'registrar_address' => env('BLOCKCHAIN_SEPOLIA_REGISTRAR_ADDRESS'),
        'registrar_private_key' => env('BLOCKCHAIN_SEPOLIA_REGISTRAR_PRIVATE_KEY'),
        'safe_tx_service_url' => env(
            'BLOCKCHAIN_SEPOLIA_SAFE_TX_SERVICE_URL',
            'https://api.safe.global/tx-service/sep/api',
        ),
        'safe_api_key' => env('BLOCKCHAIN_SEPOLIA_SAFE_API_KEY'),
    ],
];

$active = $networks[$network];

return [

    /*
    |--------------------------------------------------------------------------
    | Invoice registry
    |--------------------------------------------------------------------------
    |
    | BLOCKCHAIN_NETWORK=anvil|sepolia selects RPC, chain, contract, and
    | registrar. Legacy BLOCKCHAIN_RPC_URL / CHAIN_ID / CONTRACT_ADDRESS /
    | REGISTRAR_ADDRESS still fill the Anvil slot. Company Safe addresses
    | live on company profile (wallet_address_anvil / wallet_address_sepolia).
    | Party wallets are optional at register time (zeros allowed); setParties
    | fills empty slots later. Sepolia registerInvoice needs
    | BLOCKCHAIN_SEPOLIA_REGISTRAR_PRIVATE_KEY.
    | Anvil uses the unlocked node account when the Anvil key is empty.
    |
    */

    'enabled' => (bool) env('BLOCKCHAIN_ENABLED', false),

    'network' => $network,

    'rpc_url' => (string) ($active['rpc_url'] ?? ''),

    'chain_id' => (int) ($active['chain_id'] ?? 0),

    'contract_address' => $active['contract_address'] ?? null,

    'registrar_address' => $active['registrar_address'] ?? null,

    'registrar_private_key' => $active['registrar_private_key'] ?? null,

    'safe_tx_service_url' => $active['safe_tx_service_url'] ?? null,

    'safe_api_key' => $active['safe_api_key'] ?? null,

    'networks' => $networks,

    'timeout_seconds' => (int) env('BLOCKCHAIN_TIMEOUT_SECONDS', 15),

    'receipt_attempts' => (int) env('BLOCKCHAIN_RECEIPT_ATTEMPTS', 10),

    'proof_portal_secret' => env('PROOF_PORTAL_SECRET'),

    'proof_portal_ttl_days' => (int) env('PROOF_PORTAL_TTL_DAYS', 7),

    'proof_portal_unlock_ttl_seconds' => (int) env('PROOF_PORTAL_UNLOCK_TTL_SECONDS', 300),

];
