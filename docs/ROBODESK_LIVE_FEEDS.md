# RoboDesk live customer knowledge

Public endpoints (GET, JSON, 30 requests/minute):

- https://hero-kid.com/api/feeds/robodesk/catalog
- https://hero-kid.com/api/feeds/robodesk/guide

Both use Laravel length-aware pagination, 50 articles per page, with top-level
`data` and `next_page_url`. Follow the latter until null. Catalog pagination is
SQL `paginate(50)`, ordered by type and primary key before serialization. Guide
articles are assembled from DB categories, ages, package rules, FAQ and CMS,
sorted by their public IDs and paginated with the same Laravel paginator.
No model serialization, private prompts, cost, stock quantities, or credentials
are exposed. Article IDs are public type/slug identifiers, not order/customer IDs.

## Sources and update behavior

Prices reuse StoryPricingService and Product::effectivePriceCents, including
variant overrides and active sale dates. Package comparisons include current
eligible story prices and fixed-product quantities/variants. When story prices
vary, a comparison range is shown instead of inventing one saving for every
selection. Package sale prices are never modified by this feature.

`Story::hero_gender` is a read/write alias of the existing admin-maintained
`gender` column: boy/girl/both maps to boy/girl/any. This deliberately avoids a
second conflicting gender field; storefront filtering keeps using the same data.
Named characters are not used to guess or overwrite suitability. Admin's
existing "مناسبة لـ" selector is the source of truth.

Server-side page caches expire after 900 seconds. Model saves/deletes invalidate
both feeds. Pivot memberships are hashed on each request so sync/detach also
invalidates affected articles. There is no public cache-clear endpoint. Direct
bulk SQL changes that bypass model events are visible no later than cache expiry.
Client/CDN caching is disabled. Time-based offers are reevaluated within 15 minutes.

Site settings contain the editable customer service policy fields. FAQ answers
support `{{policy:delivery}}` and `{{package_price:package-slug}}`; public HTML,
FAQ structured data, and the feed render them identically. Photo policy supports
`{{photo_min}}`, `{{photo_max}}`, `{{photo_mb}}`, `{{photo_formats}}`, derived from
the same upload configuration as the forms. Never enter secrets into public CMS
or product descriptions.

## Approved source corrections / deployment

`php artisan robodesk:prepare-knowledge` previews the correction plan without
writes. `--apply` runs the approved one-time public-content changes and saves a
private before/after JSON record under `storage/app/private/robodesk-content` on
the local disk (actual local disk root may add a private prefix). Existing order,
payment and selling-price records are untouched. Re-running after completion is
a no-op, preserving subsequent admin edits. Do not clear the revision marker to
force a rerun. A full database backup precedes the command in deploy-main.sh.

Corrections cover FAQ package prices/language/cover question, common deposit and
delivery rules, removal of the automatic photo-deletion promise, six activity
age filters, the conflicting school-item descriptions/features, and one-child
package descriptions. Fractional storefront amounts retain their cents.

The public feeds return 503 in production until source preparation completes.
Deployment uses the existing pinned main release script, which runs preparation
while the site is in maintenance. There are no schema migrations or payment
changes in this release. Do not replace the host's checkout with a divergent
branch; the deployment script stops if deployed changes would be omitted.

After deployment run:

```sh
/usr/bin/php scripts/check-robodesk-feeds.php
```

This reads every public page, checks the exact item shape, duplicate IDs,
published counts, forbidden editorial wording, and response times. It does not
alter a product price or create an order. Run an authorized temporary price-change
test only in an isolated test database; the feature tests verify dependency
invalidation without modifying live catalog prices.

Live counts are only confirmed by the post-deploy check, not by fixture counts.
The reference export had 83 stories, 26 products and 7 packages; current publishing
status can legitimately change those numbers.

## Pre-deploy verification (2026-10-28)

- 156 focused Laravel feature tests / 3,520 assertions passed against isolated
  in-memory SQLite, including catalog, packages, FAQ/CMS, checkout, mobile catalog,
  dashboard and financial-report regressions. No production database was used.
- 15 deployment-script tests passed, including backup, fast-forward and failure
  guards. Bash syntax and whitespace checks passed.
- Local HTTP/browser QA with 83 story + 26 product + 7 package fixtures returned
  116 unique catalog articles over three pages, ending at `next_page_url: null`.
  Guide fixtures returned 15 guide + 21 index articles. The slowest page measured
  0.349 seconds; this is not a production performance measurement.
- Price changes, restricted-package membership, cache expiry, HTML sanitization,
  hidden components, throttling, and one-time correction preservation are covered.
- Production URLs/counts/content remain pending the pinned release deployment and
  `scripts/check-robodesk-feeds.php` output. Fixture totals are not live totals.
