# Public story library — website and mobile API

Independent free stories, not orders, personalized products, private customer
PDFs or the purchased/delivered-story library. No existing customer content is
imported or made public. No checkout/production/approval changes. Mobile source
is intentionally unchanged; the mobile agent implements screens using this API.

## Admin

`/admin/story-library`, sidebar Catalog → Story library. Permissions:
`story_library.view` for listing and inspecting PDFs;
`story_library.manage` plus view for upload/edit/publish/delete. The migration
registers these keys and grants them only to active direct permission managers;
other staff need explicit grants through existing permissions administration.

Fields: title, optional description/category/cover/slug, Arabic/English,
RTL/LTR, optional age range, display order and draft/published. PDF required on
creation; replacement optional. PDF <=50 MiB, <=200 pages, valid unencrypted
PDF accepted by the existing mPDF/FPDI parser. Cover JPEG/PNG/WebP <=4 MiB,
<=5000×5000 pixels. PHP/hosting web upload_max_filesize must be >=50M and
post_max_size high enough for PDF+cover+fields (e.g. 64M); the application cannot
override host-level limits. No new runtime packages required.

Publishing requires an explicit public-content/rights confirmation. Drafts and
deleted/unpublished entries and their PDF/cover routes return 404 for guests and
API clients. All files use configured private persistent disk, never the public
media library. Random storage names; no client-supplied paths/disks. File-size,
PDF signature, actual page-count/parser, image MIME/dimension checks run before
save. Rejected uploads leave existing records/files unchanged. Replaced files
and soft-deleted entries are retained privately, not physically purged; deletion
requires confirmation and reason and is audited. Public PDFs are accessible to
everyone; hiding a download button is NOT DRM or a guarantee against copying.

## Website

- `/story-library`: paginated catalog, search q, exact category, language ar/en,
  optional integer age (0–18), 24/page. Only published entries.
- `/story-library/{slug}`: details and read button.
- `/story-library/{slug}/read`: existing interactive PDF reader with library
  labels and return-to-details, RTL/LTR, thumbnails and fullscreen. Mobile library
  reading opts into a full-width single page; private preview layout is unchanged.
- `/story-library/{slug}/document`: original PDF, inline with HTTP Range support.
- `/story-library/{slug}/cover`: validated cover image when provided.

Header/footer links included. No-store/revalidation on these routes prevents
stale shared-cache exposure after unpublishing; a downloaded copy cannot be
revoked. Original private booklet-preview token/session gates remain unchanged.

## Mobile-agent contract

Public base `https://hero-kid.com/api/v1`, no Bearer token required:

`GET /story-library?q=...&category=...&language=ar&age=7&page=1`

Response: `data[]` plus `meta.current_page`, `last_page`, `per_page` (24),
`total`; `filters.categories` and `filters.languages` provide available choices.
Category values are strings: send the exact returned value, URL-encoded.

`GET /story-library/{slug}` returns `data` with the same public story fields:
id (UUID), slug, title, description, category, language, reading_direction,
age_min/age_max, page_count, file_size_bytes, cover_url, web_url, reader_url,
document_url, version (PDF checksum), updated_at. No internal path, disk, staff
identity or private order/customer fields. `GET /story-library/{slug}/document`
serves PDF bytes, not JSON; supports Range requests. URL is not a signed temporary
URL and does not need cookies, tokens or the private preview grant.

Build browse/filter/detail/reader entry points independently from purchase and
personalization. Either embed the public `reader_url` with normal HTTPS/default
security or use the mobile PDF reader against `document_url`. Honor reading
direction, do not ask login for this library, paginate lazily and load covers
lazily. Key document cache by id+version and revalidate availability; handle 404
(unpublished/deleted), unavailable files and offline errors without app crashes.
The version hash changes on PDF replacement; metadata updated_at can change
without PDF changes. Do not reuse order IDs or unlock private customer PDFs.

## Release

Reviewed additive migration `2026_10_15_000200_create_public_story_library` creates
one table and the two permission records/grants. Deploy-main only permits this
and the already reviewed push migration, runs code/.env/database backup first,
migrates while in maintenance and rebuilds caches/restarts workers. No content
is seeded or published in production. No real PDF/child content is used in tests.
After deployment verify guest catalog/API, permission navigation and one
authorized non-personal sample upload/publish/read/unpublish cycle. Do not use
real customer stories as a smoke test.

## Verification (local isolated QA, 2026-10-15)

- 28 focused PHP feature tests / 286 assertions: library upload, publication,
  pagination/filtering, permissions, invalid/encrypted/oversize PDFs, replacement,
  soft deletion, API fields and PDF Range; existing private booklet previews,
  navigation and mobile-admin regressions included.
- Four deployment-preflight tests passed, production assets built, routes and
  Blade templates cached successfully on the disposable SQLite QA instance.
- Browser checks: admin publication, guest browsing/details/reader, page flips
  and thumbnails on desktop; 390x844 mobile viewport with full-width pages and
  no horizontal overflow. Public API detail and PDF 206 checked without cookies.
- Physical iPhone/Android app integration is not part of this backend release;
  the mobile agent must test the real app after wiring these public endpoints.
- Production checks/content upload remain to be performed after host deployment.
