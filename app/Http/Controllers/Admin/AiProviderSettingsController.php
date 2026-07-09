<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Services\Ai\AiProviderAvailability;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderRegistrySyncer;
use App\Support\AdminActivityLogger;
use App\Support\Ai\SupportedProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use RuntimeException;

class AiProviderSettingsController extends Controller
{
    public function __construct(
        private readonly AiProviderRegistrySyncer $syncer,
        private readonly SupportedProviderRegistry $registry,
        private readonly AiProviderCredentialService $credentials,
        private readonly AiProviderAvailability $availability,
        private readonly AiProviderManager $providers,
    ) {}

    public function index()
    {
        $this->syncer->sync();

        $providers = AiProvider::query()
            ->with(['credential', 'models'])
            ->orderBy('display_name')
            ->get()
            ->filter(fn (AiProvider $provider): bool => $this->registry->supportsProvider($provider->driver))
            ->values();

        return view('admin.settings.ai-providers.index', [
            'providers' => $providers,
            'registry' => $this->registry,
            'availability' => $this->availability,
            'credentials' => $this->credentials,
        ]);
    }

    public function edit(AiProvider $provider)
    {
        $this->guardSupported($provider);
        $provider->load(['credential', 'models']);

        return view('admin.settings.ai-providers.edit', [
            'provider' => $provider,
            'definition' => $this->registry->provider($provider->driver),
            'credential' => $provider->credential,
            'credentials' => $this->credentials,
            'availability' => $this->availability,
        ]);
    }

    public function update(Request $request, AiProvider $provider)
    {
        $this->guardSupported($provider);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'default_timeout_seconds' => ['required', 'integer', 'min:10', 'max:600'],
            'default_max_retries' => ['required', 'integer', 'min:0', 'max:5'],
            'api_key' => ['nullable', 'string', 'min:10', 'max:500'],
            'confirm_replace_credential' => ['nullable'],
        ]);

        if ($request->filled('api_key')) {
            abort_unless(auth()->user()->hasPermission('settings.ai_providers.manage_credentials'), 403);
            $this->rateLimit($request, 'credential-save:'.$provider->id, 5);

            if ($provider->credential && ! $request->boolean('confirm_replace_credential')) {
                return back()->withErrors(['api_key' => 'استبدال مفتاح موجود يتطلب تأكيدًا صريحًا.'])->withInput();
            }
        }

        $isActive = auth()->user()->hasPermission('settings.ai_providers.enable_disable')
            ? (bool) ($validated['is_active'] ?? false)
            : $provider->is_active;

        if ($isActive && ! $provider->credential && ! $request->filled('api_key')) {
            return back()->withErrors(['is_active' => 'لا يمكن تفعيل المزود بدون مفتاح API محفوظ.'])->withInput();
        }

        $before = $provider->only(['display_name', 'is_active', 'default_timeout_seconds', 'default_max_retries']);

        $provider->update([
            'display_name' => $validated['display_name'],
            'name' => $validated['display_name'],
            'is_active' => $isActive,
            'is_available' => $isActive && ($provider->credential || $request->filled('api_key')),
            'default_timeout_seconds' => $validated['default_timeout_seconds'],
            'default_max_retries' => $validated['default_max_retries'],
        ]);

        if ($request->filled('api_key')) {
            $action = $provider->credential ? 'ai_provider.credential_replaced' : 'ai_provider.credential_configured';
            $this->credentials->save($provider->refresh(), $validated['api_key'], auth()->user());
            AdminActivityLogger::log($action, 'تم تحديث بيانات اعتماد مزود ذكاء اصطناعي.', $provider, [
                'provider_driver' => $provider->driver,
            ]);
        }

        AdminActivityLogger::log('ai_provider.settings_updated', 'تم تحديث إعدادات مزود ذكاء اصطناعي.', $provider, [
            'provider_driver' => $provider->driver,
            'changes' => AdminActivityLogger::changedValues($before, $provider->fresh()->only(array_keys($before))),
        ]);

        return redirect()->route('admin.settings.ai-providers.edit', $provider)->with('success', 'تم حفظ إعدادات المزود.');
    }

    public function removeCredential(Request $request, AiProvider $provider)
    {
        $this->guardSupported($provider);
        $this->rateLimit($request, 'credential-remove:'.$provider->id, 5);

        $request->validate(['confirm_remove_credential' => ['accepted']]);
        $this->credentials->remove($provider);

        AdminActivityLogger::log('ai_provider.credential_removed', 'تم حذف بيانات اعتماد مزود ذكاء اصطناعي.', $provider, [
            'provider_driver' => $provider->driver,
        ]);

        return back()->with('success', 'تم حذف المفتاح وإيقاف المزود.');
    }

    public function testConnection(Request $request, AiProvider $provider)
    {
        $this->guardSupported($provider);
        $this->rateLimit($request, 'connection-test:'.$provider->id, 5);

        $adapter = in_array($provider->driver, ['openai'], true)
            ? $this->providers->textVisionProvider($provider->driver)
            : $this->providers->imageProvider($provider->driver);

        $result = $adapter->testConnection($provider, $request->boolean('confirm_billable_test'));

        $provider->update([
            'last_health_check_at' => now(),
            'last_health_check_status' => $result['status'],
            'last_health_check_message' => $result['message'],
            'is_available' => $result['status'] !== 'failed' && $provider->is_active && $this->credentials->hasCredential($provider),
        ]);

        $provider->credential?->update([
            'last_tested_at' => now(),
            'last_test_status' => $result['status'],
            'last_test_message' => $result['message'],
        ]);

        AdminActivityLogger::log('ai_provider.connection_tested', 'تم اختبار اتصال مزود ذكاء اصطناعي.', $provider, [
            'provider_driver' => $provider->driver,
            'test_status' => $result['status'],
            'test_message' => $result['message'],
        ]);

        return back()->with($result['status'] === 'failed' ? 'error' : 'success', $result['message']);
    }

    public function models(AiProvider $provider)
    {
        $this->guardSupported($provider);
        $provider->load(['models' => fn ($query) => $query->orderBy('sort_order')->orderBy('display_name')]);

        return view('admin.settings.ai-providers.models', [
            'provider' => $provider,
            'definition' => $this->registry->provider($provider->driver),
            'capabilities' => SupportedProviderRegistry::DEFAULT_CAPABILITIES,
        ]);
    }

    public function updateModels(Request $request, AiProvider $provider)
    {
        $this->guardSupported($provider);
        $this->mergeExistingModelValuesForMissingPermissionFields($request, $provider);

        $definition = $this->registry->provider($provider->driver);
        $supportedCodes = array_keys($definition['models']);

        $validated = $request->validate([
            'models' => ['required', 'array'],
            'models.*.code' => ['required', 'string', Rule::in($supportedCodes)],
            'models.*.display_name' => ['required', 'string', 'max:160'],
            'models.*.is_active' => ['nullable', 'boolean'],
            'models.*.estimated_cost_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'models.*.estimated_cost_currency' => ['required', 'string', 'size:3'],
            'models.*.cost_unit' => ['required', Rule::in(['per_image', 'per_megapixel', 'per_request'])],
            'models.*.notes' => ['nullable', 'string', 'max:2000'],
            'models.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'default_models' => ['nullable', 'array'],
            'default_models.*' => ['nullable', 'string', Rule::in($supportedCodes)],
        ]);

        if (! $provider->is_active || ! $this->credentials->hasCredential($provider)) {
            foreach ($validated['models'] as $modelInput) {
                if ((bool) ($modelInput['is_active'] ?? false)) {
                    return back()->withErrors(['models' => 'لا يمكن تفعيل نموذج قبل تفعيل المزود وحفظ مفتاح API.'])->withInput();
                }
            }
        }

        foreach ($validated['models'] as $modelInput) {
            $modelDefinition = $definition['models'][$modelInput['code']];
            $model = $provider->models()->where('code', $modelInput['code'])->firstOrFail();
            $before = $model->only(['display_name', 'is_active', 'estimated_cost_amount', 'estimated_cost_currency', 'cost_unit', 'notes', 'sort_order']);

            $model->update([
                'display_name' => $modelInput['display_name'],
                'generation_capabilities_json' => $modelDefinition['capabilities'],
                'is_active' => (bool) ($modelInput['is_active'] ?? false),
                'estimated_cost_amount' => $modelInput['estimated_cost_amount'] ?? $model->estimated_cost_amount,
                'estimated_cost_per_output' => $modelInput['estimated_cost_amount'] ?? $model->estimated_cost_per_output,
                'estimated_cost_currency' => strtoupper($modelInput['estimated_cost_currency']),
                'cost_unit' => $modelInput['cost_unit'],
                'notes' => $modelInput['notes'] ?? null,
                'sort_order' => $modelInput['sort_order'] ?? 0,
            ]);

            if ($before != $model->only(array_keys($before))) {
                AdminActivityLogger::log('ai_provider.model_updated', 'تم تحديث نموذج ذكاء اصطناعي.', $model, [
                    'provider_driver' => $provider->driver,
                    'model_code' => $model->code,
                    'changes' => AdminActivityLogger::changedValues($before, $model->only(array_keys($before))),
                ]);
            }
        }

        $defaults = collect($validated['default_models'] ?? [])
            ->filter(fn ($code): bool => filled($code))
            ->all();

        foreach ($defaults as $capability => $code) {
            if (! $this->registry->modelSupportsCapability($provider->driver, $code, $capability)) {
                throw new RuntimeException('Unsupported default model capability mapping.');
            }

            $model = $provider->models()->where('code', $code)->first();

            if (! $model?->is_active) {
                return back()->withErrors(['default_models' => 'لا يمكن اختيار نموذج غير مفعل كافتراضي.'])->withInput();
            }
        }

        $settings = $provider->settings_json ?? [];
        $settings['default_models'] = $defaults;
        $provider->update(['settings_json' => $settings]);

        AdminActivityLogger::log('ai_provider.default_models_updated', 'تم تحديث النماذج الافتراضية.', $provider, [
            'provider_driver' => $provider->driver,
            'default_capabilities' => array_keys($defaults),
        ]);

        return back()->with('success', 'تم حفظ إعدادات النماذج.');
    }

    private function guardSupported(AiProvider $provider): void
    {
        abort_unless($this->registry->supportsProvider($provider->driver), 404);
    }

    private function rateLimit(Request $request, string $action, int $maxAttempts): void
    {
        $key = implode('|', [$action, $request->user()?->id, $request->ip()]);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            abort(429);
        }

        RateLimiter::hit($key, 300);
    }

    private function mergeExistingModelValuesForMissingPermissionFields(Request $request, AiProvider $provider): void
    {
        $models = $request->input('models', []);

        foreach ($models as $index => $modelInput) {
            $model = $provider->models()->where('code', $modelInput['code'] ?? null)->first();

            if (! $model) {
                continue;
            }

            $models[$index]['is_active'] ??= $model->is_active ? '1' : '0';
            $models[$index]['estimated_cost_amount'] ??= $model->estimated_cost_amount ?? $model->estimated_cost_per_output ?? '0.0000';
            $models[$index]['estimated_cost_currency'] ??= $model->estimated_cost_currency ?? 'USD';
            $models[$index]['cost_unit'] ??= $model->cost_unit ?? 'per_image';
            $models[$index]['sort_order'] ??= $model->sort_order ?? 0;
        }

        $request->merge(['models' => $models]);
    }
}
