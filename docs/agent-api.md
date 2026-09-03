# HeroKid Agent API

The Agent API lets a dedicated production agent acquire and process one complete HeroKid checkout without using the Admin UI. It reuses the existing order assignment, status, prompt, private attachment, booklet preview, product preview, and activity-log services.

## Authentication

Base URL: `https://hero-kid.com/api/agent`

From the Admin Panel, open **التكاملات → Agent API Tokens** (`/admin/agent-api-tokens`). Choose a dedicated active Admin account, expiry, and one catalog scope:

- `all`: stories and production products.
- `stories`: story production only.
- `products`: product production only.

A limited Agent skips a complete checkout when that checkout contains any production unit outside its scope. HeroKid never partially acquires a checkout. Ready products that do not require production do not affect this decision.

The same operation is available from Artisan:

```bash
php artisan agent:token issue agent@example.com --name=production-agent --expires=90 --scope=stories
```

The plaintext token is displayed once. Store it in a secret manager and send it as:

```http
Authorization: Bearer TOKEN
Accept: application/json
```

Revoke it with:

```bash
php artisan agent:token revoke agent@example.com --name=production-agent
```

Tokens issued before catalog scoping was added continue to work with both stories and products for backward compatibility. Reissue them from the Admin Panel to enforce a narrower scope.

All `POST` requests require a unique `Idempotency-Key` header. Retrying the identical request with the same key returns the saved response; reusing the key for different input returns `IDEMPOTENCY_KEY_REUSED`.

## Workflow

```text
POST /checkouts/acquire-next
       ↓
GET /checkouts/{HK08-151}/production-context
       ↓
Generate every production_units[] item
       ↓
POST /orders/{order}/attachments (for each production unit)
       ↓
POST /orders/{order}/previews (optional and independent)
       ↓
POST /checkouts/{HK08-151}/complete-production
       ↓
repeat
```

Acquisition is atomic for the complete `checkout_group`. Every production order in it is assigned to the same Agent and moved from `new` to `generating`. Ready products remain part of the checkout but are not production units and do not block completion.

## Endpoints

### Acquire next checkout

```bash
curl -X POST https://hero-kid.com/api/agent/checkouts/acquire-next \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Idempotency-Key: run-123-acquire'
```

Empty queue:

```json
{"success":true,"checkout":null,"reason":"NO_AVAILABLE_ORDERS"}
```

### Production context

```bash
curl https://hero-kid.com/api/agent/checkouts/HK08-151/production-context \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN'
```

The response contains a compact `production_units` list. Each unit has a stable `unit_key`, rendered prompt, required child/product fields, secure reference links, current production attachments, and preview state. Customer address and payment data are not returned.

### Upload production attachments

Use the returned `unit_key`. It is optional only when the underlying order has exactly one production unit.

```bash
curl -X POST https://hero-kid.com/api/agent/orders/123/attachments \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Idempotency-Key: run-123-story-output' \
  -F 'production_unit_key=story:123' \
  -F 'attachments[]=@story.pdf' \
  -F 'note=Final production file'
```

Accepted types are PDF, JPG, JPEG, PNG, WebP, HEIC, and HEIF; maximum 50 MB per file. Files use private storage and the existing 30-day validity.

### Upload a preview

Story booklet PDF:

```bash
curl -X POST https://hero-kid.com/api/agent/orders/123/previews \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Idempotency-Key: run-123-booklet-preview' \
  -F 'type=booklet' \
  -F 'preview_files[]=@preview.pdf'
```

Product image gallery: use `type=product_images` and one or more JPG/PNG/WebP files. Preview upload does not change order status and is not required for completion.

### Complete checkout production

```bash
curl -X POST https://hero-kid.com/api/agent/checkouts/HK08-151/complete-production \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN' \
  -H 'Idempotency-Key: run-123-complete'
```

Every production unit must have at least one production attachment. The existing status service moves all production orders to `preview_uploaded`. A repeated successful completion is safe.

## Errors

Errors use a stable JSON shape:

```json
{"success":false,"error":"PRODUCTION_FILES_MISSING","message":"Required production files have not been uploaded for every production unit.","details":{}}
```

Codes include `CHECKOUT_NOT_FOUND`, `ORDER_NOT_FOUND`, `ORDER_ALREADY_ACQUIRED`, `INVALID_ORDER_STATUS`, `ORDER_NOT_ACQUIRED_BY_AGENT`, `PRODUCTION_CONTEXT_INCOMPLETE`, `INVALID_ATTACHMENT`, `PRODUCTION_FILES_MISSING`, `IDEMPOTENCY_KEY_REQUIRED`, `IDEMPOTENCY_KEY_REUSED`, `REQUEST_IN_PROGRESS`, `UNAUTHORIZED`, and `FORBIDDEN`.

Reference and attachment URLs require the same Bearer token and only work for the Agent currently assigned to that checkout. They never expose private storage paths.
