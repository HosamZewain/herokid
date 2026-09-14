# Story language selection

Each story cart item saves `story_language`; checkout copies it to the existing
`orders.language` column. New requests without a language default to Arabic.
Existing orders are not rewritten. The public story form enables English only
when all 13 scenes contain both English variants; checkout rechecks availability.

Arabic retains the existing original/alternate mapping against `story.gender`.
English uses explicit `english_male_text_template` and
`english_female_text_template` fields independently of the story's base gender.
Supported child aliases are boy/male and girl/female. Missing gender retains
the existing Arabic original fallback; English defaults to male. Missing English
never silently falls back to Arabic. The customer form requires child gender.

Admin story saves synchronize existing snapshots using each order's language
and gender, including the existing explicit reprint policy. GET remains read-only.
Snapshot IDs, assets and order statuses are preserved. The returned text affects
the existing source revision.

## Studio language change after production

Reuse `PATCH /api/agent/orders/{orderId}/personalization` with Sanctum ability
`agent:orders.edit-personalization`, application permission `orders.update`,
base `agent`, enabled Agent API and existing acquisition/catalog restrictions.
Use an `Idempotency-Key` and send language alone in personalization:

```json
{
  "production_unit_key": "story:951",
  "personalization": {"language": "en"},
  "change_reason": "Customer requested English for reprint"
}
```

The explicit language change allows reprinting completed orders. It atomically
changes only that story order's language and refreshes its 13 snapshots, archiving
previous text and retaining scene IDs. Missing selected templates, mismatched
scene identities/counts or independently edited production text reject the entire
operation (422); the previous language and text remain unchanged. No assets,
payment, assignment or status changes are made. Existing idempotency applies.
Then GET the existing Studio order endpoint to receive the new text/revision.
Submit language changes separately from other personalization edits.

The Admin order scene-text refresh form also supports selecting language.
HeroKid Studio's desktop UI needs a language selector wired to this existing
personalization contract; this Laravel release does not modify the desktop app.

Deployment requires `php artisan migrate --force` before serving new code.
No translations are generated and no production orders are backfilled automatically.
