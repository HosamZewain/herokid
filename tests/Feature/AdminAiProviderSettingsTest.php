<?php

namespace Tests\Feature;

use App\Actions\ProductionStudio\CreateGenerationJobAction;
use App\Jobs\SubmitAiGenerationJob;
use App\Models\AdminActivityLog;
use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductionProject;
use App\Models\SceneGenerationJob;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderRegistrySyncer;
use App\Services\Ai\GenerationInputAssetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAiProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_admin_cannot_access_ai_provider_settings(): void
    {
        $admin = $this->adminWithPermissions([]);

        $this->actingAs($admin)
            ->get(route('admin.settings.ai-providers.index'))
            ->assertForbidden();
    }

    public function test_admin_can_save_encrypted_api_key_without_rendering_it(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.manage',
            'settings.ai_providers.manage_credentials',
            'settings.ai_providers.enable_disable',
        ]);
        $provider = $this->falProvider();

        $this->actingAs($admin)
            ->put(route('admin.settings.ai-providers.update', $provider), $this->providerPayload([
                'api_key' => 'fal-secret-db-key-1234',
                'is_active' => '1',
            ]))
            ->assertRedirect();

        $credential = AiProviderCredential::firstOrFail();
        $rawEncryptedValue = DB::table('ai_provider_credentials')->value('encrypted_value');

        $this->assertNotSame('fal-secret-db-key-1234', $rawEncryptedValue);
        $this->assertSame('fal-secret-db-key-1234', $credential->encrypted_value);
        $this->assertSame('1234', $credential->last_four);

        $this->actingAs($admin)
            ->get(route('admin.settings.ai-providers.edit', $provider))
            ->assertOk()
            ->assertDontSee('fal-secret-db-key-1234')
            ->assertSee('••••••••1234');

        $auditPayload = AdminActivityLog::query()->pluck('properties')->map(fn ($value) => json_encode($value))->implode("\n");
        $this->assertStringNotContainsString('fal-secret-db-key-1234', $auditPayload);
        $this->assertStringNotContainsString('••••••••1234', $auditPayload);
        $this->assertStringNotContainsString('1234', $auditPayload);
    }

    public function test_blank_api_key_keeps_existing_credential(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.manage',
            'settings.ai_providers.manage_credentials',
        ]);
        $provider = $this->configuredFalProvider('first-secret-key');

        $this->actingAs($admin)
            ->put(route('admin.settings.ai-providers.update', $provider), $this->providerPayload([
                'api_key' => '',
                'display_name' => 'fal.ai updated',
            ]))
            ->assertRedirect();

        $this->assertSame('first-secret-key', $provider->credential()->first()->encrypted_value);
        $this->assertSame('fal.ai updated', $provider->fresh()->display_name);
    }

    public function test_saving_new_key_clears_previous_failed_health_check(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.manage',
            'settings.ai_providers.manage_credentials',
            'settings.ai_providers.enable_disable',
        ]);
        $provider = $this->falProvider();
        $provider->update([
            'is_active' => false,
            'last_health_check_status' => 'failed',
            'last_health_check_message' => 'Authentication failed.',
            'last_health_check_at' => now(),
        ]);

        $this->actingAs($admin)
            ->put(route('admin.settings.ai-providers.update', $provider), $this->providerPayload([
                'api_key' => 'fresh-fal-secret-key',
                'is_active' => '1',
            ]))
            ->assertRedirect();

        $provider = $provider->fresh();
        $this->assertTrue($provider->is_active);
        $this->assertTrue($provider->is_available);
        $this->assertNull($provider->last_health_check_status);
        $this->assertNull($provider->last_health_check_message);
        $this->assertTrue(app(\App\Services\Ai\AiProviderAvailability::class)->providerAvailable($provider));
    }


    public function test_replacing_existing_key_requires_confirmation(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.manage',
            'settings.ai_providers.manage_credentials',
        ]);
        $provider = $this->configuredFalProvider('first-secret-key');

        $this->actingAs($admin)
            ->from(route('admin.settings.ai-providers.edit', $provider))
            ->put(route('admin.settings.ai-providers.update', $provider), $this->providerPayload([
                'api_key' => 'second-secret-key',
            ]))
            ->assertRedirect(route('admin.settings.ai-providers.edit', $provider))
            ->assertSessionHasErrors('api_key');

        $this->assertSame('first-secret-key', $provider->credential()->first()->encrypted_value);
    }

    public function test_removing_credential_makes_provider_unavailable_without_deleting_history(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.manage_credentials',
        ]);
        $provider = $this->configuredFalProvider('secret-key');
        $project = $this->productionProject();
        $job = $project->generationJobs()->create([
            'ai_provider_id' => $provider->id,
            'ai_model_id' => $provider->models()->first()->id,
            'job_type' => 'character_sheet',
            'generation_mode' => 'character_sheet',
            'status' => 'completed',
        ]);
        $asset = $project->assets()->create([
            'asset_type' => 'character_sheet',
            'status' => 'approved',
            'label' => 'Generated sheet',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.settings.ai-providers.credential.destroy', $provider), [
                'confirm_remove_credential' => '1',
            ])
            ->assertRedirect();

        $this->assertNull($provider->fresh()->credential);
        $this->assertFalse($provider->fresh()->is_active);
        $this->assertDatabaseHas('ai_providers', ['id' => $provider->id]);
        $this->assertDatabaseHas('ai_models', ['ai_provider_id' => $provider->id]);
        $this->assertDatabaseHas('scene_generation_jobs', ['id' => $job->id]);
        $this->assertDatabaseHas('production_project_assets', ['id' => $asset->id]);
    }

    public function test_provider_cannot_be_enabled_without_credential(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.manage',
            'settings.ai_providers.enable_disable',
        ]);
        $provider = $this->falProvider();

        $this->actingAs($admin)
            ->from(route('admin.settings.ai-providers.edit', $provider))
            ->put(route('admin.settings.ai-providers.update', $provider), $this->providerPayload(['is_active' => '1']))
            ->assertRedirect(route('admin.settings.ai-providers.edit', $provider))
            ->assertSessionHasErrors('is_active');
    }

    public function test_arbitrary_model_code_cannot_be_managed(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.manage_models',
        ]);
        $provider = $this->configuredFalProvider('secret-key');

        $this->actingAs($admin)
            ->from(route('admin.settings.ai-providers.models', $provider))
            ->put(route('admin.settings.ai-providers.models.update', $provider), [
                'models' => [
                    ['code' => 'evil/model', 'display_name' => 'Evil', 'is_active' => '1', 'estimated_cost_currency' => 'USD', 'cost_unit' => 'per_image'],
                ],
            ])
            ->assertRedirect(route('admin.settings.ai-providers.models', $provider))
            ->assertSessionHasErrors('models.0.code');
    }

    public function test_generation_uses_database_credential_and_not_env_key(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        Config::set('production_studio.ai.fal.key', 'wrong-env-key');
        $provider = $this->configuredFalProvider('database-secret-key');
        $model = $provider->models()->where('code', 'fal-ai/flux-kontext/dev')->firstOrFail();
        $project = $this->productionProject(['orders/photos/kid.png']);

        $this->actingAs($this->adminWithPermissions(['production_studio.ai_generate']));
        app(CreateGenerationJobAction::class)->execute($project, [
            'model_code' => $model->code,
            'job_type' => 'character_sheet',
            'generation_mode' => 'character_sheet',
            'style_preset' => 'premium_storybook',
            'reference_photo_indices' => [0],
        ]);

        Http::fake([
            'https://queue.fal.run/*' => Http::response([
                'request_id' => 'request-1',
                'status' => 'IN_QUEUE',
                'status_url' => 'https://fal.test/status',
                'response_url' => 'https://fal.test/result',
            ]),
        ]);

        (new SubmitAiGenerationJob(SceneGenerationJob::firstOrFail()->id))
            ->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Key database-secret-key'));
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Key wrong-env-key'));
    }

    public function test_connection_test_is_warning_without_billable_confirmation_and_sanitized(): void
    {
        Http::fake();
        $admin = $this->adminWithPermissions([
            'settings.ai_providers.view',
            'settings.ai_providers.test_connection',
        ]);
        $provider = $this->configuredFalProvider('database-secret-key');

        $this->actingAs($admin)
            ->post(route('admin.settings.ai-providers.test', $provider))
            ->assertRedirect()
            ->assertSessionHas('success', 'A billable validation request requires confirmation.');

        Http::assertNothingSent();
        $this->assertSame('warning', $provider->fresh()->last_health_check_status);
    }

    public function test_legacy_env_import_command_stores_key_encrypted_and_does_not_overwrite_without_force(): void
    {
        Config::set('production_studio.ai.fal.key', 'legacy-secret-key');
        $provider = $this->falProvider();

        Artisan::call('ai:import-provider-key', ['driver' => 'fal', '--yes' => true]);

        $this->assertStringNotContainsString('legacy-secret-key', Artisan::output());
        $this->assertSame('legacy-secret-key', $provider->fresh()->credential->encrypted_value);

        Config::set('production_studio.ai.fal.key', 'new-legacy-secret');
        Artisan::call('ai:import-provider-key', ['driver' => 'fal', '--yes' => true]);

        $this->assertSame('legacy-secret-key', $provider->fresh()->credential->encrypted_value);
    }

    private function falProvider(): AiProvider
    {
        app(AiProviderRegistrySyncer::class)->sync();

        return AiProvider::where('driver', 'fal')->firstOrFail();
    }

    private function configuredFalProvider(string $secret): AiProvider
    {
        $provider = $this->falProvider();
        app(AiProviderCredentialService::class)->save($provider, $secret);
        $provider->update([
            'is_active' => true,
            'is_configured' => true,
            'is_available' => true,
            'last_health_check_status' => null,
        ]);

        return $provider->fresh(['credential', 'models']);
    }

    private function providerPayload(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'fal.ai',
            'is_active' => '0',
            'default_timeout_seconds' => 180,
            'default_max_retries' => 2,
            'api_key' => '',
        ], $overrides);
    }

    private function productionProject(array $photos = []): ProductionProject
    {
        $order = Order::create([
            'order_number' => 'HK-2026-'.strtoupper(Str::random(6)),
            'parent_name' => 'Parent',
            'child_name' => 'Rina',
            'child_age' => 6,
            'child_gender' => 'girl',
            'uploaded_photos' => $photos,
            'status' => 'new',
        ]);

        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
            'sent_to_studio_at' => now(),
        ]);

        $project->characterProfile()->create([
            'approved_reference_photos' => [0],
            'reference_photo_selection' => [0],
        ]);

        return $project->load(['order', 'characterProfile']);
    }

    private function adminWithPermissions(array $permissionKeys): User
    {
        $admin = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);

        $admin->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id')->all());

        return $admin->refresh();
    }
}
