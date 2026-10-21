# HeroKid Studio employee API

Backend contract 1, implementing the Studio backend handoff of 21 October 2026. Desktop migration is a separate release. Keep existing Agent tokens and encrypted desktop settings until production verification and coordinated cutover succeed.

## Authentication and permissions

All requests use HTTPS and `Accept: application/json`. Staff sign in using their existing website **email or phone**, not their display name. The website has no separate username field. The request field is `login`; phone formatting uses the existing website normalization. Passwords are required. No customer or employee account is created by Studio login.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/studio/auth/login` | Password sign-in |
| GET | `/api/studio/auth/me` | Restore identity and effective capabilities |
| POST | `/api/studio/auth/logout` | Revoke this session only |
| GET | `/api/studio/settings` | Complete current settings revision |
| GET | `/api/studio/settings/assets/{assetId}` | Published private image |
| POST | `/api/studio/covers` | Submit a billable cover job |
| GET | `/api/studio/covers/{id}` | Read this employee's job status |
| GET | `/api/studio/covers/{id}/image` | Download completed private artwork |

Sign-in example:

```json
{"login":"employee@example.test","password":"<entered password>"}
```

Sanitized successful response:

```json
{
  "data": {
    "token": "<one-time employee session secret>",
    "token_type": "Bearer",
    "expires_at": "2026-10-21T14:00:00+00:00",
    "refresh_token": null,
    "user": {"id": 17, "name": "Studio employee"},
    "permissions": ["studio.access", "studio.settings.read", "orders.view", "orders.photos.view"],
    "abilities": ["studio:session", "agent", "agent:orders.read", "agent:catalog.products", "agent:catalog.product.42"]
  }
}
```

Store the bearer secret in Electron main using OS secure storage. Never expose it through renderer IPC, project JSON, logs or query parameters. Every protected request uses `Authorization: Bearer <session secret>`. `me` has the same identity/capabilities fields, without a token. Logout returns 204. Sessions expire after **120 minutes**, or earlier if the bound Agent policy expires. There is **no refresh token**: discard the session and ask for the password again after expiry. At most five live Studio sessions per employee are retained; a sixth sign-in revokes the oldest.

Invalid credentials, inactive/non-admin accounts and missing `studio.access` return the same 401 response. Five rejected attempts for the normalized identifier and IP in five minutes trigger 429 with `Retry-After`; an additional IP limit is 20 sign-in requests per minute. Successful/failed login, logout, manager revocation and policy changes are audited without passwords or full tokens.

Permission keys:

- `studio.access`: active admin employee login and continued session use.
- `studio.settings.read`: download published configuration and assets; does not grant order access.
- `studio.settings.manage`: manage the central settings page, uploads, publication and rollback.
- `studio.sessions.manage`: bind an employee's existing Agent policy and revoke Studio sessions, additionally requiring settings management on the Admin routes.
- `studio.covers.generate`: explicitly authorize billable cover generation. This is not automatically granted by the migration.

Existing permission administrators receive the new management/read/access permissions for bootstrap; ordinary staff receive nothing automatically. Grant staff access/read from the existing Admin users/roles screen. The additive migration does not activate Agent API, grant order permissions, issue Agent tokens, revoke existing tokens, create assignments or change orders.

## Existing Agent operations

Managers use `/admin/studio-settings` to bind an employee to the numeric ID of an **existing, active, non-wildcard Agent token owned by that same employee**. This record supplies the production policy, not a shared credential. Its secret is never copied or returned. An employee without a binding can sign in for settings only but cannot use Agent operations.

The new employee-owned token inherits the exact catalog/product restrictions and only canonical Agent operation abilities for which the employee currently has all required application permissions. No `*` is issued. Ephemeral Studio sessions are excluded from the legacy Agent token editor and cannot be converted there into ordinary tokens. The existing `/api/agent/studio/connection`, `/api/agent/studio/orders/{orderNumber}` and production/preview endpoints remain the production interface; their existing permissions, catalog filters, acquisition and unit checks continue unchanged. Studio sessions are denied access to `/api/v1` customer/mobile routes and other authenticated API families.

Changing the bound policy or its abilities, revoking/expiring its token, changing the employee's password, disabling the employee or removing access invalidates continued use. Removing operation permissions narrows authorization immediately. A new login is needed after policy changes; an active session is never widened. Revoking a Studio session leaves the underlying Agent token and other employees untouched.

## Complete settings and assets

Settings return 503 until a manager explicitly publishes the first complete revision. A successful snapshot is:

```json
{
  "data": {
    "schema_version": 1,
    "revision": "12",
    "checksum": "<sha256 of stored settings and asset manifest>",
    "updated_at": "2026-10-21T12:00:00+00:00",
    "settings": {
      "production_typography": {"default_font_id":"cairo","default_font_weight":400,"default_text_box_page_side":"right"},
      "export_preferences": {"default_mode":"reading-order","allow_warnings":false},
      "ui_preferences": {"show_safe_area":false,"show_bleed":false,"show_gutter":true},
      "text_style_presets": [],
      "openai_cover": {
        "enabled": false, "model":"gpt-image-2", "quality":"high", "size":"1024x1536",
        "prompt_template":"Create a front cover for {{story_title}}...",
        "coloring_book_prompt_template":"Create one coloring book front cover...",
        "use_identity_image":true, "cover_style_notes":""
      },
      "default_back_cover": {"asset_id":"4aa7dfa7-ce77-49da-a2b8-a9b03550ccdf","crop":{"focalX":0.5,"focalY":0.5,"zoom":1}},
      "brand_logo": null,
      "product_production_profiles": []
    },
    "assets": [{
      "id":"4aa7dfa7-ce77-49da-a2b8-a9b03550ccdf",
      "revision":"<content sha256>", "mime_type":"image/png", "byte_size":123456,
      "width":1200, "height":1800, "sha256":"<content sha256>",
      "download_url":"/api/studio/settings/assets/4aa7dfa7-ce77-49da-a2b8-a9b03550ccdf"
    }]
  }
}
```

`revision` is an opaque decimal string, increasing on every publication, including rollback. Snapshots use `ETag` and `Cache-Control: private, no-cache`, varying by Authorization. Authenticated conditional requests with `If-None-Match` return 304 only if the complete published asset set remains intact. Missing/corrupt assets return 503 rather than half a snapshot. Asset routes return PNG bytes, private/no-store headers and `X-Checksum-SHA256`. Unpublished/missing IDs return 404; path and URL parameters are not accepted.

The exact schema and bounds are executable in `app/Services/RemoteStudio/StudioSettingsSchema.php`. Every top-level key above is required, including empty lists and explicit nulls. Unknown keys, HTML, recognized local filesystem paths and credential patterns are rejected; snapshot JSON is limited to 2 MB. No API origin, Agent token, OpenAI credential, order/customer record or desktop path belongs in shared settings.

Mapping to desktop schema v9:

- Outer groups and their simple preferences use the snake-case keys shown above; map them to `productionTypography`, `exportPreferences`, `uiPreferences`, `openAiCover`, etc. on desktop.
- `text_style_presets` entries retain the v9 `{id,name,style,createdAt,updatedAt}` structure and camel-case style fields. Maximum 100 presets; dates use millisecond UTC ISO (`2026-10-21T12:00:00.000Z`). IDs are unique, stable alphanumeric/`_.:-` strings. Font weights: 400/500/600/700/800.
- `default_back_cover` is null or `{asset_id,crop:{focalX,focalY,zoom}}`. Focal coordinates are 0–1, zoom 1–4.
- `brand_logo` is null or `{asset_id,placement:{position,x,y,widthPercent,opacity}}`. Positions match v9 including custom; x/y 0–1, widthPercent 0.1–0.6, opacity 0.1–1. The desktop composes the official logo after artwork generation.
- `product_production_profiles` retains the v9 reusable profile structure, stable IDs, match/canvas/cut/photo/fields and camel-case property names. Maximum 500 profiles and 50 fields each. Replace each v9 local template file object with `{asset_id:"uuid"}`; `templates` has **both** `front` and `back`, explicitly null if unused. No back template for a one-sided profile. Do not include per-order overrides, coloring-page source images, child photos, cover candidates or exports. Profile field rectangles must remain inside the canvas.
- Upload local reusable assets through the manager UI, retain original stable profile/preset IDs, replace local asset objects, review the complete snapshot, then publish. No existing desktop settings or projects are overwritten automatically. The profile/preset round-trip test includes a non-empty reference example.

Uploads accept PNG/JPEG/WebP up to 15 MB, maximum edge 6000 pixels and 25 million pixels total. Files are re-encoded to PNG to remove metadata; normalized files are capped at 25 MB. Metadata reports the normalized bytes and hash. SVG, PDFs, HTML, external URLs and arbitrary paths are not accepted. Private storage is `studio_private`, never the public storage disk. Published assets remain available across old revisions for atomic staging and rollback.

The dedicated private directory also includes an Apache deny-all rule for defense in depth on shared hosting. Keep the document root on Laravel's public directory (or the existing protected forwarding configuration); never expose application storage directly through a static alias.

## Management and publication

`GET /admin/studio-settings` contains form controls for typography/export/UI/cover defaults, back-cover upload/preview/crop, logo upload/preview/placement, repeatable text presets and reusable product profiles (dimensions, sides, copies, cut, templates, photo rectangles and text fields). Operators do not edit JSON or copy asset UUIDs. Existing preset/profile IDs and values are retained; new entries get independent stable IDs. Removing an asset reference does not delete old published media. Publication history, rollback, employee policy binding and session revocation remain available. OpenAI credentials reuse the existing encrypted Admin AI-provider settings linked from this screen and are never included in snapshots. Operator settings access is API read-only; the management page requires `studio.settings.manage`.

Inline manager image uploads use the existing `POST /admin/studio-settings/assets` with web-session authentication, CSRF, multipart `file` and `Accept: application/json`. Success returns 201 with `data: {id,width,height,bytes,preview_url}` only. The private preview URL is an Admin route, not a desktop download URL. Uploads alone do not publish assets. Normal HTML uploads still redirect as before. All asset and publication validation/permissions are unchanged.

Publication and rollback require a reason and explicit confirmation. `base_revision` implements optimistic concurrency: stale updates return 409. Asset validation, revision/manifest creation, publication pointer and audit record are one database transaction. Rollback creates a new increasing revision, checks every asset again, and retains prior history. An incomplete publication or rollback leaves the last active revision unchanged.

## Server cover generation

The server retains the encrypted OpenAI credential in the existing AI-provider configuration. Generation is disabled by default. Enabling it requires an active OpenAI model approved for `cover_generation` and a configured credential; it does not assert that a model is available to a particular OpenAI account. Model/quality/dimensions must be verified with that account before enabling production use. No provider registry or existing generation defaults are changed.

`POST /api/studio/covers` accepts multipart fields:

```text
request_id=<new UUID, retained for retries>
settings_revision=12
order_number=HK10-123
production_unit_key=story:123
kind=story                         # story | coloring_book
confirmed_billable=1
reference_image=<PNG/JPEG/WebP>     # required only when use_identity_image=true
context[title]=Optional per-cover title override
context[theme]=Optional production art direction
context[child_name]=Optional product child name
context[child_age]=Optional product child age
context[child_gender]=Optional product child gender
context[dedication]=Optional dedication override
```

Context values are bounded plain text, not URLs or file paths. Title/name max 200, age/gender 40, theme/dedication 2000 characters. Story values default from the authorized production unit. Product child context must come from the desktop's selected production unit/personalization, not another child. This operation creates artwork only: it does not edit the order, assign it, mark approval, upload previews, print or collect money.

Authorization requires `studio.access`, `studio.covers.generate`, Agent read ability and existing order/photo permissions. The existing order service verifies the selected unit, catalog and product scope at admission, worker execution and result access. `kind=story` requires a story unit; `coloring_book` requires a product unit. Results belong to the requesting employee, including after re-login.

Reference images have the same 15 MB/6000-edge/25-million-pixel limits, are stored privately for the job, normalized before upstream use, and removed after completion/failure. If references are disabled, sending a child photo is rejected rather than silently uploading it. Text-only generation uses the Images generations endpoint; references use edits. Templates and credentials are resolved by the server; clients cannot choose an upstream URL or provide an API key. See the [official OpenAI image API guide](https://developers.openai.com/api/docs/guides/image-generation).

The endpoint returns 202:

```json
{"data":{"id":"<job UUID>","status":"queued","error_code":null,"message":null,"settings_revision":"12","result":null}}
```

Poll `GET /api/studio/covers/{id}` until `completed` or `failed`. On completion `result` contains MIME, bytes, SHA256, width/height, `used_identity_image`, `logo_composited:false`, settings revision and a protected relative download URL. No private disk paths or upstream response text are returned. Download within 24 hours; expiry returns 410. The existing scheduler purges only these temporary Studio cover files hourly, never order photos or shared settings assets.

One queued/running job per employee and six new jobs per employee per hour are allowed. A per-employee request UUID and canonical input hash prevent duplicate submissions; same UUID with changed inputs returns 409. Repeating an accepted/failed request never charges again. Jobs are inserted atomically with their admission record into the existing default database queue, with one attempt, a 240-second job timeout and one upstream attempt. Enabling covers requires that this queue use the application database connection and `DB_QUEUE_RETRY_AFTER` be at least 360 seconds (longer than the existing 300-second worker timeout). This setting must be reviewed by the deployment operator; existing queue defaults are not silently changed. There is no automatic retry after failure/interruption because the provider may already have billed. Failures use safe generic error codes/messages and retain an audit ID; a deliberate new request needs a new UUID. Stop polling when no job is being viewed.

## Release and cutover

Run `tests/remote-studio.phpunit.xml` on isolated SQLite or `tests/remote-studio.mysql.phpunit.xml` **only on a disposable MySQL instance** named `studio_test`. Never run RefreshDatabase against production. Regression tests cover the existing Agent endpoints and production AI paths. No live paid generation or production-order modification is part of backend QA.

The reviewed `scripts/deploy-main.sh` backs up code/environment/database, enters maintenance, fast-forwards the pinned main commit, runs only its allowlisted additive migrations including `2026_10_21_000100_create_remote_studio_settings.php`, rebuilds caches and restarts the existing queue. No dependencies or user/order/payment schemas are changed. Keep the existing scheduler/default queue worker running; do not start a new unbounded worker in HTTP.

After host deployment, verify anonymous settings/me return 401 and malformed login returns 422 (not 404). In Admin verify both sidebar visibility and direct-route permissions. Publish a validated first snapshot, configure one authorized test employee, then check sign-in, complete assets/checksums, settings 304, allowed/forbidden Agent reads and logout using that employee without changing production orders. A real billable cover needs separate operator confirmation and a non-production order fixture.

Desktop must stage and verify the entire snapshot and all asset checksums before atomically switching revisions. On 401, 403, 503, timeout, invalid JSON, missing asset or checksum failure, retain last-known-good settings and per-order overrides and show stale/offline state. A 304 retains the already verified revision. Do not remove desktop credentials/settings until secret-free generation and production operations pass coordinated QA. Roll back settings through the Admin revision history; roll back code through a reviewed release using the deployment backup. Do not roll back by dropping these tables or deleting existing Agent credentials.

### Desktop agent: download once, manual refresh

The backend contract remains **schema 1 with unchanged employee API routes**. Desktop implementation is owned by the Studio agent; this backend update edits no desktop files.

1. Sign in with the employee's website email/phone and password using `POST /api/studio/auth/login`. Keep only the short session secret in OS secure storage in Electron main. Do not ask staff for a shared Agent token or OpenAI key.
2. After login, request `GET /api/studio/settings` once. If a complete verified local cache exists for this server/account, send its ETag in `If-None-Match`. Without such a cache, request the full snapshot (do not send a remembered ETag alone).
3. For 200, validate the complete schema; download every referenced asset from its relative `download_url` with the same bearer header. Restrict requests to the trusted API origin and published asset path; do not forward credentials across redirects/origins. Verify bytes, MIME, dimensions and each asset's SHA256. Reuse already verified matching media. Stage all files before switching the local effective settings revision atomically. Do not persist remote asset URLs as local paths or expose raw filesystem/credential IPC to React.
4. Render effective settings read-only with revision, publication time, last successful sync and stale/error state. Expose **Refresh settings** using exactly the same fetch/stage flow. A 304 means retain the current verified snapshot. An Admin publication does not push to desktop: the new revision is picked up on manual Refresh or next login, not per order and not through background polling.
5. Preserve per-order/project overrides, source photos, PDFs and project directories. Do not replace the local configuration with defaults after a failed sync. On 401 discard the expired secret and require login again; cached settings do not authorize production requests. Logout revokes only that session. On 403 do not continue protected actions or widen permissions.
6. Local-only preferences (project directory, device environment, autosave, app version) remain local. Migrate reusable assets and stable profile/preset IDs deliberately; do not silently import secrets, device paths or child images. Existing installations continue using their old implementation until coordinated desktop cutover passes.

Use the server ETag as an opaque revision validator. The snapshot checksum is not the SHA256 of the HTTP JSON body: it uses the existing PHP canonical encoding of `[settings, assets]` (recursively sorted object keys, list order preserved). Asset SHA256 values apply directly to downloaded normalized PNG bytes. Do not compare `JSON.stringify(data)` against the snapshot checksum.

Desktop acceptance tests: first login/full sync; same ETag/304; Admin edit followed by manual Refresh/new revision; missing/corrupt asset and interrupted download keep the previous revision; permission denial and expired/revoked session; independent employees; retained per-order overrides; backend cover submission without shared secrets. Do not generate a paid production cover or modify a real order as part of connection tests.

## Backend verification on 21 October 2026

The final focused and regression selection passed on isolated SQLite and disposable MySQL 8.4: **194 tests and 1428 assertions on each database**. This includes 46 new Studio tests plus 148 existing Agent and production-AI regression tests. All image-provider responses were faked; no production order or live paid generation was used. The production asset build, route cache, Blade cache, focused style checks and deployment-script syntax check passed.

Browser QA on a disposable local SQLite fixture verified manager sign-in, permission-gated sidebar/direct page access, confirmed publication with a reason, increasing revision/history and rejection of a read-only employee at the management page (403). The live anonymous settings route returned 404 before host deployment. GitHub availability is not host deployment evidence; production authenticated verification and real-provider acceptance remain cutover gates.

### Admin-form follow-up verification

The follow-up form update passed **198 tests / 1458 assertions on each of isolated SQLite and disposable MySQL 8.4**, plus 16 JavaScript/default-entry and deployment-guard tests. Production build, focused PHP style, route cache, Blade cache and script syntax checks passed. Browser QA uploaded a synthetic logo through the inline manager upload, created a text preset and product profile with a template and child-name field, published revision 2 and verified preserved values after reload. No desktop code, real child media, production settings/order or paid generation was changed.

Read-only live checks on 21 October confirmed the previously released employee API exists: anonymous settings returned 401 and empty login returned 422. These checks do not establish deployment of this follow-up Admin UI; that requires the new pinned release and host/browser verification.
