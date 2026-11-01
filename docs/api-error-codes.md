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

- `INVALID_CREDENTIALS` (HTTP 422) — also returned by central `/login` for non–super-admin accounts
- `CENTRAL_FORBIDDEN` (HTTP 403) — central admin API called by a user who is not a super admin (`ensure.super_admin`)
- `ACCOUNT_INACTIVE` (HTTP 403) — login rejected, or authenticated request by a deactivated user (`ensure.active`)
- `PASSWORD_RESET_FAILED` (HTTP 422)
- `PASSWORD_UNCHANGED` (HTTP 422) — new password matches the current password

Central tenant:

- `TENANT_NOT_FOUND` (HTTP 404)
- `TENANT_OWNER_CREATE_FAILED` (HTTP 500)

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
- `CUSTOMER_CREDIT_LIMIT_EXCEEDED` (HTTP 422) — posting an invoice / confirming an order would put the customer's balance (per currency) above the credit limit; needs `credit_limit_override,edit`. A limit of 0 means no limit.

Price lists:

- `PRICE_LIST_ITEM_UOM_MISMATCH` (HTTP 422) — a price row's unit does not belong to its item
- `PRICE_LIST_ITEM_INVALID` (HTTP 422) — negative price or minimum quantity
- `PRICE_LIST_DUPLICATE_ITEM` (HTTP 422) — same item, unit and minimum quantity twice in one list
- `PRICE_LIST_DATES_INVALID` (HTTP 422) — end date before start date


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

