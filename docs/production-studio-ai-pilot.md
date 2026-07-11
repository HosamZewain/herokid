# Production Studio AI Image Pilot

This pilot adds controlled AI support inside Production Studio only.

It does not change checkout, order status, customer pages, payment, delivery, PDFs, the current Story Production Prompt, or the existing Codex workflow.

Provider split:

- fal.ai is the default image-generation provider: child reference illustration, cover artwork, and scene artwork.
- OpenAI is used only for text/vision structured work: child photo analysis, story scene extraction, and visual-direction improvement.
- OpenAI can also be enabled as an optional image-generation provider for child reference, cover, and single-scene attempts when OpenAI image models are active in Admin.

## Environment

Add these variables:

```env
HERO_KID_PRODUCTION_STUDIO_ENABLED=true
FAL_ENABLED=false
FAL_KEY=
QUEUE_CONNECTION=database
```

Fal and OpenAI credentials are configured from Admin:

`Admin -> Settings -> AI Providers & Models`

For fal.ai, `FAL_KEY` is legacy fallback only during migration. Import an existing env key into encrypted database credentials with:

```bash
php artisan ai:import-provider-key fal --yes
```

Never commit provider keys. After Admin-managed fal credentials are verified, remove the legacy key from `.env`.

OpenAI does not use a runtime `.env` key. Add the OpenAI API key through Admin so it is encrypted at rest and never returned to Blade or JavaScript.

## What Can Be Generated

Phase 2 supports one item at a time:

- Character Reference Sheet
- One selected scene image
- One cover image
- Analyze Child Photos with AI
- Build or refresh scenes from a story draft
- Improve a single scene's visual direction

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

The image model selector appears beside child-reference, cover, and scene generation actions. It shows the provider, model name, and estimated per-image cost. fal.ai Dev remains the low-cost default; choose fal.ai Pro or OpenAI image models only when a higher-cost retry is intentional.

## Character Analysis With OpenAI

Use this when the Character Profile is empty or weak.

1. Configure and enable OpenAI in `Admin -> Settings -> AI Providers & Models`.
2. Enable an OpenAI model with `vision_to_text` capability, such as `gpt-4.1-mini`.
3. Open the Production Studio project.
4. In Character Profile, select only the child photos that should be sent for analysis.
5. Click `تحليل صور الطفل بالذكاء الاصطناعي`.
6. Review the generated fields.
7. Click apply only after reviewing the preview.

The selected photos are sent server-side as base64 image data. They are not copied to public storage and no permanent public URL is created.

Manual Starter remains available without OpenAI and uses local helper text only. It is not AI-generated.

## Scene Extraction With OpenAI

The Story Workspace can build scenes from a story draft.

1. The deterministic parser runs first when the story text has clear scene headings.
2. If parsing fails or the story lacks enough structured scene data, OpenAI can extract strict JSON.
3. The extracted scenes are shown as a preview.

## Per-order story personalization

The selected Story record remains a reusable global template and is never edited by this workflow. Production Studio creates a separate personalized scene set for the linked order.

When **Build scenes from story draft** runs, Studio first inspects repeated protagonist names and role phrases such as `الأميرة جنا`. If deterministic detection is uncertain, or the template hero gender differs from the order child, the configured OpenAI `scene_extraction` model performs structured detection and conservative gender adaptation. The preview shows the detected template hero, confidence, supporting characters, target child, and warnings before anything replaces the current Studio scenes.

After confirmation, only the Production Studio scene fields are personalized. Each scene keeps `original_template_data_json` for traceability while its working text, visual direction, pose, environment, and continuity fields use the order child's name. The global Story and the original order data remain unchanged.

Image generation is blocked when a scene is not marked personalized, does not identify the order child as the hero, or still contains the old template hero in a main scene field. Supporting character names are preserved. A prompt snapshot records the template hero, child hero, whether personalization was applied, and whether an old hero conflict remains.

Recommended workflow:

1. Create a Studio story draft.
2. Build scenes from the draft.
3. Review the detected template hero and supporting characters.
4. Confirm personalization with the order child's name.
5. Review the 13 personalized scenes and resolve any conflict badge.
6. Analyze the child photos and complete the Character Profile.
7. Generate and approve the child reference illustration.
8. Generate one personalized scene image at a time.
4. Existing scene rows are replaced only after explicit confirmation.

The expected scene fields include written text, visual direction, child action/pose, environment, lighting, supporting characters, key objects, continuity notes, safe text-area notes, and educational value.

## Improve Visual Direction

Each scene row can call OpenAI to improve production fields:

- visual direction
- child action/pose
- environment
- mood/lighting
- supporting characters
- key objects
- continuity notes
- safe text-area notes

The improvement is previewed first and applied only after confirmation.

## Review and Approval

Generated assets are versioned. New outputs never overwrite old outputs.

Character Sheets:

- multiple versions are allowed
- only one can be the primary approved Character Sheet

Scene Images:

- multiple versions are allowed for a scene
- approving one final image unsets the previous final image for that scene
- scene generation enables Identity Lock by default
- Image 1 is always the original primary face reference and is authoritative for facial identity
- Image 2 is the approved child reference illustration and is secondary for art consistency only
- optional body and style references are not sent automatically while Identity Lock is enabled
- the admin may request one or two independently billed variants
- `Draft` uses medium quality and `Final` uses high quality when supported by the selected provider/model
- when OpenAI vision is configured, a private structured identity-consistency review is queued after a scene image is created
- a failed identity review blocks approval until the image is corrected or regenerated

### Identity Correction

Each generated scene asset has an optional `تصحيح هوية الطفل` action. It creates a new version instead of overwriting the source image.

- Image 1: original primary face reference
- Image 2: generated scene to preserve
- the correction prompt asks OpenAI to preserve composition, environment, lighting, action, supporting characters, and key objects while correcting the child identity
- high quality is the default for correction; medium remains available for a cheaper draft retry
- automatic face masks are not fabricated because the current stack has no reliable face-region detector; this is a constrained full-image edit followed by identity review

Identity review is a production consistency check, not biometric identification. Human approval remains mandatory.

Cover Images:

- multiple versions are allowed
- approving one final cover unsets the previous final cover

## Privacy

- Original child photos remain private.
- Generated child images are stored on the private `local` disk.
- Generated assets are served only through authenticated admin routes with Production Studio AI review permission.
- Generated assets are not written to `public/storage`.
- Input child images are sent to the selected configured image provider only through server-side private requests initiated by an authorized Studio user.
- OpenAI identity review receives the primary face reference and generated asset as server-side base64 inputs; image payloads and private paths are never stored in prompts, logs, or browser data.
- API keys are redacted from stored error messages.

## Cost Tracking

Before image generation, each fal job stores an estimated cost based on the selected model.

After fal generation:

- if Fal returns actual cost metadata, it is saved as `provider_actual`
- otherwise the estimate is saved as `estimate_fallback`

OpenAI text/vision jobs store their provider, model, capability, status, token usage when returned, and estimated/actual cost when calculable. These costs are tracked separately from fal image generation costs so image cost and text/vision analysis cost are not mixed incorrectly.

The Studio overview shows AI totals only to users with `production_studio.ai_view_costs`.

## Hostinger Queue Setup

Use database queues on shared hosting:

```env
QUEUE_CONNECTION=database
```

Use the existing Hostinger wrapper cron instead of a long direct command:

```bash
* * * * * /bin/bash /home/u470070883/run-herokid-queue.sh
```

Recommended `/home/u470070883/run-herokid-queue.sh` content:

```bash
#!/bin/bash
APP_DIR="/home/u470070883/domains/hero-kid.com/public_html"
PHP_BIN="/usr/bin/php"

cd "$APP_DIR" || exit 1
mkdir -p storage/logs

$PHP_BIN artisan schedule:run >> storage/logs/scheduler.log 2>&1
$PHP_BIN artisan queue:work --queue=default --stop-when-empty --tries=1 --timeout=300 >> storage/logs/queue.log 2>&1
```

Manual fallback:

```bash
cd /home/u470070883/domains/hero-kid.com/public_html
php artisan queue:work --queue=default --stop-when-empty --tries=1 --timeout=300
```

Do not assume Supervisor exists on shared hosting. The queue command intentionally omits the `database` connection argument because `QUEUE_CONNECTION=database` should be configured in `.env`.

## Rollback

To disable AI generation while keeping Studio:

```env
FAL_ENABLED=false
```

To disable OpenAI text/vision actions, disable the OpenAI provider or its models from Admin, or remove the encrypted credential. This does not affect fal image generation.

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
