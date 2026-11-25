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
- `TENANT_DELETE_REQUIRES_SUSPENSION` (HTTP 409) — `DELETE tenants/{id}` on a tenant that is not suspended
- `PLATFORM_LOGO_NOT_FOUND` (HTTP 404) — `GET branding/logo` when no platform logo is set
- `PLAN_IN_USE` (HTTP 409) — `DELETE plans/{id}` while tenants are on the plan
- `TENANT_USER_NOT_FOUND` (HTTP 404) — central tenant-user support action on a user that does not exist in the tenant
- `TENANT_USER_INACTIVE` (HTTP 422) — password-reset link or make-owner for an inactive tenant user
- `TENANT_LAST_OWNER_PROTECTED` (HTTP 409) — an owner change that would leave the tenant without an active owner
- `TENANT_NO_DOMAIN` (HTTP 422) — password-reset link for a tenant without a domain
- `PLAN_LIMIT_REACHED` (HTTP 422, tenant host) — creating or re-activating a user, creating a branch or warehouse, or uploading a file past the tenant plan's limit; `data` carries `limit` (`users`, `branches`, `warehouses`, `storage_mb`), `max` and `used`
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
- `SUPPLIER_GROUP_DELETE_HAS_MEMBERS` (HTTP 409)

Currency:

- `CURRENCY_PRIMARY_DELETE_FORBIDDEN` (HTTP 422)
- `CURRENCY_PRIMARY_LOCKED` (HTTP 422) — primary currency cannot change once item prices, supplier prices, stock or documents exist

Branches:

- `BRANCH_DEFAULT_DELETE_FORBIDDEN` (HTTP 422)
- `BRANCH_DEFAULT_UNSET_FORBIDDEN` (HTTP 422)
- `BRANCH_DEFAULT_DEACTIVATE_OWNER_ONLY` (HTTP 403)
- `BRANCH_DELETE_HAS_WAREHOUSES` (HTTP 409)
- `DOCUMENT_BRANCH_REQUIRED` (HTTP 422) — no branch exists to number a document (`DocumentNumberService`)
- `DOCUMENT_NUMBER_START_TOO_LOW` (HTTP 422) — numbering settings: the first number must be above the last number already issued for that document type

Document numbers: posted documents get `{PREFIX}-{BRANCH}-{YYYY}-{000001}` by default when posted (drafts have no number);
the prefix, branch/year parts, padding and first number are configurable per type (`GET/PUT document-number-settings`);
quotations, sales orders and purchase orders get theirs at create. Series are per document type, branch and year.

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
- `ITEM_DELETE_REFERENCED_BY_SUPPLIERS` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_STOCK` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_RECIPE` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_BUNDLE` (HTTP 409)
- `ITEM_BASE_UOM_REBASE_INVALID_FACTOR` (HTTP 422)
- `ITEM_DELETE_REFERENCED_BY_SUPPLIERS` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_STOCK` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_RECIPE` (HTTP 409)
- `ITEM_DELETE_REFERENCED_BY_BUNDLE` (HTTP 409)
- `ITEM_LOTS_ENABLE_HAS_STOCK` (HTTP 422)
- `ITEM_LOTS_DISABLE_HAS_STOCK` (HTTP 422)
- `ITEM_SERIALS_LOTS_EXCLUSIVE` (HTTP 422)
- `ITEM_SERIALS_ENABLE_HAS_STOCK` (HTTP 422)
- `ITEM_SERIALS_DISABLE_HAS_STOCK` (HTTP 422)
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

- `STOCK_INSUFFICIENT_TO_RESERVE` (HTTP 422) — confirming a sales order needs more than on hand minus what other confirmed orders reserve
- `STOCK_RESERVED` (HTTP 422) — an outbound movement (sale, transfer, production, ...) would use stock reserved by confirmed sales orders; counts and adjustments are exempt
- `SALES_ORDER_NOT_CLOSABLE` (HTTP 422) — only confirmed / partially invoiced orders can be closed (closing releases the remaining reservation)

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
- `STOCK_SERIAL_NOT_TRACKED` (HTTP 422)
- `STOCK_SERIAL_COUNT_MISMATCH` (HTTP 422)
- `STOCK_SERIAL_WHOLE_QUANTITY` (HTTP 422)
- `STOCK_SERIAL_DUPLICATE` (HTTP 422)
- `STOCK_SERIAL_TOO_LONG` (HTTP 422)
- `STOCK_SERIAL_ALREADY_IN_STOCK` (HTTP 422)
- `STOCK_SERIAL_NOT_AVAILABLE` (HTTP 422)
- `STOCK_SERIAL_MOVED_SINCE` (HTTP 422)
- `STOCK_SERIAL_NOT_ON_SOURCE` (HTTP 422)
- `STOCK_SERIAL_NOT_IN_TRANSIT` (HTTP 422)
- `PURCHASE_DEBIT_NOTE_BUNDLE_SERIAL_NOT_SUPPORTED` (HTTP 422)
- `SALES_INVOICE_BUNDLE_SERIAL_NOT_SUPPORTED` (HTTP 422)
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

Opening stock:

- `OPENING_STOCK_NOT_DRAFT` (HTTP 422)
- `OPENING_STOCK_NO_LINES` (HTTP 422)
- `OPENING_STOCK_ITEM_REQUIRED` (HTTP 422)
- `OPENING_STOCK_DUPLICATE_LINE` (HTTP 422)
- `OPENING_STOCK_LINE_INVALID_QUANTITY` (HTTP 422)
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

Stock revaluation:

- `STOCK_REVALUATION_NOT_DRAFT` (HTTP 422)
- `STOCK_REVALUATION_NO_LINES` (HTTP 422)
- `STOCK_REVALUATION_ITEM_REQUIRED` (HTTP 422)
- `STOCK_REVALUATION_DUPLICATE_LINE` (HTTP 422)
- `STOCK_REVALUATION_NO_QTY` (HTTP 422)
- `STOCK_REVALUATION_NO_CHANGE` (HTTP 422)
- `STOCK_REVALUATION_NOT_POSTED` (HTTP 422)

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

Goods receipt:

- `GOODS_RECEIPT_NOT_DRAFT` (HTTP 422)
- `GOODS_RECEIPT_NO_LINES` (HTTP 422)
- `GOODS_RECEIPT_PO_NOT_RECEIVABLE` (HTTP 422)
- `GOODS_RECEIPT_PO_FULLY_RECEIVED` (HTTP 422)
- `GOODS_RECEIPT_PO_LINE_MISMATCH` (HTTP 422)
- `GOODS_RECEIPT_DUPLICATE_LINE` (HTTP 422)
- `GOODS_RECEIPT_QTY_EXCEEDS_OPEN` (HTTP 422)

Customer/Supplier scope and attachments:

- `ATTACHMENT_FILE_TOO_LARGE` (HTTP 413 — request body exceeds PHP/server or app limit)
- `ATTACHMENT_PRIMARY_IMAGE_ONLY` (HTTP 422 — only image attachments can be primary)
- `ATTACHMENT_FILE_TOO_LARGE` (HTTP 413 — request body exceeds PHP/server or app limit)
- `ATTACHMENT_DOWNLOAD_STORAGE_NOT_CONFIGURED` (HTTP 500)
- `CUSTOMER_ATTACHMENT_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_ATTACHMENT_SCOPE_MISMATCH` (HTTP 404)
- `CUSTOMER_CONTACT_SCOPE_MISMATCH` (HTTP 404)
- `CUSTOMER_ADDRESS_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_CONTACT_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_ADDRESS_SCOPE_MISMATCH` (HTTP 404)

Sales credit notes:

- `SALES_CREDIT_NOTE_NOT_DRAFT` (HTTP 422)
- `SALES_CREDIT_NOTE_NOT_POSTED` (HTTP 422)
- `SALES_CREDIT_NOTE_INVOICE_NOT_POSTED` (HTTP 422)
- `SALES_CREDIT_NOTE_DATE_BEFORE_INVOICE` (HTTP 422)
- `SALES_CREDIT_NOTE_QTY_EXCEEDED` (HTTP 422)
- `SALES_CREDIT_NOTE_AMOUNT_EXCEEDED` (HTTP 422)
- `SALES_CREDIT_NOTE_EXCEEDS_INVOICE` (HTTP 422)
- `SALES_CREDIT_NOTE_NO_LINES` (HTTP 422)
- `SALES_CREDIT_NOTE_TOTAL_INVALID` (HTTP 422)
- `SALES_CREDIT_NOTE_LINE_MISMATCH` (HTTP 422)
- `SALES_CREDIT_NOTE_INVOICE_MISMATCH` (HTTP 422)
- `SALES_CREDIT_NOTE_INVOICE_NOT_OPEN` (HTTP 422)
- `SALES_CREDIT_NOTE_APPLY_EXCEEDS_OPEN` (HTTP 422)
- `SALES_CREDIT_NOTE_HAS_REFUNDS` (HTTP 422)
- `SALES_CREDIT_NOTE_WAREHOUSE_REQUIRED` (HTTP 422)
- `SALES_CREDIT_NOTE_BUNDLE_EMPTY` (HTTP 422)
- `SALES_CREDIT_NOTE_BUNDLE_LOT_NOT_SUPPORTED` (HTTP 422)
- `SALES_CREDIT_NOTE_BUNDLE_SERIAL_NOT_SUPPORTED` (HTTP 422)
- `SALES_CREDIT_NOTE_TYPE_INVALID` (HTTP 422)
- `SALES_INVOICE_HAS_CREDIT_NOTES` (HTTP 422)
- `CUSTOMER_CREDIT_LIMIT_EXCEEDED` (HTTP 422) — posting an invoice / confirming an order would put the customer's balance (per currency) above the credit limit; needs `credit_limit_override,edit`. A limit of 0 means no limit.

Price lists:

- `PRICE_LIST_ITEM_UOM_MISMATCH` (HTTP 422) — a price row's unit does not belong to its item
- `PRICE_LIST_ITEM_INVALID` (HTTP 422) — negative price or minimum quantity
- `PRICE_LIST_DUPLICATE_ITEM` (HTTP 422) — same item, unit and minimum quantity twice in one list
- `PRICE_LIST_DATES_INVALID` (HTTP 422) — end date before start date

Price changes:

- `PRICE_CHANGE_NOT_DRAFT` (HTTP 422) — only a draft can be edited or deleted
- `PRICE_CHANGE_NOT_OPEN` (HTTP 422) — only a draft or a scheduled change can be applied
- `PRICE_CHANGE_NOT_CANCELLABLE` (HTTP 422) — only a draft or a scheduled change can be cancelled
- `PRICE_CHANGE_EMPTY` (HTTP 422) — the document has no prices
- `PRICE_CHANGE_LINE_INVALID` (HTTP 422) — a line is missing its item, unit or field, or the same price appears twice
- `PRICE_CHANGE_ROW_MISSING` (HTTP 422) — a price list row on the document no longer exists
- `PRICE_CHANGE_FIELD_INVALID` (HTTP 422) — unknown price field
- `PRICE_CHANGE_BULK_INVALID` (HTTP 422) — the bulk rule or rounding is not recognised
- `PRICE_CHANGE_BULK_LIST_REQUIRED` (HTTP 422) — changing list prices needs a price list
- `PRICE_BELOW_COST` (HTTP 422) — a new selling price is below the current cost; the response lists the rows. Needs `price_below_cost_override,edit`


Customer refunds:

- `CUSTOMER_REFUND_FILTER_REQUIRED` (HTTP 422)
- `CUSTOMER_REFUND_HEADER_LOCKED` (HTTP 422)
- `CUSTOMER_REFUND_NOT_DRAFT` (HTTP 422)
- `CUSTOMER_REFUND_NOT_POSTED` (HTTP 422)
- `CUSTOMER_REFUND_AMOUNT_INVALID` (HTTP 422)
- `CUSTOMER_REFUND_ALLOCATION_MISMATCH` (HTTP 422)
- `CUSTOMER_REFUND_CREDIT_NOTE_MISMATCH` (HTTP 422)
- `CUSTOMER_REFUND_CREDIT_NOTE_NOT_OPEN` (HTTP 422)

Purchase debit notes use the same set as sales credit notes with the `PURCHASE_DEBIT_NOTE_` prefix, plus `PURCHASE_INVOICE_HAS_DEBIT_NOTES`.

Supplier refunds use the same set as customer refunds with the `SUPPLIER_REFUND_` prefix and `DEBIT_NOTE` in place of `CREDIT_NOTE`.

Sales credit notes:

- `SALES_CREDIT_NOTE_NOT_DRAFT` (HTTP 422)
- `SALES_CREDIT_NOTE_NOT_POSTED` (HTTP 422)
- `SALES_CREDIT_NOTE_INVOICE_NOT_POSTED` (HTTP 422)
- `SALES_CREDIT_NOTE_DATE_BEFORE_INVOICE` (HTTP 422)
- `SALES_CREDIT_NOTE_QTY_EXCEEDED` (HTTP 422)
- `SALES_CREDIT_NOTE_AMOUNT_EXCEEDED` (HTTP 422)
- `SALES_CREDIT_NOTE_EXCEEDS_INVOICE` (HTTP 422)
- `SALES_CREDIT_NOTE_NO_LINES` (HTTP 422)
- `SALES_CREDIT_NOTE_TOTAL_INVALID` (HTTP 422)
- `SALES_CREDIT_NOTE_LINE_MISMATCH` (HTTP 422)
- `SALES_CREDIT_NOTE_INVOICE_MISMATCH` (HTTP 422)
- `SALES_CREDIT_NOTE_INVOICE_NOT_OPEN` (HTTP 422)
- `SALES_CREDIT_NOTE_APPLY_EXCEEDS_OPEN` (HTTP 422)
- `SALES_CREDIT_NOTE_HAS_REFUNDS` (HTTP 422)
- `SALES_CREDIT_NOTE_WAREHOUSE_REQUIRED` (HTTP 422)
- `SALES_CREDIT_NOTE_BUNDLE_EMPTY` (HTTP 422)
- `SALES_CREDIT_NOTE_BUNDLE_LOT_NOT_SUPPORTED` (HTTP 422)
- `SALES_CREDIT_NOTE_TYPE_INVALID` (HTTP 422)
- `SALES_INVOICE_HAS_CREDIT_NOTES` (HTTP 422)

Customer refunds:

- `CUSTOMER_REFUND_FILTER_REQUIRED` (HTTP 422)
- `CUSTOMER_REFUND_HEADER_LOCKED` (HTTP 422)
- `CUSTOMER_REFUND_NOT_DRAFT` (HTTP 422)
- `CUSTOMER_REFUND_NOT_POSTED` (HTTP 422)
- `CUSTOMER_REFUND_AMOUNT_INVALID` (HTTP 422)
- `CUSTOMER_REFUND_ALLOCATION_MISMATCH` (HTTP 422)
- `CUSTOMER_REFUND_CREDIT_NOTE_MISMATCH` (HTTP 422)
- `CUSTOMER_REFUND_CREDIT_NOTE_NOT_OPEN` (HTTP 422)

Purchase debit notes use the same set as sales credit notes with the `PURCHASE_DEBIT_NOTE_` prefix, plus `PURCHASE_INVOICE_HAS_DEBIT_NOTES`.

Supplier refunds use the same set as customer refunds with the `SUPPLIER_REFUND_` prefix and `DEBIT_NOTE` in place of `CREDIT_NOTE`.

Central admin API (central host, `/api` prefix, central Bearer token). Every route below requires `auth:sanctum` + `ensure.active` and `check.central.permission:<resource>,<action>` from `config/central_rbac.php`; a missing permission is `403 FORBIDDEN` in the envelope. Only `login`, `forgot-password`, `reset-password`, and `tenant/get-tenant-by-name/{name}` stay public. `GET/PUT auth/me` (any authenticated central user) returns `id`, `name`, `email`, `is_active`, `role` (`id`, `name`, `is_system`) and `permissions` (same matrix shape as tenant `auth/me`); PUT takes `name` and optional `current_password` + `password` + `password_confirmation` (`CURRENT_PASSWORD_INVALID`, `PASSWORD_UNCHANGED`; a password change revokes every token). Central `login` also returns `user.role` and `permissions`. `GET overview` (`overview,view`) returns `tenants` (`total`, `active`, `suspended`, `created_last_30_days`), `users` (`total`, `active`), `recent_tenants`, `modules` (`code`, `name`, `is_core`, `tenants_count`) and `generated_at`. `GET tenants` (`tenants,view`) is paginated (`page`, `per_page`, `search` on id/name/domain, `status=active|suspended`); each row has `id`, `name`, `status`, `suspended_at`, `suspension_reason`, `domains`, `primary_domain`, `modules`, `owner` (detail only), `created_at`, `updated_at`. `POST tenants` (`tenants,add`) takes `name`, `domain`, `email`, `password`, `password_confirmation` (reserved or taken subdomains are `422` on `domain`). `GET tenants/{id}` (`tenants,view`), `PUT tenants/{id}` (`tenants,edit`, `name`) and `PATCH tenants/{id}/status` (`tenants,edit`, `status=active|suspended`, optional `reason`; suspending revokes every tenant token) 404 with `TENANT_NOT_FOUND`. `DELETE tenants/{id}` (`tenants,delete`) takes `confirmation` (must equal the tenant id, otherwise `422` on `confirmation`), only works on a suspended tenant (`409 TENANT_DELETE_REQUIRES_SUSPENSION`), and permanently removes the tenant schema, its attachment files, domains, and module rows; the audit log keeps a `deleted` entry with the tenant's id, name, domains, and modules. `GET tenants/{id}/users` (`tenant_users,view`) lists the tenant's users (`id`, `name`, `email`, `is_active`, `is_owner`, `roles`, `last_login_at` from the tenant's login audits, `created_at`). `POST tenants/{id}/users/{user}/password-reset` (`tenant_users,edit`) emails a reset link pointing at the tenant host (`FRONTEND_SCHEME` + primary domain + `FRONTEND_PORT`) and returns `email`, `url`, `expires_at`, `email_sent` (the link is shown to the admin once; the audit `password_reset_link_created` never stores it). `POST tenants/{id}/users/{user}/make-owner` (`tenant_users,edit`, `demote_others` boolean) gives the user the Owner role on the default branch and, with `demote_others`, turns other owners into Admin; it returns `users` and `owner` and is audited as `owner_changed`. `GET tenants/{id}/modules` (`tenant_modules,view`) returns `tenant_id`, `modules` and `available` (module catalog); modules are read-only and always equal the tenant's plan. `POST tenants` also requires `plan_id` (an active plan) and accepts `subscription_starts_at` (default today), `subscription_ends_at` (default one billing period later; send `null` for no end) and `trial_ends_at`. `PUT tenants/{id}/subscription` (`tenants,edit`) takes the same four fields, switches the plan, and re-syncs the modules. Tenant rows include `plan` (`id`, `name`), `subscription_starts_at`, `subscription_ends_at`, `trial_ends_at`. `plans` (`plans,*`; `?section=names`) take `name`, `description`, `price`, `currency` (3 letters), `billing_cycle` (`monthly|yearly`), `max_users`, `max_branches`, `max_warehouses`, `max_storage_mb` (null = unlimited), `is_active`, `sort_order`, `modules` (`core` always added); changing a plan's modules re-syncs every tenant on it. `GET overview` also returns `plans` (with `tenants_count`) and `expiring_tenants` (active, ending within `expiring_days`). `php artisan tenants:expire-subscriptions` (daily 00:10) suspends active tenants whose end date has passed; `php artisan central:sync-plans` puts tenants without a plan on the default Unlimited plan. On tenant hosts, `GET tenant/subscription` (`subscription,view`) returns `plan` (`id`, `name`, `price`, `currency`, `billing_cycle`), `limits`, `usage` (`users`, `branches`, `warehouses`, `storage_mb`), `subscription_starts_at`, `subscription_ends_at` and `trial_ends_at`. `GET modules` (`modules,view`) adds `tenants_count`. `users` (`users,*`: `name`, `email`, `password`, `password_confirmation`, `is_active`, `central_role_id`; `?section=names`), `roles` (`roles,*`; `?section=names`), `permissions`, `permissions/roles`, `roles/{id}/permissions` (`permissions,view|edit`) and `audits`, `audits/{id}`, `audits/export` (`audits,view|export`) mirror the tenant endpoints of the same name. `GET platform-settings` (`platform_settings,view`) returns `name`, `has_logo`, `logo_version`; `PUT platform-settings` (`platform_settings,edit`) takes `name` (max 80); `POST platform-settings/logo` takes `file` (png, jpg, webp, max 2 MB; SVG is rejected) and `DELETE platform-settings/logo` removes it. Public (no auth): `GET branding` returns the same payload and `GET branding/logo?v={logo_version}` streams the logo. Run `php artisan central:sync-rbac` after deploying (it seeds the catalog, the Super Admin role, and assigns it to central users without a role).

## Rules For New Endpoints

1. Always return `code` for API errors.
2. Reuse existing codes when meaning matches.
3. Add new codes only when behavior/handling differs.
4. Keep one semantic meaning per code.
5. When adding a new code:
   - add backend usage in response envelope
   - add frontend translations under `ApiErrors.codes.<CODE>` in `messages/en.json` and `messages/ar.json`

Treasury (cash/bank accounts, transfers, expense vouchers):

- `TREASURY_LEDGER_ACCOUNT_INVALID` (HTTP 422) — a cash/bank account must use an active, non-group asset account
- `TREASURY_ACCOUNT_IN_USE` (HTTP 409/422) — account used by documents: cannot delete or change its ledger account / currency
- `TREASURY_ACCOUNT_INACTIVE` (HTTP 422) — cash/bank account missing or inactive
- `TREASURY_ACCOUNT_REQUIRED` (HTTP 422) — cash/bank account has no ledger account at posting
- `TREASURY_SAME_ACCOUNT` (HTTP 422) — transfer from and to the same account
- `TREASURY_AMOUNT_INVALID` (HTTP 422) — amount must be greater than zero
- `TREASURY_DOCUMENT_NOT_DRAFT` / `TREASURY_DOCUMENT_NOT_POSTED` (HTTP 422) — wrong status for edit/post or reverse
- `EXPENSE_VOUCHER_ACCOUNT_INVALID` (HTTP 422) — expense lines must use an active, non-group expense account
- `EXPENSE_VOUCHER_NO_LINES` (HTTP 422) — post needs at least one line with a positive total

Recurring entries and deferral schedules:

- `RECURRING_TEMPLATE_INVALID_SCHEDULE` (HTTP 422) — frequency, day, or date range is not a valid schedule
- `RECURRING_TEMPLATE_UNBALANCED` (HTTP 422) — journal template debits do not equal credits
- `RECURRING_TEMPLATE_NO_LINES` (HTTP 422) — the template has no lines or a zero total
- `RECURRING_TEMPLATE_NOT_ACTIVE` (HTTP 422) — run or skip on a template that is not active
- `RECURRING_TEMPLATE_NOT_EDITABLE` (HTTP 422) — edit of a finished template, or a schedule change after the first run
- `RECURRING_TEMPLATE_HAS_RUNS` (HTTP 422) — delete is blocked once a run exists; cancel instead
- `RECURRING_RUN_NOT_FAILED` (HTTP 422) — retry of a run that is not the current failed occurrence
- `DEFERRAL_INVALID_PERIOD` (HTTP 422) — end before start, or more periods than the configured maximum
- `DEFERRAL_ACCOUNT_INVALID` (HTTP 422) — account is the wrong type, inactive, a group, or a controlled role
- `DEFERRAL_NOT_DRAFT` (HTTP 422) — update, delete, or activate of a schedule that is not a draft
- `DEFERRAL_NOT_ACTIVE` (HTTP 422) — post or pause of a schedule that is not active
- `DEFERRAL_LINE_OUT_OF_ORDER` (HTTP 422) — posting out of order, or reversing a line that is not the latest posted
- `DEFERRAL_LINE_NOT_POSTED` / `DEFERRAL_LINE_NOT_PENDING` (HTTP 422) — the line is not in the status the action needs
- `DEFERRAL_HAS_POSTED_LINES` (HTTP 422) — cancel, or reverse the source voucher, while recognition lines are posted
- `DEFERRAL_INSUFFICIENT_BALANCE` (HTTP 422) — manual activation when the deferral account balance is below the schedule and strict checking is on
- `DEFERRAL_SOURCE_LINE_TAKEN` (HTTP 422) — that voucher line already has a schedule

Delivery notes (optional step: sales order -> delivery note -> invoice):

- `DELIVERY_NOTE_NOT_DRAFT` / `DELIVERY_NOTE_NOT_POSTED` (HTTP 422) — wrong status for edit/post, reverse or invoice
- `DELIVERY_NOTE_NO_LINES` (HTTP 422) — post needs at least one line
- `DELIVERY_NOTE_HAS_INVOICES` (HTTP 422) — reverse the invoices made from it first
- `DELIVERY_NOTE_NOTHING_TO_INVOICE` (HTTP 422) — every delivered quantity is already invoiced
- `DELIVERY_NOTE_ORDER_LINE_MISMATCH` (HTTP 422) — line does not match its sales order line (item/unit)
- `DELIVERY_NOTE_LOT_ITEM_MISMATCH` (HTTP 422) — lot belongs to another item
- `DELIVERY_NOTE_PDF_NOT_ALLOWED` (HTTP 422) — only posted/reversed delivery notes print
- `DELIVERY_NOTE_ORDER_CUSTOMER_MISMATCH` (HTTP 422) — the chosen sales order belongs to another customer
- `SALES_ORDER_NOTHING_TO_DELIVER` (HTTP 422) — everything on the order already shipped
- `SALES_ORDER_DELIVERY_EXCEEDED` (HTTP 422) — shipping more than the order line (delivery note or direct invoice)
- `SALES_ORDER_HAS_DELIVERIES` (HTTP 422) — an order with posted deliveries cannot be cancelled (close it)
- `SALES_INVOICE_DELIVERY_LINE_INVALID` / `SALES_INVOICE_DELIVERY_LINE_MISMATCH` (HTTP 422) — invoice line must come from a posted delivery note of the same customer, item and unit
- `SALES_INVOICE_DELIVERY_QTY_EXCEEDED` (HTTP 422) — invoicing more than the delivery note delivered

## Item variants

| Code | HTTP | Meaning |
| --- | --- | --- |
| `ITEM_ATTRIBUTE_IN_USE` | 409 | The attribute is used by item variants. |
| `ITEM_ATTRIBUTE_VALUE_IN_USE` | 409 | A removed attribute value is used by item variants. |
| `ITEM_VARIANT_NESTED` | 422 | A variant cannot have its own variants. |
| `ITEM_VARIANT_PARENT_HAS_STOCK` | 422 | An item with stock history cannot become a variant template. |
| `ITEM_VARIANT_ATTRIBUTES_LOCKED` | 422 | Attributes are fixed once variants exist; only values can be added. |
| `ITEM_VARIANT_DUPLICATE_ATTRIBUTE` | 422 | The same attribute was chosen twice. |
| `ITEM_VARIANT_VALUE_MISMATCH` | 422 | A chosen value does not belong to its attribute. |
| `ITEM_VARIANT_TOO_MANY` | 422 | More than 200 combinations. |
| `ITEM_VARIANT_PARENT_NOT_STOCKABLE` | 422 | Stock cannot move for a variant template. |
| `ITEM_DELETE_HAS_VARIANTS` | 409 | Delete the variants before the template item. |


## Opening invoices and opening credits

Old open balances of customers/suppliers are opening documents (`is_opening = true` on sales/purchase invoices and credit/debit notes), managed from the customer/supplier (`opening_documents` on save, `GET customers|suppliers/{id}/opening-documents`).

| Code | HTTP | Meaning |
| --- | --- | --- |
| `OPENING_DOCUMENT_AMOUNT_INVALID` | 422 | An opening amount cannot be zero. |
| `OPENING_DOCUMENT_DUE_DATE_INVALID` | 422 | The due date is before the document date. |
| `OPENING_DOCUMENT_NOT_FOUND` | 404 | The row's id is not an opening document of this customer/supplier. |
| `OPENING_DOCUMENT_IN_USE` | 422 | A payment, credit or refund already uses it: amount, currency, date and branch can no longer change, and it cannot be removed. |
| `OPENING_DOCUMENT_MANAGED_BY_PARTY` | 422 | Opening documents cannot be reversed or printed as normal documents. |
| `OPENING_INVOICE_NO_RETURN` | 422 | A credit/debit note (return) cannot be made against an opening invoice; apply an existing credit instead. |

## Bill discount, discount limits, payments at sale

| Code | HTTP | Meaning |
| --- | --- | --- |
| `SALES_HEADER_DISCOUNT_TOO_LARGE` | 422 | The bill discount is above 100% or larger than the document total. |
| `DISCOUNT_APPROVAL_REQUIRED` | 422 | The discount is above the user's role limit; send `discount_approval: {email, password}` of a manager. |
| `DISCOUNT_APPROVAL_INVALID` | 422 | The approval login is wrong, inactive, has no access to the branch, or cannot approve this discount. |
| `DISCOUNT_APPROVAL_THROTTLED` | 429 | Too many approval attempts; try again in a minute. |
| `SALES_INVOICE_PAYMENT_METHOD_INVALID` | 422 | Payment at sale needs an active method that takes money now (not "credit"). |
| `SALES_INVOICE_PAYMENT_AMOUNT_INVALID` | 422 | A payment at sale must be greater than zero. |
| `SALES_INVOICE_TENDERED_TOO_LOW` | 422 | Cash given is less than the amount paid. |
| `SALES_INVOICE_PAYMENTS_EXCEED_TOTAL` | 422 | Payments at sale are more than the invoice total (checked at post). |
| `SALES_INVOICE_PAYMENT_CURRENCY_MISMATCH` | 422 | The payment's cash/bank account is not in the invoice currency. |
| `SALES_INVOICE_WALK_IN_MUST_PAY` | 422 | A walk-in customer must pay the whole invoice at posting. |
| `CUSTOMER_RECEIPT_MANAGED_BY_INVOICE` | 422 | A receipt taken with its sales invoice is reversed only with that invoice. |
| `PAYMENT_METHOD_ACCOUNT_DUPLICATE_BRANCH` | 422 | A payment method has one default cash/bank account per branch. |

## Landed costs

| Code | HTTP | Meaning |
| --- | --- | --- |
| `LANDED_COST_NOT_DRAFT` | 422 | Only a draft landed cost can be changed, deleted or posted. |
| `LANDED_COST_NOT_POSTED` | 422 | Only a posted landed cost can be reversed. |
| `LANDED_COST_NO_RECEIPTS` | 422 | Choose at least one receipt. |
| `LANDED_COST_NO_RECEIPT_LINES` | 422 | The chosen receipts have no stock lines. |
| `LANDED_COST_NO_CHARGES` | 422 | Add at least one charge. |
| `LANDED_COST_RECEIPT_NOT_POSTED` | 422 | Only posted goods receipts and direct purchase invoices can take landed costs. |
| `LANDED_COST_CHARGE_NOT_ELIGIBLE` | 422 | The bill line is not a posted landed-cost line (landed-cost item or clearing account). |
| `LANDED_COST_CHARGE_EXCEEDS_OPEN` | 422 | The charge is more than what is left of the bill line. |
| `LANDED_COST_CHARGE_AMOUNT_INVALID` | 422 | A charge amount must be greater than zero. |
| `LANDED_COST_DUPLICATE_CHARGE` | 422 | The same bill line is added twice. |
| `LANDED_COST_WEIGHT_MISSING` | 422 | Spreading by weight needs a weight on every item (names listed). |
| `LANDED_COST_VOLUME_MISSING` | 422 | Spreading by volume needs a volume on every item (names listed). |
| `LANDED_COST_BASIS_ZERO` | 422 | The receipt lines have nothing to spread by (all zero). |
| `LANDED_COST_MANUAL_MISMATCH` | 422 | Manual shares must add up to the charge. |
| `LANDED_COST_PDF_NOT_ALLOWED` | 422 | Only posted or reversed landed costs print. |
| `DOCUMENT_HAS_LANDED_COST` | 422 | A posted landed cost uses this receipt or bill; reverse the landed cost first. |
| `ITEM_LANDED_COST_MUST_BE_SERVICE` | 422 | Only items that do not track stock can be landed-cost items. |
| `ITEM_FIXED_ASSET_MUST_BE_SERVICE` | 422 | A fixed-asset item cannot track stock and cannot also be a landed-cost item. |

## Budgets

| Code | HTTP | Meaning |
| --- | --- | --- |
| `BUDGET_NOT_DRAFT` | 422 | Only a draft budget can be changed, filled or deleted. |
| `BUDGET_ACCOUNT_INVALID` | 422 | The account is missing, inactive, or a group. Budgets are entered on active posting accounts. |
| `BUDGET_MONTH_OUT_OF_RANGE` | 422 | The month is not one of the twelve months of the budget's fiscal year. |
| `BUDGET_ALREADY_APPROVED_FOR_YEAR` | 422 | An approved budget already exists for that fiscal year and branch. |
| `BUDGET_IMPORT_INVALID` | 422 | The spreadsheet could not be read, or one or more rows are invalid (`details.rows`). |
| `REPORT_BUDGET_REQUIRED` | 422 | Budget vs actual report: choose a budget (`budget_id`). |

## Fixed assets

| Code | HTTP | Meaning |
| --- | --- | --- |
| `FIXED_ASSET_CATEGORY_IN_USE` | 409 | The category is used by an asset or an item. |
| `FIXED_ASSET_ACCOUNT_INVALID` | 422 | A category account is missing, inactive, a group, or the wrong type. |
| `FIXED_ASSET_NOT_DRAFT` | 422 | Only a draft asset can be activated or deleted. |
| `FIXED_ASSET_NOT_ACTIVE` | 422 | The asset is not in service. |
| `FIXED_ASSET_SALVAGE_TOO_HIGH` | 422 | Salvage is higher than cost, or higher than the current book value. |
| `FIXED_ASSET_OPENING_TOO_HIGH` | 422 | Depreciation already taken is higher than cost. |
| `FIXED_ASSET_CATEGORY_INVALID` | 422 | Choose an active asset category. |
| `FIXED_ASSET_HAS_DEPRECIATION` | 422 | A purchase invoice cannot be reversed after one of its assets was depreciated or disposed. |
| `FIXED_ASSET_RETURN_NOT_SUPPORTED` | 422 | Dispose of the asset. A debit note cannot return it. |
| `FIXED_ASSET_QUANTITY_TOO_LARGE` | 422 | One invoice line cannot create more than 500 asset cards. |
| `FIXED_ASSET_DISPOSAL_DATE` | 422 | The disposal date is in the future, or depreciation is already posted after it. |
| `FIXED_ASSET_DISPOSAL_AMOUNT` | 422 | The sale amount cannot be negative. |
| `FIXED_ASSET_CUSTOMER_REQUIRED` | 422 | A sale on account needs a customer. |
| `FIXED_ASSET_CASH_ACCOUNT_INVALID` | 422 | The cash or bank account is missing, inactive, or not in the primary currency. |
| `FIXED_ASSET_NOT_DISPOSED` | 422 | The asset has no disposal to reverse. |
| `FIXED_ASSET_LATER_DEPRECIATION` | 422 | A depreciation run after the disposal must be undone first. |
| `DEPRECIATION_ALREADY_POSTED` | 422 | That month already has a posted depreciation run. |
| `DEPRECIATION_PREVIOUS_MONTH_MISSING` | 422 | An earlier month still has depreciation to post. |
| `DEPRECIATION_NOT_LATEST` | 422 | Only the latest depreciation run can be undone. |
| `DEPRECIATION_BRANCH_REQUIRED` | 422 | Undoing depreciation needs the branch whose run is undone. |
| `DEPRECIATION_NOTHING_DUE` | 422 | No asset has depreciation due for that month. |

## Document emails

| Code | HTTP | Meaning |
| --- | --- | --- |
| `EMAIL_NOT_CONFIGURED` | 422 | The mail server or sender address is missing (Settings → Email). |
| `EMAIL_SENDING_DISABLED` | 422 | The mailbox is filled in but "Send emails" is turned off (Settings → Email). |
| `EMAIL_DOCUMENT_NOT_SENDABLE` | 422 | The document can't be emailed in its current status (same rule as printing: no drafts or cancelled documents). |
| `EMAIL_TEST_FAILED` | 422 | The test email was refused; `message` holds the mail server's reason. |
| `EMAIL_HOST_NOT_ALLOWED` | 422 | The port is not a mail port (25, 465, 587, 2525) or the server address is internal. |
| `EMAIL_NO_RECIPIENT` | 422 | No recipient address was given. |

## Scale barcodes (weighed / priced items)

Translated messages take `params` from the response `data.params` (see `X-Error-Params`).
Warnings that don't block the line come back in `data.scale.warnings` (`SCALE_LABEL_PRICE_DIFFERS`, `SCALE_LABEL_BEST_BEFORE_PASSED`).

| Code | HTTP | Meaning |
| --- | --- | --- |
| `SCALE_BARCODE_UNKNOWN_PLU` | 404 | A scale format read the label, but no item sold at the scale has that PLU / GTIN (`plu`). |
| `SCALE_BARCODE_ITEM_NOT_SOLD` | 422 | The item on the label is not for sale (`item`). |
| `SCALE_BARCODE_ITEM_UNIT_MISSING` | 422 | The item has no unit matching the format (`item`, `unit`). |
| `SCALE_BARCODE_NO_UNIT_PRICE` | 422 | A price label needs the item's selling price to work out the weight (`item`). |
| `SCALE_BARCODE_UNKNOWN_CURRENCY` | 422 | The GS1 393n currency on the label is not set up (`currency`). |
| `SCALE_BARCODE_NO_RATE` | 422 | No exchange rate from the primary currency to the label currency (`currency`). |
| `SCALE_BARCODE_ZERO_VALUE` | 422 | The label weight / price / count is zero. |
| `SCALE_BARCODE_WEIGHTS_DISAGREE` | 422 | The label prints a kg and a lb weight that don't match (`kg`, `lb`). |
| `SCALE_BARCODE_EXPIRED` | 422 | The label's expiry date (GS1 AI 17) has passed (`date`). |
| `SCALE_BARCODE_UNKNOWN_LOT` | 422 | The label's lot (GS1 AI 10) doesn't exist for this lot-tracked item (`lot`, `item`). |
| `SCALE_BARCODE_FORMAT_ENGINE_MISMATCH` | 422 | GS1 symbologies need the GS1 reader; EAN / UPC / custom layouts need fixed positions. |
| `SCALE_BARCODE_FORMAT_UNIT_REQUIRED` | 422 | Weight and count formats need a unit. |
| `SCALE_BARCODE_FORMAT_POSITIONS_INVALID` | 422 | Positions missing, overlapping or past the end of the barcode. |
| `SCALE_BARCODE_FORMAT_PRICE_CHECK_INVALID` | 422 | A price check digit only works with a 4- or 5-digit price. |
| `SCALE_BARCODE_PREFIX_RESERVED` | 422 | EAN-13 codes starting with 200 are this system's in-store barcodes. |
| `SCALE_BARCODE_FORMAT_OVERLAP` | 422 | Another active format reads the same codes in a shared branch (`format`). |
| `ITEM_SCALE_NOT_SOLD` | 422 | Only items that are sold can be sold at the scale. |
| `ITEM_SCALE_PLU_REQUIRED` | 422 | Items sold at the scale need a PLU made of digits only. |
| `ITEM_SCALE_NO_SERIALS` | 422 | Items sold at the scale can't track serial numbers. |
