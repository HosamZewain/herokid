# HeroKid Agent API

The Agent API lets a dedicated production agent acquire and process one complete HeroKid checkout without using the Admin UI. It reuses the existing order assignment, status, prompt, private attachment, booklet preview, product preview, and activity-log services.

## Authentication

Base URL: `https://hero-kid.com/api/agent`

From the Admin Panel, open **التكاملات → Agent API Tokens** (`/admin/agent-api-tokens`). Choose a dedicated active Admin account, expiry, and one catalog scope:

- `all`: stories and production products.
- `stories`: story production only.
- `products`: product production only.

Enable **السماح بتعديل وإعادة إنتاج الطلبات السابقة** only for an Agent that must correct existing orders. This adds two narrowly scoped abilities:

- `agent:orders.edit-personalization`
- `agent:orders.rework`

Existing tokens do not receive these abilities automatically. Reissue the token when rework access is required.

A limited Agent skips a complete checkout when that checkout contains any production unit outside its scope. HeroKid never partially acquires a checkout. Ready products that do not require production do not affect this decision.

The same operation is available from Artisan:

```bash
php artisan agent:token issue agent@example.com --name=production-agent --expires=90 --scope=stories

# Add --rework only when this Agent may correct existing orders
php artisan agent:token issue agent@example.com --name=production-rework-agent --expires=90 --scope=products --rework
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

All `POST` requests require a unique `Idempotency-Key` header. Retrying an operation that already changed data with the same key returns the saved response; reusing the key for different input returns `IDEMPOTENCY_KEY_REUSED`. Empty queue responses are deliberately transient, so polling with an old key can discover orders that arrived later. Agents should still generate a fresh key for each intended queue poll.

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
{
  "success": true,
  "checkout": null,
  "reason": "NO_AVAILABLE_ORDERS",
  "queue": {
    "token_catalog_scope": "products",
    "new_checkout_groups": 12,
    "eligible_now": 0,
    "already_acquired": 2,
    "without_production_units": 3,
    "outside_token_scope": 7,
    "mixed_production_status": 0
  }
}
```

The `queue` object contains counts only and never customer data. It explains why checkouts that appear as New in the Admin Panel may not be production-eligible for this token. `without_production_units` means the checkout contains no story or product with a current/historical production prompt; `outside_token_scope` means its complete production set is outside the token's stories/products scope; and `already_acquired` means another assignment already exists.

### Production context

```bash
curl https://hero-kid.com/api/agent/checkouts/HK08-151/production-context \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN'
```

The response contains a compact `production_units` list. Each unit has a stable `unit_key`, rendered prompt, required child/product fields, secure reference links, current production attachments, and preview state. The top-level `team_notes` list contains the checkout's permanent staff notes in newest-first order, including writer and Cairo timestamp. Customer address and payment data are not returned.

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

Every production unit must have at least one production attachment. The existing status service moves all production orders to `ready_preview` (جاهز للمعاينة). A staff member sends the preview to the customer and then moves the checkout to `preview_uploaded` (انتظار الموافقة). A repeated successful Agent completion is safe.

The Agent API deliberately does not expose a free-form status-change endpoint. Production completion can only perform the controlled `generating` → `ready_preview` transition.

## Correcting and reworking an existing checkout

This workflow is separate from `acquire-next`. It selects the exact short checkout reference and never takes an arbitrary queue item.

```text
POST /checkouts/{reference}/acquire
       ↓
GET /checkouts/{reference}/production-context
       ↓
PATCH /orders/{order}/personalization
       ↓
POST /checkouts/{reference}/start-rework
       ↓
generate and upload replacement files/previews
       ↓
POST /checkouts/{reference}/complete-production
```

Selecting a specific checkout does not release or modify another checkout already assigned to the same Agent. It is rejected when another user owns the requested checkout, when the token catalog scope does not cover every production unit, or when the checkout is cancelled or has reached shipment creation/shipping/delivery/return.

### Acquire the exact existing checkout

```bash
curl -X POST https://hero-kid.com/api/agent/checkouts/HK09-82/acquire \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer REWORK_TOKEN' \
  -H 'Idempotency-Key: hk09-82-acquire-v1'
```

This operation assigns the whole `checkout_group` but does not change its status. Repeating it for an assignment already owned by the same Agent is safe.

### Correct one production unit

Use the exact `order_id` and `unit_key` returned by `production-context`.

```bash
curl -X PATCH https://hero-kid.com/api/agent/orders/123/personalization \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer REWORK_TOKEN' \
  -H 'Idempotency-Key: hk09-82-data-v1' \
  -d '{
    "production_unit_key": "product:456",
    "personalization": {
      "child_name": "Adam Ahmed Mohamed",
      "school_name": "School sky light",
      "class_name": "kg2",
      "language": "en"
    },
    "change_reason": "Customer requested corrected sticker data."
  }'
```

Product fields are limited to child name, school, class, age, gender, interests, parent notes, and language. Story fields are limited to child name, age, gender, language, interests, gift note, and parent notes. Prices, quantities, products, customer contact, payment, printing, and shipping cannot be changed through this endpoint.

The existing product personalization snapshot and the mirrored order fields are updated in place. Story corrections reuse the Admin order-detail service, including scene text, prompt, linked identity, and Production Studio synchronization. Every change is audited with its old/new values and reason.

### Start the replacement production run

```bash
curl -X POST https://hero-kid.com/api/agent/checkouts/HK09-82/start-rework \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer REWORK_TOKEN' \
  -H 'Idempotency-Key: hk09-82-start-v1'
```

All production orders in the checkout move to `generating`. Existing attachments, previews, and audit history are preserved, but files uploaded before this rework run do not satisfy `complete-production`; every production unit must receive a new attachment after `start-rework`.

Retry the same request with the same `Idempotency-Key` to receive the cached result safely. Sending a different key starts a new rework run boundary—even if the checkout is already `generating`—and returns `already_started: true`; files uploaded before that new boundary will no longer count toward completion.

### Process the revision-request queue automatically

Use the dedicated queue endpoint instead of `acquire-next`:

```bash
curl -X POST https://hero-kid.com/api/agent/checkouts/acquire-next-revision \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer REWORK_TOKEN' \
  -H 'Idempotency-Key: revision-queue-20260905-001'
```

It selects the oldest eligible checkout whose complete production set is still in `revision_requested` (طلب تعديلات). The checkout is assigned atomically but remains in `revision_requested` so the Agent can read `team_notes` before starting the replacement run. A checkout owned by another user is skipped; an existing assignment to the requesting Agent is reused safely. Catalog scope still applies to the complete checkout.

Empty queue:

```json
{"success":true,"checkout":null,"reason":"NO_AVAILABLE_REVISIONS"}
```

For every returned checkout: get `production-context`, read the newest `team_notes`, apply only the requested personalization corrections when needed, call `start-rework`, generate and upload replacement attachments/previews, call `complete-production`, then call `acquire-next-revision` again with a fresh idempotency key. The same Agent may hold multiple checkout assignments; an unfinished assignment does not prevent this explicit revision queue from selecting another eligible checkout.

### Repairing completions created before `ready_preview`

First preview the exact checkout and order-record counts:

```bash
php artisan agent:repair-ready-preview
```

Then apply the correction:

```bash
php artisan agent:repair-ready-preview --apply
```

The command only selects checkouts recorded by `agent.checkout_production_completed` whose latest order-status log is the original Agent completion into `preview_uploaded`. It skips manually updated or subsequently changed orders and is safe to run again.

## Errors

Errors use a stable JSON shape:

```json
{"success":false,"error":"PRODUCTION_FILES_MISSING","message":"Required production files have not been uploaded for every production unit.","details":{}}
```

Codes include `CHECKOUT_NOT_FOUND`, `ORDER_NOT_FOUND`, `ORDER_ALREADY_ACQUIRED`, `CHECKOUT_NOT_REWORKABLE`, `INVALID_ORDER_STATUS`, `ORDER_NOT_ACQUIRED_BY_AGENT`, `PRODUCTION_CONTEXT_INCOMPLETE`, `INVALID_PERSONALIZATION`, `INVALID_ATTACHMENT`, `PRODUCTION_FILES_MISSING`, `IDEMPOTENCY_KEY_REQUIRED`, `IDEMPOTENCY_KEY_REUSED`, `REQUEST_IN_PROGRESS`, `UNAUTHORIZED`, and `FORBIDDEN`.

Reference and attachment URLs require the same Bearer token and only work for the Agent currently assigned to that checkout. They never expose private storage paths.
