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
- `REQUEST_FAILED` (fallback for unclassified errors)

Auth:

- `INVALID_CREDENTIALS` (HTTP 422)
- `ACCOUNT_INACTIVE` (HTTP 403)

Central tenant:

- `TENANT_NOT_FOUND` (HTTP 404)
- `TENANT_OWNER_CREATE_FAILED` (HTTP 500)

RBAC:

- `ROLE_SYSTEM_RENAME_FORBIDDEN` (HTTP 422)
- `ROLE_SYSTEM_DELETE_FORBIDDEN` (HTTP 422)
- `ROLE_DELETE_HAS_ASSIGNED_USERS` (HTTP 409)

Currency:

- `CURRENCY_PRIMARY_DELETE_FORBIDDEN` (HTTP 422)

Inventory:

- `UNIT_GROUP_DELETE_HAS_UNITS` (HTTP 409)
- `UOM_DELETE_REFERENCED_BY_ITEMS` (HTTP 409)
- `UOM_DELETE_REFERENCED_BY_ITEM_CONVERSIONS` (HTTP 409)
- `ITEM_BASE_UOM_REQUIRED` (HTTP 422)
- `ITEM_UOM_ALREADY_ATTACHED` (HTTP 422)
- `ITEM_BASE_UOM_INVALID_CONVERSION` (HTTP 422)
- `ITEM_BASE_UOM_DETACH_FORBIDDEN` (HTTP 422)
- `ITEM_BASE_UOM_MISSING` (HTTP 422)
- `ITEM_UOM_UNIT_GROUP_MISMATCH` (HTTP 422)
- `ITEM_BASE_UOM_CHANGE_GROUP_MISMATCH` (HTTP 422)

Customer/Supplier scope and attachments:

- `ATTACHMENT_DOWNLOAD_STORAGE_NOT_CONFIGURED` (HTTP 500)
- `CUSTOMER_ATTACHMENT_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_ATTACHMENT_SCOPE_MISMATCH` (HTTP 404)
- `CUSTOMER_CONTACT_SCOPE_MISMATCH` (HTTP 404)
- `CUSTOMER_ADDRESS_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_CONTACT_SCOPE_MISMATCH` (HTTP 404)
- `SUPPLIER_ADDRESS_SCOPE_MISMATCH` (HTTP 404)

## Rules For New Endpoints

1. Always return `code` for API errors.
2. Reuse existing codes when meaning matches.
3. Add new codes only when behavior/handling differs.
4. Keep one semantic meaning per code.
5. When adding a new code:
   - add backend usage in response envelope
   - add frontend translations under `ApiErrors.codes.<CODE>` in `messages/en.json` and `messages/ar.json`

