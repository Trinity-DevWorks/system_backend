# API Error Codes

This project uses a stable JSON API envelope for errors:

```json
{
    "success": false,
    "status": false,
    "message": "Human-readable message",
    "code": "STABLE_ERROR_CODE",
    "errors": {},
    "error_type": "optional",
    "details": {}
}
```

## Contract

- `code` is for frontend logic and localization (must be stable).
- `message` is for debugging/logging and can change.
- `errors` is mainly for validation field details.
- Keep code names uppercase snake case (e.g. `VALIDATION_ERROR`).
- If a specific code is not provided, backend falls back by HTTP status (`UNAUTHORIZED`, `FORBIDDEN`, `NOT_FOUND`, `CONFLICT`, `VALIDATION_ERROR`, otherwise `REQUEST_FAILED`).

## Current Standard Codes

Core/global:

- `VALIDATION_ERROR` (HTTP 422)
- `UNAUTHORIZED` (HTTP 401)
- `FORBIDDEN` (HTTP 403)
- `NOT_FOUND` (HTTP 404)
- `CONFLICT` (HTTP 409)
- `DELETE_REFERENCED` (HTTP 409) — PostgreSQL FK on delete; fallback when no domain rule ran first
- `FOREIGN_KEY_INVALID_REFERENCE` (HTTP 409) — insert/update references a missing parent row
- `FOREIGN_KEY_VIOLATION` (HTTP 409) — other FK integrity conflicts (mapped from SQLSTATE `23503`)
- `REQUEST_FAILED` (fallback for unclassified errors)

Auth:

- `INVALID_CREDENTIALS` (HTTP 422)
- `ACCOUNT_INACTIVE` (HTTP 403) — login rejected, or authenticated request by a deactivated user (`ensure.active`)
- `PASSWORD_RESET_FAILED` (HTTP 422)
- `PASSWORD_UNCHANGED` (HTTP 422) — new password matches the current password

Central tenant:

- `TENANT_NOT_FOUND` (HTTP 404)
- `TENANT_OWNER_CREATE_FAILED` (HTTP 500)
- `TENANT_SUSPENDED` (HTTP 403) — every tenant-host route (login included) while central has suspended the tenant (`ensure.tenant.active`)

Central admin (platform RBAC):

- `CENTRAL_SUPER_ADMIN_EDIT_FORBIDDEN` (HTTP 422) — the Super Admin system role and its permission matrix cannot be edited
- `CENTRAL_LAST_SUPER_ADMIN_PROTECTED` (HTTP 409) — demoting, deactivating, or deleting the last active Super Admin
- Central role and user rules reuse `ROLE_SYSTEM_DELETE_FORBIDDEN`, `ROLE_DELETE_HAS_ASSIGNED_USERS`, `USER_SELF_DELETE_FORBIDDEN`, `USER_SELF_DEACTIVATE_FORBIDDEN`, `PASSWORD_UNCHANGED`, and `CURRENT_PASSWORD_INVALID`

RBAC:

- `ROLE_SYSTEM_RENAME_FORBIDDEN` (HTTP 422)
- `ROLE_SYSTEM_DELETE_FORBIDDEN` (HTTP 422)
- `ROLE_DELETE_HAS_ASSIGNED_USERS` (HTTP 409)
- `USER_SELF_DELETE_FORBIDDEN` (HTTP 422)
- `USER_SELF_DEACTIVATE_FORBIDDEN` (HTTP 422)
- `USER_LAST_OWNER_PROTECTED` (HTTP 409)
- `USER_BRANCH_REQUIRED` (HTTP 422)
- `USER_BRANCH_NOT_ASSIGNED` (HTTP 422)
- `USER_PREFERRED_BRANCH_FORBIDDEN` (HTTP 422)
- `USER_ATTACHMENT_FORBIDDEN` (HTTP 403)
- `USER_ATTACHMENT_SCOPE_MISMATCH` (HTTP 404)

VAT / lookup groups:

- `VAT_GROUP_DEFAULT_DELETE_FORBIDDEN` (HTTP 422)
- `VAT_GROUP_DELETE_REFERENCED_BY_ITEMS` (HTTP 409)
- `VAT_GROUP_DELETE_REFERENCED_BY_CUSTOMERS` (HTTP 409)
- `VAT_GROUP_DELETE_REFERENCED_BY_SUPPLIERS` (HTTP 409)
- `CUSTOMER_GROUP_DELETE_HAS_MEMBERS` (HTTP 409)
- `CUSTOMER_SYSTEM_DELETE_FORBIDDEN` (HTTP 422)
- `CUSTOMER_SYSTEM_STATUS_FORBIDDEN` (HTTP 422)
- `CUSTOMER_WALLET_IS_COMPANY_SAFE_OWNER` (HTTP 422) — customer `wallet_address` is the company Safe or one of its owners, or is a Safe owned by one of them
- `COMPANY_SAFE_WALLET_USED_BY_CUSTOMER` (HTTP 422) — company Safe (or an owner of that Safe) matches an existing customer wallet
- `WALLET_ADDRESS_IS_CONTRACT` (HTTP 422) — `wallet_type` is `wallet` (personal wallet) but the address holds contract code on the active network (a Safe or another contract); applies to customers, the company profile, and invoice verifiers
- `WALLET_SAFE_NOT_FOUND` (HTTP 422) — `wallet_type` is `safe` but no Safe with readable owners exists at the address on the active network (not deployed, or another contract)
- `SUPPLIER_GROUP_DELETE_HAS_MEMBERS` (HTTP 409)

Currency:

- `CURRENCY_PRIMARY_DELETE_FORBIDDEN` (HTTP 422)

Branches:

- `BRANCH_DEFAULT_DELETE_FORBIDDEN` (HTTP 422)
- `BRANCH_DEFAULT_UNSET_FORBIDDEN` (HTTP 422)
- `BRANCH_DEFAULT_DEACTIVATE_OWNER_ONLY` (HTTP 403)
- `BRANCH_DELETE_HAS_WAREHOUSES` (HTTP 409)

Inventory:

- `UNIT_GROUP_DELETE_HAS_UNITS` (HTTP 409)
- `UOM_DELETE_REFERENCED_BY_ITEMS` (HTTP 409)
- `UOM_DELETE_REFERENCED_BY_ITEM_CONVERSIONS` (HTTP 409)
- `ITEM_BASE_UOM_REQUIRED` (HTTP 422)
- `ITEM_UOM_ALREADY_ATTACHED` (HTTP 422)
- `ITEM_UOM_ALREADY_EXISTS` (HTTP 422)
- `ITEM_BASE_UOM_INVALID_CONVERSION` (HTTP 422)
- `ITEM_BASE_UOM_DETACH_FORBIDDEN` (HTTP 422)
- `ITEM_BASE_UOM_DELETE_FORBIDDEN` (HTTP 422)
- `ITEM_BASE_UOM_MISSING` (HTTP 422)
- `ITEM_BASE_UOM_MISMATCH` (HTTP 422)
- `ITEM_UOM_UNIT_GROUP_MISMATCH` (HTTP 422)
- `ITEM_BASE_UOM_CHANGE_GROUP_MISMATCH` (HTTP 422)
- `ITEM_BASE_UOM_REBASE_INVALID_FACTOR` (HTTP 422)
- `ITEM_DELETE_REFERENCED_BY_SUPPLIERS` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_STOCK` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_RECIPE` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_BUNDLE` (HTTP 409)
- `ITEM_LOTS_ENABLE_HAS_STOCK` (HTTP 422)
- `ITEM_LOTS_DISABLE_HAS_STOCK` (HTTP 422)
- `ITEM_UOM_ALREADY_EXISTS` (HTTP 422)
- `ITEM_UOM_NOT_CONFIGURED` (HTTP 422)
- `ITEM_INACTIVE` (HTTP 422)
- `ITEM_NOT_PRODUCE_TYPE` (HTTP 422)
- `ITEM_NOT_BUNDLE_TYPE` (HTTP 422)
- `RECIPE_NOT_FOUND` (HTTP 404)
- `RECIPE_MISSING_BASE_UOM` (HTTP 422)
- `RECIPE_ITEM_INACTIVE` (HTTP 422)
- `RECIPE_ITEM_NO_STOCK_TRACKING` (HTTP 422)
- `RECIPE_SELF_REFERENCE` (HTTP 422)
- `RECIPE_INGREDIENT_INACTIVE` (HTTP 422)
- `RECIPE_INGREDIENT_TYPE_NOT_ALLOWED` (HTTP 422)
- `RECIPE_INGREDIENT_NO_STOCK_TRACKING` (HTTP 422)
- `RECIPE_CIRCULAR_REFERENCE` (HTTP 422)
- `RECIPE_INGREDIENT_DUPLICATE` (HTTP 422)
- `RECIPE_ITEM_SCOPE_MISMATCH` (HTTP 404)
- `BUNDLE_ITEM_INACTIVE` (HTTP 422)
- `BUNDLE_SELF_REFERENCE` (HTTP 422)
- `BUNDLE_CHILD_INACTIVE` (HTTP 422)
- `BUNDLE_NESTED_NOT_ALLOWED` (HTTP 422)
- `BUNDLE_CIRCULAR_REFERENCE` (HTTP 422)
- `BUNDLE_ITEM_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_INACTIVE` (HTTP 422)
- `SUPPLIER_ITEM_SCOPE_MISMATCH` (HTTP 404)

Stock:

- `STOCK_BALANCE_PARAMS_REQUIRED` (HTTP 422)
- `STOCK_MOVEMENT_ZERO_QUANTITY` (HTTP 422)
- `STOCK_ADJUSTMENT_ZERO_BASE_QUANTITY` (HTTP 422)
- `STOCK_ITEM_UOM_MISMATCH` (HTTP 422)
- `STOCK_INSUFFICIENT` (HTTP 422)
- `STOCK_ITEM_INACTIVE` (HTTP 422)
- `STOCK_ITEM_NOT_TRACKED` (HTTP 422)
- `STOCK_WAREHOUSE_INACTIVE` (HTTP 422)
- `STOCK_STANDARD_COST_REQUIRED` (HTTP 422)
- `STOCK_UNIT_COST_REQUIRED` (HTTP 422)
- `STOCK_UNIT_COST_INVALID` (HTTP 422)
- `STOCK_COST_LAYER_INSUFFICIENT` (HTTP 422)
- `STOCK_LOT_ITEM_REQUIRED` (HTTP 422)
- `STOCK_LOT_NOT_TRACKED` (HTTP 422)
- `STOCK_LOT_REQUIRED` (HTTP 422)
- `STOCK_LOT_MISMATCH` (HTTP 422)
- `STOCK_LOT_NOT_FOUND` (HTTP 422)
- `STOCK_LOT_EXPIRY_MISMATCH` (HTTP 422)
- `STOCK_TRANSFER_NOT_DRAFT` (HTTP 422)
- `STOCK_TRANSFER_SAME_WAREHOUSE` (HTTP 422)
- `STOCK_TRANSFER_WAREHOUSE_INACTIVE` (HTTP 422)
- `STOCK_TRANSFER_NO_LINES` (HTTP 422)
- `STOCK_TRANSFER_DUPLICATE_ITEM` (HTTP 422)
- `STOCK_TRANSFER_LINE_INVALID_QUANTITY` (HTTP 422)
- `STOCK_TRANSFER_ITEM_UOM_MISMATCH` (HTTP 422)
- `STOCK_TRANSFER_LINE_INVALID_BASE_QUANTITY` (HTTP 422)

Goods receipt:

- `GOODS_RECEIPT_NOT_DRAFT` (HTTP 422)
- `GOODS_RECEIPT_NO_LINES` (HTTP 422)
- `GOODS_RECEIPT_PO_NOT_RECEIVABLE` (HTTP 422)
- `GOODS_RECEIPT_PO_FULLY_RECEIVED` (HTTP 422)
- `GOODS_RECEIPT_PO_LINE_MISMATCH` (HTTP 422)
- `GOODS_RECEIPT_DUPLICATE_LINE` (HTTP 422)
- `GOODS_RECEIPT_QTY_EXCEEDS_OPEN` (HTTP 422)
- `GOODS_RECEIPT_WAREHOUSE_REQUIRED` (HTTP 422)
- `GOODS_RECEIPT_ITEM_REQUIRED` (HTTP 422)
- `PURCHASE_ORDER_CLOSED` (HTTP 422)
- `PURCHASE_ORDER_QTY_EXCEEDS_MAX` (HTTP 422) — PO qty would put on-hand + open PO + inbound above the item's warehouse max_qty

Opening stock:

- `OPENING_STOCK_NOT_DRAFT` (HTTP 422)
- `OPENING_STOCK_NO_LINES` (HTTP 422)
- `OPENING_STOCK_ITEM_REQUIRED` (HTTP 422)
- `OPENING_STOCK_DUPLICATE_LINE` (HTTP 422)
- `OPENING_STOCK_LINE_INVALID_QUANTITY` (HTTP 422)

Stock adjustment:

- `STOCK_ADJUSTMENT_NOT_DRAFT` (HTTP 422)
- `STOCK_ADJUSTMENT_NO_LINES` (HTTP 422)
- `STOCK_ADJUSTMENT_ITEM_REQUIRED` (HTTP 422)
- `STOCK_ADJUSTMENT_DUPLICATE_LINE` (HTTP 422)
- `STOCK_ADJUSTMENT_LINE_INVALID_QUANTITY` (HTTP 422)
- `STOCK_ADJUSTMENT_REASON_INACTIVE` (HTTP 422)
- `STOCK_ADJUSTMENT_REASON_DIRECTION` (HTTP 422)
- `STOCK_ADJUSTMENT_REASON_SYSTEM` (HTTP 422)
- `STOCK_ADJUSTMENT_REASON_IN_USE` (HTTP 422)

Production:

- `PRODUCTION_NOT_DRAFT` (HTTP 422)
- `PRODUCTION_NO_LINES` (HTTP 422)
- `PRODUCTION_ITEM_REQUIRED` (HTTP 422)
- `PRODUCTION_RECIPE_REQUIRED` (HTTP 422)
- `PRODUCTION_NO_INGREDIENTS` (HTTP 422)
- `PRODUCTION_QTY_INVALID` (HTTP 422)
- `PRODUCTION_INGREDIENT_MISSING` (HTTP 422)
- `PRODUCTION_INGREDIENT_UNKNOWN` (HTTP 422)
- `PRODUCTION_DUPLICATE_LINE` (HTTP 422)
- `PRODUCTION_LINE_INVALID_QUANTITY` (HTTP 422)

Bundle explosion:

- `BUNDLE_EXPLOSION_NOT_DRAFT` (HTTP 422)
- `BUNDLE_EXPLOSION_NO_LINES` (HTTP 422)
- `BUNDLE_EXPLOSION_ITEM_REQUIRED` (HTTP 422)
- `BUNDLE_EXPLOSION_NO_COMPONENTS` (HTTP 422)
- `BUNDLE_EXPLOSION_QTY_INVALID` (HTTP 422)
- `BUNDLE_EXPLOSION_COMPONENT_MISSING` (HTTP 422)
- `BUNDLE_EXPLOSION_COMPONENT_UNKNOWN` (HTTP 422)
- `BUNDLE_EXPLOSION_DUPLICATE_LINE` (HTTP 422)
- `BUNDLE_EXPLOSION_LINE_INVALID_QUANTITY` (HTTP 422)

Stock count:

- `STOCK_COUNT_NOT_DRAFT` (HTTP 422)
- `STOCK_COUNT_NO_LINES` (HTTP 422)
- `STOCK_COUNT_ITEM_REQUIRED` (HTTP 422)
- `STOCK_COUNT_DUPLICATE_LINE` (HTTP 422)
- `STOCK_COUNT_LINE_INVALID_QUANTITY` (HTTP 422)
- `STOCK_COUNT_COUNTED_REQUIRED` (HTTP 422)

Customer/Supplier scope and attachments:

- `ATTACHMENT_FILE_TOO_LARGE` (HTTP 413 — request body exceeds PHP/server or app limit)
- `ATTACHMENT_PRIMARY_IMAGE_ONLY` (HTTP 422 — only image attachments can be primary)
- `ATTACHMENT_DOWNLOAD_STORAGE_NOT_CONFIGURED` (HTTP 500)
- `CUSTOMER_ATTACHMENT_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_ATTACHMENT_SCOPE_MISMATCH` (HTTP 404)
- `CUSTOMER_CONTACT_SCOPE_MISMATCH` (HTTP 404)
- `CUSTOMER_ADDRESS_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_CONTACT_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_ADDRESS_SCOPE_MISMATCH` (HTTP 404)

Invoice proof:

- `INVOICE_SNAPSHOT_ALREADY_EXISTS` (HTTP 409) — a posted invoice already has an immutable snapshot
- `INVOICE_PROOFS_DISABLED` (HTTP 403) — company setting `invoice_proofs_enabled` is off; snapshot, chain registration, verify, company approval, and the public buyer portal are skipped
- `PROOF_LINK_INVALID` (HTTP 404) — buyer portal HMAC `exp`/`sig` is missing or does not match
- `PROOF_LINK_EXPIRED` (HTTP 403) — buyer portal HMAC `exp` is in the past
- `PROOF_WALLET_REQUIRED` (HTTP 422) — buyer portal unlock requires a customer `wallet_address`
- `PROOF_WALLET_MISMATCH` (HTTP 422) — personal_sign signer is neither the invoice buyer wallet nor an owner of the buyer Safe
- `PROOF_BUYER_UNKNOWN` (HTTP 422) — buyer history sign-in wallet is not stored on a customer of this company
- `PROOF_UNLOCK_INVALID` (HTTP 422) — unlock nonce is missing, expired, or already used
- `SALES_INVOICE_NOT_POSTED` (HTTP 422) — company approval is only allowed on posted invoices
- `INVOICE_PROOF_NOT_ON_CHAIN` (HTTP 422) — the hash is not on InvoiceRegistry yet
- `INVOICE_PROOF_TAMPERED` (HTTP 422) — local or chain proof no longer matches; company approval is blocked
- `INVOICE_PROOF_COMPANY_ALREADY_APPROVED` (HTTP 422) — `approveBySupplier` has already run for this proof
- `INVOICE_PROOF_PARTY_NOT_SET` (HTTP 422) — company approve was requested but the on-chain supplier slot is still `address(0)`
- `COMPANY_APPROVAL_REQUIRES_WALLET` (HTTP 422) — company approval is executed by the company Safe (`msg.sender` = supplier); Laravel does not broadcast `approveBySupplier`
- `INVOICE_PROOF_CHAIN_FAILED` (HTTP 503) — JSON-RPC / InvoiceRegistry write failed, or Safe owners could not be read
- `INVOICE_PROOF_NOT_REGISTERED` (HTTP 422) — a posted invoice has no sealed snapshot, so there is nothing to disclose
- `INVOICE_PROOF_FIELD_UNKNOWN` (HTTP 422) — a requested disclosure path is not a leaf of the sealed snapshot

Local invoice proof verification (`GET sales-invoices/{id}/verify`, `sales_invoices,view` plus `invoice_proofs,view` or `invoice_proofs,edit`) returns `data.status` of `verified`, `tampered`, `not_registered`, `pending_chain`, `waiting_company`, `waiting_buyer`, or `fully_approved`. It is a successful 200 check, not an error code. `chain_matches` is `null` until the hash is read from the InvoiceRegistry contract. Status compares the content hash of the sealed snapshot JSON with that on-chain hash. The content hash (schema v2) is the salted Merkle root of the snapshot leaves, see `CanonicalInvoiceMerkle`. `snapshot_intact` is false when that JSON no longer hashes to the stored `content_hash`, which is also `tampered`. `live_invoice_matches` only reports whether the current ERP invoice still serializes to the stored hash; it does not change `status`. A later customer, item, or invoice edit therefore stays `verified`, `waiting_company`, `waiting_buyer`, or `fully_approved` while the snapshot and chain still match. When the hash matches, `waiting_company` / `waiting_buyer` / `fully_approved` reflect the on-chain approvals. An empty supplier or buyer slot still waits for that party: `can_approve_as_company` / `can_approve_as_buyer` stay `false` and no typed data is returned until `setParties` fills the slot after the wallet is saved. `verified` means the hash matches but blockchain approvals do not apply (for example, the chain is off). When `waiting_company`, the payload includes `supplier_wallet` (the company Safe), `blockchain_network`, `safe_tx_service_url` (Sepolia only), and EIP-712 `SupplierApproval` typed data so the UI can encode `approveBySupplier`. Company approve is executed by that Safe (`msg.sender` must be supplier). When `waiting_buyer`, it includes `buyer_wallet` and `BuyerApproval` typed data for MetaMask. The endpoint is only available while invoice proofs are enabled.

Company approval (`POST sales-invoices/{id}/approve-as-company`) remains an ERP permission gate (`invoice_proofs,edit`), not `sales_invoices,edit`. Laravel does not broadcast `approveBySupplier`. The company Safe executes that call: Anvil uses the local 1-of-1 `OneOwnerSafe`; Sepolia uses Safe{Wallet} (typically 2-of-N) via the Safe Transaction Service. The Invoice Proofs row on the permissions matrix can only be assigned while company setting `invoice_proofs_enabled` is on; while it is off the checkboxes stay visible but disabled. `registerInvoice` stamps `proofId` + `contentHash` and optional party addresses (company Safe for the active network and/or customer `wallet_address`). Missing wallets register as `address(0)`; the seal still succeeds. Later wallet saves call registrar `setParties` to fill empty slots without changing the hash. Customer wallets must not be the company Safe or a Safe owner (`CUSTOMER_WALLET_IS_COMPANY_SAFE_OWNER`); company Safe addresses must not already be used by a customer (or have an owner that is) (`COMPANY_SAFE_WALLET_USED_BY_CUSTOMER`). `BLOCKCHAIN_NETWORK=anvil|sepolia` selects RPC, chain, contract, and registrar. Sepolia registration is signed with `BLOCKCHAIN_SEPOLIA_REGISTRAR_PRIVATE_KEY`.

Every stored party address carries a declared `wallet_type` (`wallet` or `safe`): customers (`wallet_type`, required with `wallet_address`), invoice verifiers (`wallet_type`, required), and the company profile (`wallet_type_anvil` / `wallet_type_sepolia`, each required with its address; responses also include `wallet_type` for the active network). On save, while invoice proofs are on and the chain is configured, the backend checks the active network: `wallet` must have no contract code (`WALLET_ADDRESS_IS_CONTRACT`), `safe` must have readable owners (`WALLET_SAFE_NOT_FOUND`). The company address for the inactive network is not checked (its RPC is not configured). `GET wallet-inspection?address=` (any authenticated tenant user, throttled) returns `available`, `blockchain_network`, `address`, `kind` (`wallet`, `safe`, or `contract`), `owners`, and `threshold` so forms can warn before saving; `available` is false when invoice proofs are off or the chain is not configured. Verify and portal payloads include `supplier_wallet_type` / `buyer_wallet_type` (verify) and `buyer_wallet_type` (portal challenge and unlocked portal) when the on-chain party is still the stored address.

The public buyer portal (`GET proofs/{sales_invoice}?exp=&sig=`) is unauthenticated on the tenant host. The clerk copies a signed URL from `POST sales-invoices/{id}/buyer-portal-link` (`sales_invoices,view` and `invoice_proofs,view`). UUID alone 404s with `PROOF_LINK_INVALID`; an expired stamp is `PROOF_LINK_EXPIRED`. A valid HMAC GET returns only a locked challenge (`locked`, `chain_id`, `buyer_wallet`, `nonce`, `message`) — no lines, totals, parties, `content_hash`, or EIP-712 payload. The buyer unlocks with `POST proofs/{sales_invoice}/unlock?exp=&sig=` by connecting the customer wallet and signing that message (`personal_sign`). A matching one-use nonce returns the sealed snapshot. Commercial fields (parties, dates, lines, totals) come from the sealed snapshot, not the live ERP invoice. It also returns `content_hash` (the snapshot's Merkle root) so the buyer can see the seal they are binding to. It does not return `canonical_json` or company-approve flags. After the company has approved, `supplier_wallet` is the company Safe address stored on the chain. `financed_at` is the time of the financier attestation, or `null`; the portal shows only that the invoice is financed, never the financier's wallet, name, or the other attestations. Verify compares the sealed snapshot hash with the chain. A mismatch is `tampered` and `can_approve_as_buyer` is false. Live ERP edits are not part of that check. Draft invoices and posted invoices with no snapshot 404. Buyer approval is signed in the browser (MetaMask) on that page; there is no buyer private key on the server.

Selective disclosure (`sales_invoices,view` and `invoice_proofs,view`, only while invoice proofs are enabled). `GET sales-invoices/{id}/proof-fields` returns `proof_id`, `content_hash`, `schema_version`, `leaf_count`, and `fields` (`path`, `value`) for every leaf of the sealed snapshot, in tree order. `POST sales-invoices/{id}/proof-disclosure` with `{ "fields": ["grand_total", "buyer.tax_number"] }` returns a self-contained bundle: `format` (`invoice-proof-disclosure`), `schema_version`, `proof_id`, `content_hash`, `leaf_count`, `chain_id`, `contract_address`, `tx_hash`, `block_number`, `generated_at`, and `fields` (`path`, `value`, `index`, `salt`, `proof` of `{ position: left|right, hash }` steps). Each field recomputes the root: `leaf = SHA-256(0x00 ‖ salt ‖ path ‖ 0x00 ‖ value)`, `node = SHA-256(0x01 ‖ left ‖ right)`, where value is `n`, `t`/`f`, `i<int>`, `s<utf-8>`, or `a` (empty list). A verifier compares that root with `contentHashOf(proof_id)` on InvoiceRegistry. Only the requested salts are returned; the per-snapshot `disclosure_secret` is never exposed, so undisclosed values cannot be guessed from sibling hashes. Draft invoices are `SALES_INVOICE_NOT_POSTED`, posted invoices without a snapshot are `INVOICE_PROOF_NOT_REGISTERED`, an unknown path is `INVOICE_PROOF_FIELD_UNKNOWN`, and a snapshot that no longer matches its stored hash is `INVOICE_PROOF_TAMPERED`.

Company verifiers (banks, auditors, tax authorities) are managed per tenant, only while invoice proofs are enabled (otherwise `403 INVOICE_PROOFS_DISABLED`). `GET invoice-verifiers` (`invoice_proofs,view`) lists them. `POST invoice-verifiers`, `PUT invoice-verifiers/{id}`, `DELETE invoice-verifiers/{id}`, and `POST invoice-verifiers/{id}/sync` require `invoice_proofs,edit`. Create takes `name`, `role` (`auditor|tax_authority|financier`), `wallet_address`, and optional `notes`. Update takes the same fields except `wallet_address`, which cannot change after create. `wallet_address` returns `422` when it is the zero address, is already listed, or is the company Safe or one of its owners. Each row returns `id`, `name`, `role`, `wallet_address`, `notes`, `chain_status` (`pending|active|failed|removing`), `chain_company_wallet`, `chain_tx_hash`, `chain_error`, `chain_synced_at`, `created_at`, and `updated_at`. The registrar wallet writes each row on chain with `InvoiceRegistry.setVerifier(companySafe, wallet, role)` from a queued job. Delete first revokes the wallet on chain (role `0`), then removes the row. Changing the company Safe re-queues every verifier for the new Safe. A row that is still `removing` cannot be edited (`422`).

Third-party attestations are written on chain by the verifier's own wallet (`InvoiceRegistry.attest`); Laravel never sends them. The contract accepts an attestation only from a wallet that the invoice's supplier company has listed. A personal wallet also signs an EIP-712 `Attestation` statement that the contract checks, while a listed Safe approves by executing the call. Verify (`GET sales-invoices/{id}/verify`) also returns `attestations` (a list of `verifier`, `verifier_name` (the matching company verifier's name, or `null`), `role` = `auditor|tax_authority|financier`, `reference_hash` or `null`, `attested_at`) and `financed_by`, which is the financier wallet or `null`. Both are empty while the proof is not registered on chain, and an RPC failure while reading them also returns an empty list without changing `status`. The contract allows only one financier per proof, so `financed_by` shows that the invoice has already been financed.

Chain consistency check (only while invoice proofs are enabled, otherwise `403 INVOICE_PROOFS_DISABLED`). `GET invoice-proofs/chain-check` (`invoice_proofs,view`) returns the latest run; `POST invoice-proofs/chain-check` (`invoice_proofs,edit`, throttled) runs one now. Both return `available` (false when the chain is not configured; `check` is then the last stored run or `null`) and `check`: `id`, `status` (`consistent|issues|failed`), `blockchain_network`, `contract_address`, `checked_count`, `issue_count`, `requeued_count`, `scanned_from_block`, `scanned_to_block`, `error` (RPC failure text when `failed`), `started_at`, `finished_at`, and `issues` (`id`, `kind`, `proof_id`, `chain_proof_id`, `invoice_id`, `invoice_number`, `expected`, `actual`). `kind` is `snapshot_altered` (stored JSON no longer hashes to the stored hash), `missing_on_chain` (registration confirmed but the contract has no record), `hash_mismatch` (contract hash differs from the snapshot), `other_contract` (confirmed on a different contract than the configured one), `status_out_of_sync` (on chain but the row is still pending/failed), `registration_stuck` (pending/failed past `BLOCKCHAIN_CHECK_STUCK_AFTER_MINUTES`), `not_submitted` (snapshot without a registration row), or `unknown_on_chain` (`InvoiceRegistered` for the company wallet with no matching snapshot). `expected` is the ERP side and `actual` the chain side. Stuck, out-of-sync, and not-submitted proofs are re-queued for registration. The nightly `invoice-proofs:check-chain` command runs the same check for every tenant and sends `invoice_proof.chain_issues` to `invoice_proofs,view` users when issues are found. The event scan resumes from the last scanned block for the same network, contract, and company wallet, starting at `BLOCKCHAIN_LOG_FROM_BLOCK`. `GET sales-invoices` and `GET sales-invoices/{id}` include `chain_issue`: `null`, or `{ kind, checked_at }` with the most severe issue for that invoice from the latest check (only when that check found issues). It is always `null` when invoice proofs are disabled or the user has neither `invoice_proofs,view` nor `invoice_proofs,edit`. Re-queued kinds (`status_out_of_sync`, `registration_stuck`, `not_submitted`) disappear once the registration is confirmed; other kinds stay until the next check.

Central admin API (central host, `/api` prefix, central Bearer token). Every route below requires `auth:sanctum` + `ensure.active` and `check.central.permission:<resource>,<action>` from `config/central_rbac.php`; a missing permission is `403 FORBIDDEN` in the envelope. Only `login`, `forgot-password`, `reset-password`, and `tenant/get-tenant-by-name/{name}` stay public. `GET/PUT auth/me` (any authenticated central user) returns `id`, `name`, `email`, `is_active`, `role` (`id`, `name`, `is_system`) and `permissions` (same matrix shape as tenant `auth/me`); PUT takes `name` and optional `current_password` + `password` + `password_confirmation` (`CURRENT_PASSWORD_INVALID`, `PASSWORD_UNCHANGED`; a password change revokes every token). Central `login` also returns `user.role` and `permissions`. `GET overview` (`overview,view`) returns `tenants` (`total`, `active`, `suspended`, `created_last_30_days`), `users` (`total`, `active`), `recent_tenants`, `modules` (`code`, `name`, `is_core`, `tenants_count`) and `generated_at`. `GET tenants` (`tenants,view`) is paginated (`page`, `per_page`, `search` on id/name/domain, `status=active|suspended`); each row has `id`, `name`, `status`, `suspended_at`, `suspension_reason`, `domains`, `primary_domain`, `modules`, `owner` (detail only), `created_at`, `updated_at`. `POST tenants` (`tenants,add`) takes `name`, `domain`, `email`, `password`, `password_confirmation` (reserved or taken subdomains are `422` on `domain`). `GET tenants/{id}` (`tenants,view`), `PUT tenants/{id}` (`tenants,edit`, `name`) and `PATCH tenants/{id}/status` (`tenants,edit`, `status=active|suspended`, optional `reason`; suspending revokes every tenant token) 404 with `TENANT_NOT_FOUND`. `GET/PUT tenants/{id}/modules` (`tenant_modules,view|edit`) return `tenant_id`, `modules` (codes, `core` always kept) and `available` (module catalog). `GET modules` (`modules,view`) adds `tenants_count`. `users` (`users,*`: `name`, `email`, `password`, `password_confirmation`, `is_active`, `central_role_id`; `?section=names`), `roles` (`roles,*`; `?section=names`), `permissions`, `permissions/roles`, `roles/{id}/permissions` (`permissions,view|edit`) and `audits`, `audits/{id}`, `audits/export` (`audits,view|export`) mirror the tenant endpoints of the same name. Run `php artisan central:sync-rbac` after deploying (it seeds the catalog, the Super Admin role, and assigns it to central users without a role).

## Rules For New Endpoints

1. Always return `code` for API errors.
2. Reuse existing codes when meaning matches.
3. Add new codes only when behavior/handling differs.
4. Keep one semantic meaning per code.
5. When adding a new code:
    - add backend usage in response envelope
    - add frontend translations under `ApiErrors.codes.<CODE>` in `messages/en.json` and `messages/ar.json`
