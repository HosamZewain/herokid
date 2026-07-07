# Production Studio Future AI Architecture

Production Studio includes provider-ready database structures. Phase 2 adds the first controlled provider implementation: fal.ai.

Fal is disabled unless `HERO_KID_PRODUCTION_STUDIO_ENABLED=true`, `FAL_ENABLED=true`, and `FAL_KEY` is present.

## Provider Abstraction

AI providers are represented by `ai_providers`.

Important fields:

- `name`
- `driver`
- `configuration_reference`
- capability flags for text-to-image, image-to-image, editing, and upscaling
- `is_active`

Provider configuration must be referenced indirectly. API keys must never be stored in source control.

Use environment variables or a secrets manager for future provider credentials.

The current provider contract is `App\Contracts\AiImageProvider`.

Required provider methods:

- `isAvailable`
- `listSupportedModels`
- `supportsCapability`
- `estimateCost`
- `submitGeneration`
- `pollGeneration`
- `downloadOutput`

The first implementation is `App\Services\Ai\FalImageProvider`.

## Model Abstraction

AI models are represented by `ai_models`.

Important fields:

- `ai_provider_id`
- `code`
- `display_name`
- `generation_capabilities_json`
- `estimated_cost_per_output`
- `is_active`

This makes providers and models swappable without changing the scene or project domain model.

Initial Fal model registry:

- `fal-ai/flux-kontext/dev`: FLUX Kontext Dev
- `fal-ai/flux-pro/kontext`: FLUX Kontext Pro

## Scene Generation Jobs

Future generation attempts should be recorded in `scene_generation_jobs`.

Job lifecycle fields include:

- project
- scene
- provider
- model
- generation mode
- prompt snapshot
- negative prompt snapshot
- input assets
- output asset path
- output metadata
- estimated cost
- actual cost
- status
- error message
- initiating user

The job record should store the exact prompt and inputs used so future outputs are traceable.

Phase 2 job lifecycle:

1. Admin submits a single generation request.
2. `scene_generation_jobs` row is created with `queued`.
3. `SubmitAiGenerationJob` submits to Fal and stores the external request/status/response URLs.
4. `PollAiGenerationJob` polls status.
5. When complete, the job downloads the generated image to private storage.
6. A versioned `production_project_assets` row is created.
7. The asset waits for review, approval, rejection, archive, or retry.

## Suggested Future Job Statuses

- `queued`
- `running`
- `succeeded`
- `failed`
- `cancelled`
- `approved`
- `rejected`

The current implementation only prepares storage. It does not enqueue jobs or call providers.

## Cost Tracking

Use `estimated_cost` before running a job and `actual_cost` after receiving provider billing or usage metadata.

Costs should be recorded per generated output when possible, then summarized at project level if reporting is needed.

## Security Rules

- Do not expose child images publicly.
- Do not put API keys in code, config files committed to Git, prompts, logs, or activity records.
- Keep provider secrets in environment variables or managed secrets.
- Store input and output asset paths in private storage unless explicitly approved for public delivery.
- Restrict job and asset views to authorized Studio users.

## Future Provider Examples

The abstraction can support:

- FLUX-style image providers
- Grok image providers
- Google Imagen
- OpenAI image generation/editing
- Replicate-hosted models
- fal.ai-hosted models
- internal renderers

Each driver should translate the Studio scene, character profile, and approved references into the provider-specific request format without changing the Studio tables.

## Future PDF and Print Automation

PDF automation should be separate from image generation.

Recommended future outputs:

- Reader-order A4 portrait preview PDF
- Print-imposed A3 landscape booklet PDF
- Print manifest with sheet order, page numbers, and flip direction
- Proof checklist asset

Automation should write versioned assets and never overwrite original order files silently.

## Prompt Snapshot Policy

Prompts are compiled by `App\Services\Ai\ProductionPromptCompiler` from structured project, scene, story, child, character profile, style preset, and manual admin notes.

Every generation job stores:

- `prompt_snapshot`
- `negative_prompt_snapshot`
- selected model/provider metadata
- selected reference photo indexes
- selected character sheet id when used

Provider API keys are never included in prompt snapshots.

## Asset Versioning Policy

Generated outputs are stored as private `production_project_assets`.

Supported Phase 2 asset types:

- `character_sheet`
- `scene_image`
- `cover_image`

Multiple versions are allowed. Approval rules:

- one primary approved Character Sheet per project
- one final approved Scene Image per scene
- one final approved Cover Image per project

Approving a new output unsets the previous primary/final flag but does not delete old versions.

## Cost Tracking Policy

Each job stores:

- estimated cost before submission
- actual cost when provider metadata is available
- `cost_source` to distinguish provider actuals from estimate fallback

Project-level totals are calculated from `scene_generation_jobs`.

## Adding Future Providers

To add Grok, Imagen, OpenAI, Replicate, or another provider:

1. Create a class implementing `App\Contracts\AiImageProvider`.
2. Register provider/model rows in `ai_providers` and `ai_models`.
3. Extend `AiProviderManager` to return the provider for the new driver.
4. Keep controller and Studio business logic unchanged.
5. Store credentials only in environment variables or secrets.
