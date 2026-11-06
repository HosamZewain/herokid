# Book page review, one bounded retry, and saved-page downloads

The owner requested another attempt for a failed page, a complete printable PDF afterward, and a way to see/download already generated pages. This continues the automation correction/publication work. The verified AWS/Main base is `db5eecc05652aebf1b9836ead1b27abc9c5e589b`; the dirty primary mobile/UI checkout is preserved. This preparation record is not deployment proof.

## Observed case and resulting behavior

Read-only production preflight on 6 November at14:13UTC confirmed HK11-155/item3173 has19of20 pages, with18 absent after `content_review`. The approved book inputs remain unchanged, the checkout remains safely paused, and the prepared staff review plan accepts page18. No live generation/recovery request was submitted.

The existing collector already gives newly collected eligible partial books one independent replacement per missing page, with original order/approval/payment/pause/worker-settlement checks. Local image-quality checks retain their existing bounded attempts. Explicit content refusals remain outside automatic retry. They now have an authorized staff review page showing the exact saved page brief and original book instructions. Staff must explicitly confirm review before requesting one new attempt for those original page descriptions under the normal content checks. No prompt substitution, safety override, new provider, API fallback or infinite retry is added.

Review requires current assigned-checkout authority, photo and production-prompt permissions, a confirmed pause, settled terminal worker evidence, no uncollected original output, no active book in the checkout, unchanged approved inputs and an actual recorded payment. An encrypted selection binds the actor, run/inputs/progress/snapshot, existing recovery state and control version. Checkout/run/control locks precede the fresh verification. The review and audit commit together; the normal recovery service admits the bounded replacement. A newer pause/control version or revoked authority fences admission/publication. The checkout hold/control state and other work are preserved.

The original run, accepted images, rejected page code and worker receipts remain. Review provenance is stored in existing recovery JSON. A failed replacement blocks further automatic attempts. A successful replacement goes through the existing source-bound, full-count assembly and verified private PDF publication. No partial print PDF is exposed through the new page.

## Pages and downloads

`GET /admin/orders/{order}/codex-scenes/{run}/pages` is permission scoped and private/no-store. It shows saved and missing page positions, specific failure reasons, numbered previews, individual original PNG downloads and a saved-images ZIP. It paginates48positions and follows a completed recovery to its full assembly. Links appear at checkout level for books on every sibling order, including inactive/needs-review runs that previously had no visible book panel.

The ZIP retains original page numbers and includes `manifest.json` with expected/saved/missing positions and completeness. It contains no storage paths or customer payload. Source PNGs pass native book/order/unit/source-mapping and SHA checks. Temporary PNGs are0600 files, consumed by ZipArchive and cleaned after close, avoiding retention of all image bytes in PHP memory. The downloaded ZIP is private/no-store and deleted after send. Existing story archive behavior remains compatible. Book thumbnails validate the same source binding; full images/downloads also verify the PNG and hash.

`POST /admin/orders/{order}/workspace/book-pages/{run}/retry-reviewed` requires all existing control/update/photo/prompt permissions and the explicit review checkbox. GETs never record approval, change status, dispatch work, or send messages.

## Validation

PHP8.3 SQLite and isolated MySQL8.4 EACH **521tests/4807assertions** pass, including11new tests for the gallery, sibling-order visibility,48-page pagination, numbered ZIP contents, byte validation, cross-order/book isolation, explicit review, stale tokens/control versions, revoked/unassigned authority, payment, uncollected output, active work, repeat clicks, retry failure and successful full PDF assembly. Outbound requests are blocked or mocked. Three independent real-commit MySQL race regressions are checked separately. Pint/PHP lint/route/Blade compilation and lockfile build pass; the build manifest/assets remain unchanged.

A synthetic native reviewed-retry flow exported a3-page complete PDF. Poppler confirms3A4pages/no JavaScript; all3pages were rendered and visually inspected, with the accepted first/third pages preserved and a distinct replacement at position2, without a missing-page placeholder. This synthetic artifact is not a customer deliverable or proof of HK11-155 completion.

## Release and recovery boundaries

No schema, config, dependency, Python worker/protocol, infrastructure, queue replay or customer message change. The recovery/collector runs in the existing fresh `codex-scenes:sync` scheduler command; no persistent worker restart is needed for this service/view release. Route and Blade caches are the only required cache refresh. Retain private environment/config, existing route cache ownership/mode, hashed assets and all worker/generator PIDs.

After reviewed recovery records exist, preserve compatible `reviewed_pages` readers and source bindings. Prefer a forward fix; old readers would block reviewed content again. No database restore, metadata erasure, media deletion or historical retry as a release test. Actual deployed SHA/hashes, live GET verification, logs and checkpoint are recorded after authorized application.
