# Admin AI Provider Settings

HeroKid Production Studio uses a provider-adapter architecture. Admins can configure only AI providers and models that are supported by code. The admin UI cannot create arbitrary drivers, endpoints, callbacks, PHP classes, shell commands, or model IDs.

## Supported Registry

Supported providers live in `App\Support\Ai\SupportedProviderRegistry`.

Currently supported:

- `fal` / fal.ai
- `openai` / OpenAI

fal.ai models:

- `fal-ai/flux-kontext/dev`
- `fal-ai/flux-pro/kontext`

OpenAI models:

- `gpt-4.1-mini`
- `gpt-image-2`
- `gpt-image-1`

Provider roles:

- fal.ai: default image generation provider.
- OpenAI: text/vision analysis, structured JSON, and optional image generation when OpenAI image models are enabled.

Future providers must be added by code first:

1. Build the provider adapter.
2. Add the provider and model definitions to `SupportedProviderRegistry`.
3. Deploy.
4. Configure the encrypted credential from Admin.

## Admin Pages

Routes:

- `GET /admin/settings/ai-providers`
- `GET /admin/settings/ai-providers/{provider}`
- `GET /admin/settings/ai-providers/{provider}/models`

The sidebar item appears under Admin Settings as `مزودو الذكاء الاصطناعي`.

## Permissions

- `settings.ai_providers.view`
- `settings.ai_providers.manage`
- `settings.ai_providers.manage_credentials`
- `settings.ai_providers.manage_models`
- `settings.ai_providers.test_connection`
- `settings.ai_providers.enable_disable`
- `settings.ai_providers.view_costs`

Credential access is intentionally separated from normal provider/model management.

## Credential Security

API keys are stored in `ai_provider_credentials.encrypted_value` using Laravel encrypted casts. The full key is never returned to Blade, HTML, JavaScript, logs, audit records, queue payloads, or test output.

After save, admins see only:

```text
Configured: ••••••••abcd
```

The edit form never pre-fills the key. Leave the key field blank to keep the existing credential.

Important: rotating Laravel `APP_KEY` without a planned encrypted-data migration can make stored provider credentials unreadable.

## Saving, Replacing, Removing Keys

Save a new key from:

```text
Admin -> Settings -> AI Providers & Models -> Configure
```

Replacing an existing key requires explicit checkbox confirmation. Removing a key also requires explicit confirmation and immediately disables the provider. Removing credentials does not delete providers, models, historical jobs, generated assets, audit logs, or cost history.

If a key is compromised:

1. Revoke it at the provider.
2. Replace or remove it from Admin.
3. Run a connection test after replacement.
4. Review recent Production Studio generation jobs.

## Provider and Model Enablement

A provider can generate only when:

- Production Studio is enabled.
- The provider exists in `SupportedProviderRegistry`.
- The code adapter exists.
- The provider is enabled.
- A credential exists.
- The provider is not in a failed health state.
- At least one compatible active model exists.

Models can be enabled/disabled independently. Disabled models remain in history but cannot be selected for new generation.

## Defaults and Costs

Default model mappings are stored in `ai_providers.settings_json.default_models`.

Supported fal defaults:

- `character_sheet`
- `scene_generation`
- `cover_generation`
- `premium_retry`

Supported OpenAI defaults:

- `vision_to_text`
- `text_to_json`
- `prompt_enhancement`
- `scene_extraction`
- `image_analysis`
- `structured_json_generation`

Estimated model costs are stored on `ai_models` and displayed only to users with `settings.ai_providers.view_costs`.

## Connection Tests

Fal does not provide a guaranteed free image-free validation endpoint. The first test returns:

```text
A billable validation request requires confirmation.
```

When confirmed, HeroKid performs a server-side provider check through the adapter and stores only a sanitized result:

- passed
- failed
- warning

Raw provider responses and secrets are not stored.

OpenAI connection tests use a lightweight server-side text request. They do not send child images and they never expose the API key, Authorization header, raw private payloads, or credentials to the browser.

## OpenAI Setup

1. Open `Admin -> Settings -> AI Providers & Models`.
2. Configure the OpenAI provider.
3. Paste the OpenAI API key into the credential field.
4. Enable the provider.
5. Enable an OpenAI model, such as `gpt-4.1-mini`.
6. Make sure the model has the required capabilities:
   - `vision_to_text`
   - `text_to_json`
   - `prompt_enhancement`
   - `scene_extraction`
   - `image_analysis`
   - `structured_json_generation`
7. Save and run the connection test.

The key is encrypted at rest. After saving, the full key is never shown again.

OpenAI actions in Production Studio are disabled when the provider, credential, or required model capability is missing.

## OpenAI Production Studio Usage

OpenAI powers:

- `تحليل صور الطفل بالذكاء الاصطناعي`
- `بناء المشاهد من مسودة القصة`
- `تحسين التوجيه البصري بالذكاء الاصطناعي`
- optional child reference, cover, and single-scene image generation from the model selector

fal.ai remains the default image generator. OpenAI image models appear as optional paid alternatives in the model selector when they are active.

For child photo analysis, selected photos are sent from the server as base64 image data. The system does not create permanent public child-photo URLs for OpenAI.

To disable OpenAI safely, disable the OpenAI provider or model, or remove the encrypted credential. This leaves fal image generation and existing Studio projects intact.

## Legacy `.env` Import

Legacy Fal env values are fallback only during migration. Import an existing `FAL_KEY` into encrypted database credentials:

```bash
php artisan ai:import-provider-key fal --yes
```

To replace an existing Admin-managed credential from `.env`:

```bash
php artisan ai:import-provider-key fal --force --yes
```

The command never prints the key. After verifying Admin-managed generation, remove the legacy `FAL_KEY` from `.env` or leave it blank.

## Tests

```bash
php artisan test tests/Feature/AdminAiProviderSettingsTest.php
php artisan test tests/Feature/ProductionStudioAiPilotTest.php
php artisan test
```
