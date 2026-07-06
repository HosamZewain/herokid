# Production Studio

Production Studio is an isolated internal admin workspace for experimenting with order production without changing the existing HeroKid order flow.

It is optional. Orders do not enter Production Studio automatically. An admin must open an order and click `إرسال إلى استوديو الإنتاج` to create a linked project.

## What Stays Unchanged

- Checkout and order creation
- Customer-facing routes and pages
- Payment and delivery behavior
- Existing order status workflow
- Existing Story Production Prompt and copy behavior
- Existing child photo uploads and storage
- Existing admin order lists and order detail pages

The original order remains the source of truth for customer data, child data, uploaded photos, selected story, add-ons, delivery details, notes, and order status.

## Feature Flag

The module is controlled by:

```env
HERO_KID_PRODUCTION_STUDIO_ENABLED=true
```

When set to `false`:

- The sidebar menu item is hidden.
- The order detail Production Studio section is hidden.
- `/admin/production-studio` routes return 404.
- Existing Production Studio records remain in the database.
- Existing orders continue normally.

## Sending an Order to Studio

1. Open an admin order detail page.
2. Click `إرسال إلى استوديو الإنتاج`.
3. A `production_projects` row is created and linked to the original order.
4. The order status is not changed.
5. The admin is redirected to the new Studio project.

If the order already has a Studio project, the system redirects to the existing project and does not create a duplicate.

## Project Lifecycle

Supported Studio statuses:

- `draft`
- `in_progress`
- `waiting_review`
- `approved`
- `ready_for_print`
- `completed`
- `archived`
- `cancelled`

Supported stages:

- `intake`
- `story_review`
- `character_profile`
- `scene_preparation`
- `image_generation`
- `image_review`
- `layout`
- `quality_check`
- `print_ready`

These statuses and stages are independent from order statuses.

## Database Model Overview

Core tables:

- `production_projects`: isolated project linked to one order.
- `production_story_versions`: production-specific story drafts and revisions.
- `production_character_profiles`: manual child visual identity notes and approved references.
- `production_scenes`: production scene workspace.
- `production_qa_checks`: mandatory and optional QA checklist rows.
- `production_project_activity_logs`: Studio-specific project activity history.
- `production_project_assets`: future/manual asset references.

Future AI foundation:

- `ai_providers`
- `ai_models`
- `scene_generation_jobs`

The Studio keeps `source_snapshot_json` when the project is created, but live order data remains the source of truth.

## Permissions

Permissions added:

- `production_studio.view`
- `production_studio.create_from_order`
- `production_studio.manage`
- `production_studio.assign`
- `production_studio.story_edit`
- `production_studio.character_profile_edit`
- `production_studio.scene_edit`
- `production_studio.qa_review`
- `production_studio.archive`
- `production_studio.delete_or_cancel`
- `production_studio.settings`

The UI hides menu items and actions when permissions are missing. Routes enforce permissions server-side.

Child photos inside Studio are served through a Studio-specific admin route guarded by `production_studio.view`. Public users cannot access Studio data or photos.

## Story Workspace

`Create Draft from Existing Story` creates a production-specific draft version and scene workspace.

It does not update the original Story record or the public story catalog.

The current standard scene count is 13, but the scene table supports future counts.

## Character Profile

Staff can manually record:

- appearance summary
- hair details
- skin tone
- visible traits
- expression
- identity rules
- wardrobe direction
- visual style
- negative instructions
- approved reference photos
- reviewer notes

This is structured for future image production tools but does not call any AI provider.

## QA Behavior

QA items support:

- `pass`
- `fail`
- `not_applicable`
- `not_reviewed`

Mandatory failed or incomplete checks block moving a project to `ready_for_print` unless an authorized reviewer provides an override reason.

## Rollback / Disable

To disable the module without removing data:

```env
HERO_KID_PRODUCTION_STUDIO_ENABLED=false
```

To remove the module later, remove the routes, menu entry, models/controllers/views/config, and drop only the `production_*`, `ai_providers`, `ai_models`, and `scene_generation_jobs` tables. Do not modify orders, order items, stories, users, or customer data.

## Hostinger Shared Hosting Deployment

The Vite production assets under `public/build` are generated locally and committed with the deployment branch. Do not run `npm run build` on Hostinger unless Node/NPM availability has been verified for that server.

Use these commands from the application root on Hostinger:

```bash
cd /home/u470070883/domains/hero-kid.com/public_html

php artisan down || true

git fetch origin
git pull --ff-only origin codex/seo-security-order-photos

composer install --no-dev --optimize-autoloader --no-interaction

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

chmod -R 775 storage bootstrap/cache

php artisan up
```

Production Studio can be disabled without rollback by setting:

```env
HERO_KID_PRODUCTION_STUDIO_ENABLED=false
```

## Test Commands

```bash
php artisan test tests/Feature/ProductionStudioTest.php
php artisan test
npm run build
```
