# Production Studio AI Image Pilot

This pilot adds controlled AI image generation inside Production Studio only.

It does not change checkout, order status, customer pages, payment, delivery, PDFs, the current Story Production Prompt, or the existing Codex workflow.

## Environment

Add these variables:

```env
HERO_KID_PRODUCTION_STUDIO_ENABLED=true
FAL_ENABLED=false
FAL_KEY=
FAL_DEFAULT_MODEL=fal-ai/flux-kontext/dev
FAL_DEFAULT_PREMIUM_MODEL=fal-ai/flux-pro/kontext
FAL_REQUEST_TIMEOUT=180
FAL_MAX_RETRIES=2
QUEUE_CONNECTION=database
```

Enable Fal only after adding a real key:

```env
FAL_ENABLED=true
FAL_KEY=your-secret-fal-key
```

Never commit `FAL_KEY`. It is read only from environment config and is never exposed to frontend JavaScript or stored in the database.

## What Can Be Generated

Phase 2 supports one item at a time:

- Character Reference Sheet
- One selected scene image
- One cover image

It does not support:

- all-scene bulk generation
- PDF generation
- text overlay automation
- final print-resolution export
- public/customer access

## Safe Test Generation

1. Open an order in admin.
2. Send it to Production Studio.
3. Open the Studio project.
4. In Character Profile, approve 1 to 4 child reference photos.
5. Generate a Character Sheet.
6. Run the queue worker or wait for cron.
7. Review the generated private asset.
8. Approve one Character Sheet as primary.
9. Generate one scene or one cover using the approved reference.

## Review and Approval

Generated assets are versioned. New outputs never overwrite old outputs.

Character Sheets:

- multiple versions are allowed
- only one can be the primary approved Character Sheet

Scene Images:

- multiple versions are allowed for a scene
- approving one final image unsets the previous final image for that scene

Cover Images:

- multiple versions are allowed
- approving one final cover unsets the previous final cover

## Privacy

- Original child photos remain private.
- Generated child images are stored on the private `local` disk.
- Generated assets are served only through authenticated admin routes with Production Studio AI review permission.
- Generated assets are not written to `public/storage`.
- Input child images are sent to Fal only when an authorized admin explicitly selects them in Studio.
- API keys are redacted from stored error messages.

## Cost Tracking

Before generation, each job stores an estimated cost based on the selected model.

After generation:

- if Fal returns actual cost metadata, it is saved as `provider_actual`
- otherwise the estimate is saved as `estimate_fallback`

The Studio overview shows total attempts, estimated cost, actual cost, approved outputs, rejected outputs, and failed jobs for users with `production_studio.ai_view_costs`.

## Hostinger Queue Setup

Use database queues on shared hosting:

```env
QUEUE_CONNECTION=database
```

Add this cron job in Hostinger:

```bash
* * * * * cd /home/u470070883/domains/hero-kid.com/public_html && php artisan queue:work database --queue=default --stop-when-empty --tries=2 --timeout=240 >> storage/logs/queue-worker.log 2>&1
```

Manual fallback:

```bash
cd /home/u470070883/domains/hero-kid.com/public_html
php artisan queue:work database --queue=default --stop-when-empty --tries=2 --timeout=240
```

Do not assume Supervisor exists on shared hosting.

## Rollback

To disable AI generation while keeping Studio:

```env
FAL_ENABLED=false
```

To disable all Studio routes and UI:

```env
HERO_KID_PRODUCTION_STUDIO_ENABLED=false
```

Existing orders and existing production prompts are unaffected.

## Tests

```bash
php artisan test tests/Feature/ProductionStudioAiPilotTest.php
php artisan test tests/Feature/ProductionStudioTest.php
php artisan test
```
