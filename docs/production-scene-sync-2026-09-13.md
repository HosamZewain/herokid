# Production text synchronization — 2026-09-13

## Diagnosis and approved behavior

The live Admin editor for story 77 now contains 13 populated original female texts and 13 populated alternate male texts (`story.gender=girl`). The affected story unit 734 still displays 13 order-owned historical scenes with original/male wording. Saving a story previously changed only the template, not existing order snapshots. The Agent DTO correctly prioritized the populated historical snapshot, so refreshing the client could obtain a new template timestamp/revision while receiving the same stale text. Separately, the old selector recognized only boy/girl, not male/female aliases.

Snapshots were protected from ordinary template edits and reads, but were **not absolutely immutable**: an authorized order-detail edit already called `refreshForOrder()` and updated rows in place. That broad order-detail workflow was inappropriate for a text-only repair because it also synchronizes personalization/prompt/project information.

The user subsequently explicitly requested automatic synchronization on every story save, **including completed and printed orders** so incorrectly gendered text can be reprinted. This implementation follows that newer policy; the previous text is archived rather than discarded.

## Implementation

- Central `ProductionSceneVariantResolver`: normalizes structured child/story gender; uses the existing original/opposite-variant storage convention. No name/text/photo inference.
- New and existing authorized snapshot-creation paths use that resolver. Internal original/alternate metadata remains compatible; new render context records the actual female/male/neutral variant for the Agent API.
- Admin story save synchronizes that story's snapshot-bearing non-deleted orders synchronously before returning success, including printed units. It reports blocked unit IDs/reasons. No background-job completion is required before Studio refresh.
- Text-only refresh preserves order IDs, snapshot IDs, scene ordering, identity, order/payment/ownership state, attachments and assets. Previous values are archived transactionally in `order_scene_text_snapshot_revisions`. Auto-derived Production Studio text can follow its snapshot; independently edited text is blocked for review. No GET mutation.
- Exactly 13 matching template/snapshot scene numbers are required, plus matching source-template identity. Incomplete/missing selected variants, independent production text or mismatched production scene sets fail the whole unit before any scene writes.
- Agent `child.gender` is canonical female/male (null for unknown); actual resolved `metadata.text_variant` comes from persisted context, not today's template guess. Unrefreshed historical metadata retains its legacy meaning. SHA-256 source revision includes actual returned content, preventing same-second misses and remaining stable on unchanged reads.
- A both/unspecified story keeps the existing neutral-original policy. Missing child gender selects original. New-order missing-alternate behavior retains its existing original-fallback warning; explicit synchronization refuses this fallback to avoid reporting a false repair.

## Files

- `app/Services/Stories/ProductionSceneVariantResolver.php`
- `app/Services/Stories/StoryProductionTextSyncService.php`
- `app/Services/Orders/OrderSceneTextService.php`
- `app/Services/Orders/ProductionSceneSnapshotRefreshService.php`
- `app/Http/Controllers/Admin/StoryController.php`
- `app/Http/Controllers/Admin/ProductionSceneSnapshotController.php`
- `app/Http/Resources/Agent/AgentStudioOrderResource.php`
- `app/Console/Commands/RefreshProductionSceneSnapshots.php`
- `app/Console/Commands/AuditProductionSceneSnapshots.php`
- `database/migrations/2026_09_13_150000_create_order_scene_text_snapshot_revisions_table.php`
- `resources/views/admin/orders/_scene-text-handoff.blade.php`
- `resources/views/admin/stories/edit.blade.php`
- `routes/web.php`
- `tests/Feature/ProductionSceneSnapshotSyncTest.php`
- `docs/agent-api.md`, this report, and rebuilt CSS/manifest in `public/build`.

No HeroKid Studio repository/client changes were made. No migration rewrites production orders.

## Final local verification

- Full Laravel suite through Docker: **880 passed / 7,119 assertions**.
- Focused new synchronization suite: **8 passed / 60 assertions** (included in the full suite, not additional totals).
- Browser regressions: **17 passed**, including localhost synthetic upload and Admin filters.
- Laravel Pint, `npm run build`, and `git diff --check`: passed.
- Read-only production SSH attempt using the existing account/default port failed with `No route to host`; no remote command, deployment or data write executed.

## Production handoff

Production repair has **not** been executed and the live Agent API has **not** been verified after the fix. Only read-only production Admin inspection was available; no production SSH deployment session or Agent token was available. Do not interpret passing synthetic tests as proof of a repaired live unit.

After deploying and running migrations:

1. Audit without writes: `php artisan orders:audit-production-scenes --all` (or `--story=77`). Output is unit IDs, counts, scene numbers and workflow state only.
2. For a targeted repair, use the command below, first without `--apply`. `--admin=1` must identify the responsible authorized Admin; the service checks permissions. The completed override is explicitly approved for the reported reprint workflow.
3. Alternatively, re-save the currently approved story 77 in Admin to synchronize its units. Subsequent saves automatically propagate corrections; this is not a recurring manual-backfill requirement.
4. Use the existing Studio Agent token to GET `/api/agent/studio/orders/HK-2026-8MONV9`; verify unit story:734, 13 ordered scenes, female metadata/text, unchanged snapshot IDs and changed order-level source revision. Do not print the token or full response.

```bash
php artisan orders:refresh-production-scenes story:734 --story=77 --admin=1 --reason="Approved gender text correction for reprint" --allow-completed
php artisan orders:refresh-production-scenes story:734 --story=77 --admin=1 --reason="Approved gender text correction for reprint" --allow-completed --apply
```

The reported historical scene ID `order_scene_snapshot:2120` came from the user's API evidence; live database IDs were not independently read. The repair retains existing rows instead of creating replacements.
