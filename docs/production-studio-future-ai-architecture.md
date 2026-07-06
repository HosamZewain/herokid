# Production Studio Future AI Architecture

Production Studio includes provider-ready database structures, but no external AI service is connected in this phase.

There are no FLUX, Grok, Google Imagen, OpenAI, Replicate, fal.ai, or other provider calls in the current implementation.

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
