# HeroKid Agent API

The Agent API lets a dedicated production agent acquire and process one complete HeroKid checkout without using the Admin UI. It reuses the existing order assignment, status, prompt, private attachment, booklet preview, product preview, and activity-log services.

## Authentication

Base URL: `https://hero-kid.com/api/agent`

From the Admin Panel, open **التكاملات → Agent API Tokens** (`/admin/agent-api-tokens`). Choose a dedicated active Admin account, expiry, and one catalog scope:

- `all`: stories and production products.
- `stories`: story production only.
- `products`: product production only.

For a dedicated worker that must process one product (or a small allowed set), choose `products` and enable **تقييد هذا الـAgent بمنتجات محددة**. Select the allowed products from the token page. The restriction is embedded in the Sanctum token abilities, so it cannot be widened by changing an API request.

- Existing product tokens without selected product IDs remain allowed to process all production products for backward compatibility.
- A restricted token skips a complete checkout if any production unit is a story or a different personalized product.
- Ready-made items without a Production Prompt are not production units and do not block an otherwise eligible checkout.
- Product restrictions apply to acquisition, context, uploads, previews, rework, and completion.

Enable **السماح بتعديل وإعادة إنتاج الطلبات السابقة** only for an Agent that must correct existing orders. This adds two narrowly scoped abilities:

- `agent:orders.edit-personalization`
- `agent:orders.rework`

Existing tokens do not receive these abilities automatically. Reissue the token when rework access is required.

A limited Agent skips a complete checkout when that checkout contains any production unit outside its scope. HeroKid never partially acquires a checkout. Ready products that do not require production do not affect this decision.

The same operation is available from Artisan:

```bash
php artisan agent:token issue agent@example.com --name=production-agent --expires=90 --scope=stories

# Restrict a product worker to product IDs 12 and 19
php artisan agent:token issue agent@example.com --name=specific-product-agent --expires=90 --scope=products --product=12 --product=19

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

### Edit an existing Agent token

Admins with `agent_api.tokens.manage` can use **Edit** on `/admin/agent-api-tokens` to change an existing, non-revoked token without issuing a new secret. The edit page updates only the Sanctum token row's name, allowlisted abilities, catalog/product scope abilities, rework abilities, and expiry. The stored token hash and owner are never displayed or changed, so the same token string already configured in HeroKid Studio takes on the new abilities immediately.

The base `agent` ability is mandatory and is always retained. Order abilities can be added or removed individually. Catalog scope and selected-product restrictions continue to use `agent:catalog.*` abilities, and the rework control continues to manage `agent:orders.rework` plus `agent:orders.edit-personalization` together. Unknown ability strings and attempts to change the token owner are rejected.

Token abilities and Agent account permissions remain separate. Editing a token does not grant or remove account permissions. The page shows the current account-permission state beside each ability and warns when an enabled token ability is blocked by a missing application permission. Creation retains its existing behavior of granting the standard Agent account permissions intentionally.

Revocation remains final because Sanctum revocation deletes the token row. Revoked tokens cannot be edited, and no reversible disable state is simulated. The secret is still shown only once at creation and can never be recovered from the edit page.

Tokens issued before catalog scoping was added continue to work with both stories and products for backward compatibility. Reissue them from the Admin Panel to enforce a narrower scope.

## HeroKid Studio read-only API

HeroKid Studio uses the same Sanctum Agent token. Both Studio routes require the base `agent` ability, `agent:orders.read`, an enabled `agent_api_enabled` account, and the application permission `orders.view`. They do not acquire, assign, or mutate an order, and they do not require the checkout to be owned by the requesting Agent.

### Test connection

```bash
curl https://hero-kid.com/api/agent/studio/connection \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN'
```

```json
{
  "success": true,
  "agent": { "id": 42, "name": "HeroKid Studio Agent" },
  "abilities": ["agent", "agent:orders.read"],
  "studio_api": true,
  "api_version": "1"
}
```

### Read an order for Studio

The lookup accepts either an exact internal `orders.order_number` such as `HK-2026-XXXXXX` or the public short checkout reference such as `HK09-236`. It loads every personalized story row in the same persisted `checkout_group_key`. Ready-made and custom product rows are not serialized as stories.

```bash
curl https://hero-kid.com/api/agent/studio/orders/HK-2026-XXXXXX \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer TOKEN'
```

```json
{
  "success": true,
  "order": {
    "id": "CHK-20260909-ABC123",
    "order_number": "HK-2026-XXXXXX",
    "checkout_reference": "HK09-236",
    "status": "generating",
    "created_at": "2026-09-09T10:00:00+03:00",
    "source_revision": "sha256:..."
  },
  "production_stories": [
    {
      "production_unit_id": "story:456",
      "order_id": 456,
      "order_number": "HK-2026-XXXXXX",
      "status": "generating",
      "child": {
        "name": "ياسين",
        "age": 7,
        "gender": "boy",
        "production_data": { "interests": "العلوم" }
      },
      "story": {
        "id": 31,
        "template_id": "story:31",
        "title": "المخترع الصغير",
        "language": "ar"
      },
      "dedication": "إلى مبدعي الصغير...",
      "scenes": [
        {
          "id": "order_scene_snapshot:1001",
          "number": 1,
          "text": "بدأ ياسين مغامرته.",
          "title": null,
          "metadata": { "source": "order_snapshot" }
        }
      ],
      "metadata": {
        "scene_text_source": "نسخة الطلب المحفوظة",
        "updated_at": "2026-09-09T10:05:00+03:00"
      }
    }
  ]
}
```

Stable identifier semantics:

- `order.id` is the persisted `checkout_group_key`.
- `production_unit_id` is `story:{orders.id}` and remains tied to that exact personalized story row.
- `story.template_id` is `story:{stories.id}`.
- Scene IDs identify the database record actually supplying the text: `production_scene:{id}`, `order_scene_snapshot:{id}`, or `story_scene_template:{id}`.
- `source_revision` is a deterministic SHA-256 digest of relevant order, story, snapshot, and Production Studio scene update timestamps.

Scene text precedence is evaluated independently for every scene number: a populated latest Production Studio scene wins, otherwise a populated order-owned snapshot wins, otherwise a populated current story template is rendered as fallback. An empty or missing snapshot for one scene does not suppress that scene's template fallback, while a populated historical snapshot is never replaced implicitly. The read path does not write or refresh snapshots. Scenes are explicitly sorted by scene number and Arabic Unicode is returned unchanged. The dedication is read from the individual story order's `gift_note`.

A checkout with no personalized stories returns HTTP 200 with `production_stories: []`. Unknown order numbers return HTTP 404 with `ORDER_NOT_FOUND`. Missing/invalid credentials return `UNAUTHORIZED`; disabled Agent access, a missing `agent:orders.read` ability, or a missing `orders.view` permission return `FORBIDDEN`. Phone, email, delivery address, payment data, storage paths, product rows, and unrelated Admin notes are never returned.

## Upload a story preview from HeroKid Studio

Story preview upload is a separate production operation and does not acquire the checkout. It does not require an existing assignment, and an assignment owned by another Agent does not block a properly authorized uploader.

The existing endpoint and multipart contract remain unchanged:

```http
POST /api/agent/orders/{storyOrderId}/previews
Authorization: Bearer TOKEN
Accept: application/json
Idempotency-Key: UNIQUE_OPERATION_KEY
Content-Type: multipart/form-data

type=booklet
preview_files[]=@preview.pdf
```

Authorization requires all of the following:

- a valid Sanctum Agent token with the base `agent` ability;
- an active Admin account with `agent_api_enabled = true`;
- token ability `agent:orders.upload-preview`;
- application permission `orders.preview.upload`;
- a story catalog scope that allows the selected story production unit.

The token ability is intentionally separate from `agent:orders.read`; a read-only Studio token cannot upload previews. Standard production tokens issued from **Agent API Tokens** or `php artisan agent:token issue` already include `agent:orders.upload-preview` and grant the account `orders.preview.upload`. Existing custom or read-only tokens are not upgraded automatically; an authorized Admin can add the ability through **Edit** while keeping the same token secret, provided the Agent account already has `orders.preview.upload`.

Booklet previews are keyed by the exact story order ID, not by the checkout group. In a checkout containing multiple stories, uploading Story A creates or replaces only Story A's preview and does not modify Story B. Replacements keep the same preview record and create the next immutable preview version according to the existing booklet-preview rules.

Every request requires `Idempotency-Key`. Repeating the same request with the same key returns the saved response without creating another preview version or file. Reusing the key with different input returns `409 IDEMPOTENCY_KEY_REUSED`.

Error behavior:

- `401 UNAUTHORIZED`: missing or invalid Sanctum token.
- `403 FORBIDDEN`: disabled Agent API account, missing preview-upload ability, missing application permission, or disallowed catalog scope.
- `404 ORDER_NOT_FOUND`: unknown story order ID.
- `422 INVALID_ATTACHMENT`: missing/invalid multipart fields, a non-PDF file, an unreadable/encrypted PDF, an unsafe size/page count, or an order without a story production unit.

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

## Story identity-only workflow

Create a separate token from **Agent API Tokens**, choose **القصص فقط**, and enable **هويات القصص فقط**. This token cannot use the normal story/product production or rework endpoints.

```text
POST /checkouts/acquire-next-identity
       ↓
GET /checkouts/{reference}/identity-context
       ↓
Execute every identity_units[].identity_prompt
       ↓
POST /orders/{order}/identity-preview for every missing identity
       ↓
POST /checkouts/{reference}/complete-identity
       ↓
repeat with a fresh Idempotency-Key
```

Acquisition remains atomic for the complete checkout. A checkout containing multiple stories returns one `identity_units[]` entry per story. Products in a mixed checkout are returned only as `deferred_units[]` with `DEFERRED_DO_NOT_PROCESS_IN_IDENTITY_WORKFLOW`; no product or story-production prompt is exposed. After every story has an uploaded identity, completion moves every row in that checkout from `new` to `waiting_customer` so the checkout cannot split into conflicting statuses.

```bash
curl -X POST https://hero-kid.com/api/agent/checkouts/acquire-next-identity \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer IDENTITY_ONLY_TOKEN' \
  -H 'Idempotency-Key: identity-poll-001'

curl https://hero-kid.com/api/agent/checkouts/HK09-236/identity-context \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer IDENTITY_ONLY_TOKEN'

curl -X POST https://hero-kid.com/api/agent/orders/123/identity-preview \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer IDENTITY_ONLY_TOKEN' \
  -H 'Idempotency-Key: identity-upload-123-v1' \
  -F 'identity=@child-identity.png'

curl -X POST https://hero-kid.com/api/agent/checkouts/HK09-236/complete-identity \
  -H 'Accept: application/json' \
  -H 'Authorization: Bearer IDENTITY_ONLY_TOKEN' \
  -H 'Idempotency-Key: identity-complete-HK09-236'
```

`complete-identity` returns `IDENTITY_FILES_MISSING` until every story has an identity. A repeated successful completion is idempotent. Story checkouts missing original child photos are skipped because the Agent cannot safely generate their identity.

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

For a product-restricted token, `queue.token_product_ids` lists the enforced product IDs. The normal workflow and endpoints do not change: acquire with `POST /checkouts/acquire-next`, execute every returned product prompt, upload at least one production attachment for every unit, optionally upload previews, then call `POST /checkouts/{reference}/complete-production`. Successful completion changes the production orders to `ready_preview`.

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
