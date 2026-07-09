# Production Studio Future AI Architecture

Production Studio includes provider-ready database structures. Phase 2 adds controlled provider implementations for two separate job families:

- fal.ai for image generation.
- OpenAI for text/vision analysis, structured JSON, and optional image generation.

fal.ai remains the default image generation provider. OpenAI image models are available as explicit higher-cost alternatives in the Studio model selector when configured in Admin.

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

The current image provider contract is `App\Contracts\AiImageProvider`.

Required image provider methods:

- `isAvailable`
- `listSupportedModels`
- `supportsCapability`
- `estimateCost`
- `submitGeneration`
- `pollGeneration`
- `downloadOutput`

The first implementation is `App\Services\Ai\FalImageProvider`.

The text/vision provider contract is `App\Contracts\AiTextVisionProvider`.

Required text/vision provider methods:

- `isAvailable`
- `analyzeImagesToJson`
- `extractScenesToJson`
- `improveSceneToJson`
- `testConnection`

The first implementation is `App\Services\Ai\OpenAiTextVisionProvider`.

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

Initial OpenAI model registry:

- `gpt-4.1-mini`: vision analysis, structured JSON extraction, and prompt enhancement

OpenAI models are managed through Admin. Controllers and Blade views request a model by capability instead of hardcoding a model name.

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

Text/vision structured jobs use the same `scene_generation_jobs` audit trail for traceability, but they do not create generated image assets. They store:

- provider and model snapshot
- capability, such as `vision_to_text` or `scene_extraction`
- prompt snapshot or input summary
- structured JSON result metadata
- token usage and cost metadata when available
- sanitized failure reason if validation or provider calls fail

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

Costs should be recorded per generated output or text/vision task when possible, then summarized at project level if reporting is needed.

Keep cost categories separate:

- fal image generation cost
- OpenAI text/vision analysis cost
- total AI cost

## Security Rules

- Do not expose child images publicly.
- Do not put API keys in code, config files committed to Git, prompts, logs, or activity records.
- Keep provider secrets in encrypted Admin credentials or managed secrets.
- Store input and output asset paths in private storage unless explicitly approved for public delivery.
- Restrict job and asset views to authorized Studio users.
- Send child photos to OpenAI only when an authorized admin explicitly selects them for analysis.
- Prefer server-side base64 image payloads for OpenAI child-photo analysis so permanent public URLs are not created.
- Never log OpenAI image payloads or Authorization headers.

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

OpenAI text/vision uses the Responses API for structured JSON. OpenAI image generation uses a separate image-provider implementation, so the text/vision adapter and image-generation adapter stay independent.

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

OpenAI prompt snapshots may include sanitized story/scene/profile text and selected child-photo indexes. They must not include base64 image payloads or credentials.

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

OpenAI token usage, when returned by the provider, is stored in job metadata. If an exact cost cannot be calculated from model pricing, the job should mark the cost source as estimate or unavailable rather than mixing it with fal image-provider actuals.

## Adding Future Providers

To add Grok, Imagen, Replicate, or another image provider:

1. Create a class implementing `App\Contracts\AiImageProvider`.
2. Add the provider and allowed model codes to `App\Support\Ai\SupportedProviderRegistry`.
3. Register provider/model rows in `ai_providers` and `ai_models`.
4. Extend `AiProviderManager` to return the provider for the new driver.
5. Configure credentials from Admin after deployment.
6. Keep controller and Studio business logic unchanged.

To add a new text/vision provider:

1. Create a class implementing `App\Contracts\AiTextVisionProvider`.
2. Add the provider and allowed model codes to `App\Support\Ai\SupportedProviderRegistry`.
3. Register provider/model rows in `ai_providers` and `ai_models`.
4. Extend `AiProviderManager::textVisionProvider`.
5. Configure encrypted credentials from Admin.
6. Keep Production Studio controllers working through the provider contract.

## Admin-Managed Provider Configuration

Provider runtime configuration is database-backed and adapter-based:

- `SupportedProviderRegistry` defines drivers, model codes, capabilities, and safe defaults supported by code.
- `ai_providers` stores enabled/disabled state, safe provider settings, health status, and default model mappings.
- `ai_models` stores allowed model codes, active state, capabilities, estimated costs, notes, and sort order.
- `ai_provider_credentials` stores encrypted provider secrets, including OpenAI API keys.

Admins cannot create arbitrary drivers, endpoints, callback URLs, PHP classes, shell commands, or arbitrary model IDs from the UI.

## Credential Encryption

Provider API keys are encrypted at rest with Laravel encrypted casts. They are decrypted only inside server-side provider services through `AiProviderCredentialService`.

Secrets must never be stored in:

- Blade views
- JavaScript
- queue payloads
- generation job snapshots
- logs or audit records
- provider/model settings JSON

Rotating Laravel `APP_KEY` without a migration strategy can invalidate encrypted provider credentials.

## Provider Adapter Resolution

Generation resolution now follows this order:

1. Validate Production Studio feature flag.
2. Validate provider driver exists in `SupportedProviderRegistry`.
3. Resolve adapter from `AiProviderManager`.
4. Confirm provider is active and has an encrypted credential.
5. Confirm provider is not in a failed health state.
6. Confirm requested model is active and supports the requested capability.
7. Submit through the adapter.

Legacy `.env` keys are migration fallback only. Admin-managed encrypted credentials take precedence.

## Default Model Resolution

Default model mappings are stored under:

```text
ai_providers.settings_json.default_models
```

Supported default capabilities:

- `character_sheet`
- `scene_generation`
- `cover_generation`
- `premium_retry`

Production Studio preselects these defaults but still snapshots the exact model used per generation job.

## Audit Policy

Audit logs record provider-management actions without secrets:

- credential configured/replaced/removed
- provider settings updated
- connection tested
- model updated
- defaults changed

Audit payloads must not include raw API keys, encrypted credential values, masked keys, last four values, Authorization headers, or raw provider responses.
4. Keep controller and Studio business logic unchanged.
5. Store credentials only in environment variables or secrets.
