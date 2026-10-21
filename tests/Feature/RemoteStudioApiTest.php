<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Permission;
use App\Models\StudioAsset;
use App\Models\StudioSession;
use App\Models\StudioSettingsRevision;
use App\Models\User;
use App\Services\RemoteStudio\StudioSettingsSchema;
use App\Services\RemoteStudio\StudioSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemoteStudioApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('studio_private');
        Http::preventStrayRequests();
    }

    private function staff(array $permissions = ['studio.access', 'studio.settings.read']): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'password' => 'studio-test-password', 'agent_api_enabled' => true]);
        $user->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return $user->fresh();
    }

    private function callApi(string $method, string $url, ?string $token = null, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        if ($token) {
            $this->withToken($token);
        }

        return $this->json($method, $url, $data);
    }

    private function login(User $user): string
    {
        return $this->callApi('POST', '/api/studio/auth/login', null, ['login' => $user->email, 'password' => 'studio-test-password'])->assertOk()->json('data.token');
    }

    private function policy(User $user, array $abilities): PersonalAccessToken
    {
        $token = $user->createToken('Existing employee Agent policy', $abilities, now()->addDays(2))->accessToken;
        DB::table('studio_employee_grants')->insert(['user_id' => $user->id, 'agent_token_id' => $token->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $token;
    }

    private function publish(User $user, ?array $settings = null, int $base = 0): StudioSettingsRevision
    {
        return app(StudioSettingsService::class)->publish($settings ?? StudioSettingsSchema::defaults(), $base, $user, 'Isolated test publish');
    }

    public function test_login_issues_distinct_employee_owned_short_sessions_and_not_shared_agent_secret(): void
    {
        $user = $this->staff(['studio.access', 'studio.settings.read', 'orders.view', 'orders.photos.view']);
        $source = $this->policy($user, ['agent', 'agent:orders.read', 'agent:orders.upload-preview', 'agent:catalog.products', 'agent:catalog.product.17']);
        $token = $this->login($user);
        $pat = PersonalAccessToken::findToken($token);
        $this->assertSame($user->id, $pat->tokenable_id);
        $this->assertNotSame($source->id, $pat->id);
        $this->assertNotContains('*', $pat->abilities);
        $this->assertContains('agent:orders.read', $pat->abilities);
        $this->assertContains('agent:catalog.product.17', $pat->abilities);
        $this->assertNotContains('agent:orders.upload-preview', $pat->abilities);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($pat->expires_at), 1);
        $this->assertDatabaseCount('order_group_assignments', 0);
        $second = $this->login($user);
        $this->assertNotSame($token, $second);
        $this->callApi('GET', '/api/agent/studio/connection', $token)->assertOk()->assertJsonPath('agent.id', $user->id);
        $this->callApi('POST', '/api/agent/checkouts/acquire-next', $token)->assertForbidden();
        $this->assertStringNotContainsString($token, AdminActivityLog::all()->toJson());
        $this->assertStringNotContainsString('studio-test-password', AdminActivityLog::all()->toJson());
    }

    public function test_phone_login_uses_existing_staff_identifier_and_never_creates_accounts(): void
    {
        $user = $this->staff();
        $user->update(['phone' => '01012345678']);
        $count = User::count();
        $this->callApi('POST', '/api/studio/auth/login', null, ['login' => '010 1234 5678', 'password' => 'studio-test-password'])->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertSame($count, User::count());
    }

    public function test_invalid_disabled_customer_and_unpermitted_accounts_cannot_sign_in(): void
    {
        $user = $this->staff();
        $this->callApi('POST', '/api/studio/auth/login', null, ['login' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        $user->update(['is_active' => false]);
        $this->callApi('POST', '/api/studio/auth/login', null, ['login' => $user->email, 'password' => 'studio-test-password'])->assertUnauthorized();
        $user->update(['is_active' => true, 'role' => 'customer']);
        $this->callApi('POST', '/api/studio/auth/login', null, ['login' => $user->email, 'password' => 'studio-test-password'])->assertUnauthorized();
        $user->update(['role' => 'admin']);
        $user->permissions()->detach();
        $this->callApi('POST', '/api/studio/auth/login', null, ['login' => $user->email, 'password' => 'studio-test-password'])->assertUnauthorized();
        $this->assertDatabaseCount('studio_sessions', 0);
    }

    public function test_login_rate_limit_and_required_password(): void
    {
        $this->callApi('POST', '/api/studio/auth/login', null, ['login' => 'unknown@example.test'])->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->callApi('POST', '/api/studio/auth/login', null, ['login' => 'unknown@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->callApi('POST', '/api/studio/auth/login', null, ['login' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_settings_only_session_does_not_grant_production_or_mobile_access(): void
    {
        $user = $this->staff();
        $token = $this->login($user);
        $this->callApi('GET', '/api/studio/auth/me', $token)->assertOk()->assertJsonPath('data.abilities', ['studio:session']);
        $this->callApi('GET', '/api/agent/studio/connection', $token)->assertForbidden();
        $this->callApi('GET', '/api/v1/cart', $token)->assertForbidden();
        $this->assertDatabaseCount('permission_user', 2);
    }

    public function test_logout_revokes_one_session_without_revoking_other_session_or_agent_token(): void
    {
        $user = $this->staff();
        $source = $this->policy($user, ['agent']);
        $one = $this->login($user);
        $two = $this->login($user);
        $this->callApi('POST', '/api/studio/auth/logout', $one)->assertNoContent();
        $this->callApi('GET', '/api/studio/auth/me', $one)->assertUnauthorized();
        $this->callApi('GET', '/api/studio/auth/me', $two)->assertOk();
        $this->assertNotNull($source->fresh());
    }

    public function test_expired_session_and_disabled_employee_stop_on_existing_agent_endpoints(): void
    {
        $user = $this->staff(['studio.access', 'orders.view', 'orders.photos.view']);
        $this->policy($user, ['agent', 'agent:orders.read']);
        $token = $this->login($user);
        $user->update(['is_active' => false]);
        $this->callApi('GET', '/api/agent/studio/connection', $token)->assertForbidden();
        $user->update(['is_active' => true]);
        $this->travel(121)->minutes();
        $this->callApi('GET', '/api/agent/studio/connection', $token)->assertUnauthorized();
    }

    public function test_password_change_and_policy_revocation_invalidate_sessions(): void
    {
        $user = $this->staff();
        $source = $this->policy($user, ['agent']);
        $token = $this->login($user);
        $source->delete();
        $this->callApi('GET', '/api/studio/auth/me', $token)->assertUnauthorized();
        $new = $this->login($user);
        $user->update(['password' => 'different-password']);
        $this->callApi('GET', '/api/studio/auth/me', $new)->assertUnauthorized();
    }

    public function test_policy_catalog_edits_invalidate_instead_of_accidentally_unrestricting_session(): void
    {
        $user = $this->staff(['studio.access', 'orders.view', 'orders.photos.view']);
        $source = $this->policy($user, ['agent', 'agent:orders.read', 'agent:catalog.stories']);
        $token = $this->login($user);
        $source->forceFill(['abilities' => ['agent', 'agent:orders.read', 'agent:catalog.products']])->save();
        $this->callApi('GET', '/api/agent/studio/connection', $token)->assertUnauthorized();
    }

    public function test_permission_removal_takes_effect_immediately_without_changing_source(): void
    {
        $user = $this->staff(['studio.access', 'orders.view', 'orders.photos.view']);
        $source = $this->policy($user, ['agent', 'agent:orders.read']);
        $token = $this->login($user);
        $user->permissions()->detach(Permission::where('key', 'orders.photos.view')->value('id'));
        $this->callApi('GET', '/api/agent/studio/connection', $token)->assertForbidden();
        $this->assertContains('agent:orders.read', $source->fresh()->abilities);
    }

    public function test_five_session_limit_and_source_expiry_bound(): void
    {
        $user = $this->staff();
        $source = $this->policy($user, ['agent']);
        $source->update(['expires_at' => now()->addMinutes(15)]);
        $first = $this->login($user);
        for ($i = 0; $i < 5; $i++) {
            $this->login($user);
        }
        $this->callApi('GET', '/api/studio/auth/me', $first)->assertUnauthorized();
        $this->assertSame(5, StudioSession::whereNull('revoked_at')->count());
        $this->assertEqualsWithDelta(15, now()->diffInMinutes(StudioSession::latest('id')->first()->expires_at), 1);
    }

    public function test_other_token_types_cannot_read_settings_even_with_admin_identity(): void
    {
        $user = $this->staff();
        $this->callApi('GET', '/api/studio/settings')->assertUnauthorized();
        foreach ([['*'], ['agent'], ['mobile'], ['mobile:guest']] as $abilities) {
            $token = $user->createToken('non Studio', $abilities)->plainTextToken;
            $this->callApi('GET', '/api/studio/settings', $token)->assertForbidden();
        }
        $token = $this->login($user);
        $this->callApi('GET', '/api/studio/settings', $token)->assertStatus(503);
    }

    public function test_complete_versioned_snapshot_conditional_304_and_no_secrets_or_local_paths(): void
    {
        $user = $this->staff();
        $revision = $this->publish($user);
        $token = $this->login($user);
        $response = $this->callApi('GET', '/api/studio/settings', $token)->assertOk()->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.revision', (string) $revision->id)->assertJsonPath('data.settings', fn ($value) => $value == StudioSettingsSchema::defaults())
            ->assertJsonPath('data.assets', [])->assertHeader('Vary', 'Authorization');
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('/Users/', $response->getContent());
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->getJson('/api/studio/settings')->assertStatus(304)->assertContent('');
    }

    public function test_read_permission_is_not_implied_by_access_or_manage(): void
    {
        $user = $this->staff(['studio.access', 'studio.settings.manage']);
        $token = $this->login($user);
        $this->callApi('GET', '/api/studio/settings', $token)->assertForbidden();
        $this->callApi('GET', '/api/studio/settings/assets/'.Str::uuid(), $token)->assertForbidden();
    }

    public function test_same_employee_session_receives_new_full_revision_on_manual_refresh(): void
    {
        $user = $this->staff();
        $one = $this->publish($user);
        $token = $this->login($user);
        $first = $this->callApi('GET', '/api/studio/settings', $token)->assertOk();
        $changed = $one->settings;
        $changed['production_typography']['default_text_box_page_side'] = 'left';
        $two = $this->publish($user, $changed, $one->id);
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->getJson('/api/studio/settings')
            ->assertOk()->assertJsonPath('data.revision', (string) $two->id)->assertJsonPath('data.settings', fn ($value) => $value == $changed);
        $this->assertDatabaseCount('studio_sessions', 1);
        $this->assertEquals($one->settings, $one->fresh()->settings);
    }

    public function test_protected_asset_integrity_and_unpublished_assets_are_not_downloadable(): void
    {
        $user = $this->staff();
        $service = app(StudioSettingsService::class);
        $asset = $service->upload(UploadedFile::fake()->image('private-device-filename.jpg', 25, 40), $user);
        $token = $this->login($user);
        $this->callApi('GET', '/api/studio/settings/assets/'.$asset->id, $token)->assertNotFound();
        $settings = StudioSettingsSchema::defaults();
        $settings['default_back_cover'] = ['asset_id' => $asset->id, 'crop' => ['focalX' => 0.5, 'focalY' => 0.5, 'zoom' => 1]];
        $this->publish($user, $settings);
        $response = $this->callApi('GET', '/api/studio/settings', $token)->assertOk();
        $metadata = $response->json('data.assets.0');
        $bytes = Storage::disk('studio_private')->get($asset->path);
        $this->assertSame(hash('sha256', $bytes), $metadata['sha256']);
        $this->assertSame(strlen($bytes), $metadata['byte_size']);
        $this->assertSame(25, $metadata['width']);
        $this->assertStringNotContainsString('private-device-filename', $response->getContent());
        $this->assertArrayNotHasKey('path', $metadata);
        $this->callApi('GET', $metadata['download_url'])->assertUnauthorized();
        $download = $this->callApi('GET', $metadata['download_url'], $token)->assertOk()->assertHeader('X-Checksum-SHA256', $metadata['sha256']);
        $this->assertSame($bytes, $download->streamedContent());
        Storage::disk('studio_private')->put($asset->path, 'corrupt');
        $this->callApi('GET', $metadata['download_url'], $token)->assertStatus(503);
        $this->callApi('GET', '/api/studio/settings', $token)->assertStatus(503);
    }

    public function test_failed_publish_does_not_change_active_revision_or_history_and_stale_publish_conflicts(): void
    {
        $user = $this->staff();
        $one = $this->publish($user);
        $settings = StudioSettingsSchema::defaults();
        $settings['brand_logo'] = ['asset_id' => (string) Str::uuid(), 'placement' => ['position' => 'top-center', 'x' => 0.3, 'y' => 0.04, 'widthPercent' => 0.32, 'opacity' => 1]];
        try {
            $this->publish($user, $settings, $one->id);
            $this->fail('Missing asset accepted');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('studio_settings_revisions', 1);
        $this->assertSame($one->id, app(StudioSettingsService::class)->current()->id);
        $token = $this->login($user);
        $this->actingAs($this->staff(['studio.settings.manage']));
        $this->postJson('/admin/studio-settings/publish', ['settings_json' => json_encode(StudioSettingsSchema::defaults()), 'base_revision' => 0, 'reason' => 'stale', 'confirmed' => true])->assertConflict();
    }

    public static function invalidSettings(): array
    {
        return [
            'unknown secret field' => ['api_key', 'sk-secret-example'],
            'filesystem path' => ['openai_cover.cover_style_notes', '/Users/operator/logo.png'],
            'HTML' => ['openai_cover.cover_style_notes', '<script>alert(1)</script>'],
            'unsupported weight' => ['production_typography.default_font_weight', 999],
            'string boolean' => ['ui_preferences.show_bleed', 'false'],
            'oversized prompt' => ['openai_cover.prompt_template', str_repeat('a', 20001)],
            'unsafe size' => ['openai_cover.size', '9999x9999'],
            'wrong model quality' => ['openai_cover.quality', 'max'],
            'profiles not list' => ['product_production_profiles', ['bad' => []]],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_strict_settings_validation_rejects_unsafe_or_incomplete_configuration(string $path, mixed $value): void
    {
        $settings = StudioSettingsSchema::defaults();
        data_set($settings, $path, $value);
        $this->expectException(ValidationException::class);
        app(StudioSettingsSchema::class)->validate($settings);
    }

    public function test_admin_permissions_confirmation_publish_audit_and_rollback(): void
    {
        $reader = $this->staff();
        $this->actingAs($reader)->get('/admin/studio-settings')->assertForbidden();
        $manager = $this->staff(['studio.settings.manage']);
        $this->actingAs($manager)->get('/admin/studio-settings')->assertOk()->assertSee('إعدادات HeroKid Studio المركزية');
        $payload = ['settings_json' => json_encode(StudioSettingsSchema::defaults()), 'base_revision' => 0, 'reason' => 'Initial test publish'];
        $this->postJson('/admin/studio-settings/publish', $payload)->assertUnprocessable();
        $this->post('/admin/studio-settings/publish', [...$payload, 'confirmed' => true])->assertRedirect();
        $one = app(StudioSettingsService::class)->current();
        $changed = StudioSettingsSchema::defaults();
        $changed['production_typography']['default_font_weight'] = 700;
        $two = $this->publish($manager, $changed, $one->id);
        $this->post('/admin/studio-settings/revisions/'.$one->id.'/rollback', ['base_revision' => $two->id, 'reason' => 'Restore typography', 'confirmed' => true])->assertRedirect();
        $three = app(StudioSettingsService::class)->current();
        $this->assertGreaterThan($two->id, $three->id);
        $this->assertSame($one->settings, $three->settings);
        $this->assertSame(700, $two->fresh()->settings['production_typography']['default_font_weight']);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'studio.settings.published', 'user_id' => $manager->id]);
    }

    public function test_admin_asset_upload_rejects_svg_and_private_download_requires_manager(): void
    {
        $manager = $this->staff(['studio.settings.manage']);
        $this->actingAs($manager)->post('/admin/studio-settings/assets', ['file' => UploadedFile::fake()->image('logo.png')])->assertRedirect();
        $asset = StudioAsset::firstOrFail();
        $this->get('/admin/studio-settings/assets/'.$asset->id)->assertOk();
        $this->postJson('/admin/studio-settings/assets', ['file' => UploadedFile::fake()->createWithContent('bad.svg', '<svg/>')])->assertUnprocessable();
        $this->actingAs($this->staff())->get('/admin/studio-settings/assets/'.$asset->id)->assertForbidden();
    }

    public function test_admin_editor_preserves_complete_settings_and_hides_raw_json_controls(): void
    {
        $manager = $this->staff(['studio.access', 'studio.settings.read', 'studio.settings.manage']);
        $asset = app(StudioSettingsService::class)->upload(UploadedFile::fake()->image('logo.png', 100, 40), $manager);
        $settings = StudioSettingsSchema::defaults();
        $settings['brand_logo'] = ['asset_id' => $asset->id, 'placement' => ['position' => 'custom', 'x' => 0.36, 'y' => 0.04, 'widthPercent' => 0.28, 'opacity' => 0.8]];
        $settings['default_back_cover'] = ['asset_id' => $asset->id, 'crop' => ['focalX' => 0.3, 'focalY' => 0.8, 'zoom' => 2]];
        $revision = $this->publish($manager, $settings);
        $response = $this->actingAs($manager)->get('/admin/studio-settings')->assertOk()->assertSee('data-studio-settings-editor', false)->assertDontSee('rows="24"', false);
        $response->assertViewHas('configuration', fn ($value) => $value == $settings);
        $options = $response->viewData('editorOptions');
        $this->assertSame(['id', 'width', 'height', 'bytes', 'preview_url'], array_keys($options['assets'][0]));
        $this->assertStringNotContainsString('studio-private', json_encode($options));
        $this->post('/admin/studio-settings/publish', ['settings_json' => json_encode($settings), 'base_revision' => $revision->id, 'reason' => 'Keep configured positions', 'confirmed' => true])->assertRedirect();
        $this->assertEquals($settings, app(StudioSettingsService::class)->current()->settings);
        $this->postJson('/admin/studio-settings/publish', ['settings_json' => json_encode($settings), 'base_revision' => $revision->id, 'reason' => 'Stale form', 'confirmed' => true])->assertConflict();
    }

    public function test_inline_upload_returns_only_safe_metadata_and_remains_unpublished(): void
    {
        $manager = $this->staff(['studio.access', 'studio.settings.read', 'studio.settings.manage']);
        $response = $this->actingAs($manager)->postJson('/admin/studio-settings/assets', ['file' => UploadedFile::fake()->image('private-local-name.png', 100, 80)])
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'width', 'height', 'bytes', 'preview_url']]);
        $this->assertSame(['id', 'width', 'height', 'bytes', 'preview_url'], array_keys($response->json('data')));
        $this->assertStringNotContainsString('private-local-name', $response->getContent());
        $id = $response->json('data.id');
        $token = $this->login($manager);
        $this->callApi('GET', '/api/studio/settings/assets/'.$id, $token)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($this->staff())->postJson('/admin/studio-settings/assets', ['file' => UploadedFile::fake()->image('denied.png')])->assertForbidden();
    }

    public function test_editor_does_not_omit_old_assets_referenced_by_current_revision(): void
    {
        $manager = $this->staff(['studio.settings.manage']);
        $asset = app(StudioSettingsService::class)->upload(UploadedFile::fake()->image('old-logo.png'), $manager);
        $asset->update(['created_at' => now()->subYear()]);
        $settings = StudioSettingsSchema::defaults();
        $settings['default_back_cover'] = ['asset_id' => $asset->id, 'crop' => ['focalX' => 0.5, 'focalY' => 0.5, 'zoom' => 1]];
        $this->publish($manager, $settings);
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'path' => 'qa-placeholder.png', 'mime' => 'image/png', 'bytes' => 100, 'width' => 10, 'height' => 10, 'checksum' => str_repeat('a', 64), 'uploaded_by' => $manager->id, 'created_at' => now()];
        }
        DB::table('studio_assets')->insert($rows);
        $this->actingAs($manager)->get('/admin/studio-settings')->assertOk()->assertViewHas('assets', fn ($assets) => $assets->contains('id', $asset->id));
    }

    public function test_manager_can_revoke_session_and_cannot_bind_another_employees_policy(): void
    {
        $manager = $this->staff(['studio.settings.manage', 'studio.sessions.manage']);
        $employee = $this->staff();
        $other = $this->staff();
        $source = $this->policy($other, ['agent']);
        $token = $this->login($employee);
        $session = StudioSession::where('user_id', $employee->id)->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($manager)->putJson('/admin/studio-settings/employees/'.$employee->id, ['agent_token_id' => $source->id, 'confirmed' => true])->assertUnprocessable();
        $this->delete('/admin/studio-settings/sessions/'.$session->id)->assertRedirect();
        $this->callApi('GET', '/api/studio/auth/me', $token)->assertUnauthorized();
        $this->assertNotNull($source->fresh());
    }

    public function test_legacy_agent_authentication_remains_unchanged(): void
    {
        $user = $this->staff(['orders.view', 'orders.photos.view']);
        $token = $user->createToken('Existing Agent', ['agent', 'agent:orders.read'])->plainTextToken;
        $this->callApi('GET', '/api/agent/studio/connection', $token)->assertOk();
        $this->assertDatabaseCount('studio_sessions', 0);
    }

    public function test_product_profile_round_trip_preserves_stable_ids_and_template_references(): void
    {
        $user = $this->staff();
        $service = app(StudioSettingsService::class);
        $asset = $service->upload(UploadedFile::fake()->image('template.webp', 40, 60), $user);
        $settings = StudioSettingsSchema::defaults();
        $settings['product_production_profiles'] = [[
            'schemaVersion' => 1, 'id' => 'existing-local-profile-17', 'name' => 'قالب بطاقة', 'workflow' => 'personalized-card',
            'match' => ['productId' => '17', 'sku' => 'CARD', 'componentKey' => null],
            'canvas' => ['widthMm' => 60, 'heightMm' => 90, 'orientation' => 'portrait'], 'sideCount' => 1, 'defaultCopies' => 3,
            'cut' => ['showBorder' => true, 'borderColor' => '#000000', 'borderWidthMm' => 0.5, 'borderInsetMm' => 0.5, 'bleedMm' => 0],
            'templates' => ['front' => ['asset_id' => $asset->id], 'back' => null],
            'photo' => ['enabled' => true, 'side' => 'front', 'shape' => 'oval', 'rect' => ['xPercent' => 20, 'yPercent' => 9, 'widthPercent' => 60, 'heightPercent' => 38]],
            'fields' => [], 'updatedAt' => '2026-10-21T12:00:00.000Z',
        ]];
        $settings['text_style_presets'] = [[
            'id' => 'existing-text-preset', 'name' => 'عنوان', 'createdAt' => '2026-10-21T12:00:00.000Z', 'updatedAt' => '2026-10-21T12:00:00.000Z',
            'style' => ['fontId' => 'cairo', 'fontWeight' => 700, 'fontSizePt' => 20, 'lineHeight' => 1.5, 'color' => '#000000', 'backgroundColor' => '#ffffff',
                'backgroundOpacity' => 0, 'alignment' => 'center', 'verticalAlignment' => 'center', 'textDirection' => 'rtl',
                'paddingTopMm' => 1, 'paddingRightMm' => 1, 'paddingBottomMm' => 1, 'paddingLeftMm' => 1, 'borderRadiusMm' => 0],
        ]];
        $this->publish($user, $settings);
        $token = $this->login($user);
        $response = $this->callApi('GET', '/api/studio/settings', $token)->assertOk()->assertJsonPath('data.settings', fn ($value) => $value == $settings)->assertJsonCount(1, 'data.assets');
        $this->assertSame($response->json('data.checksum'), $service->checksum($response->json('data.settings'), $response->json('data.assets')));
        $settings['product_production_profiles'][] = $settings['product_production_profiles'][0];
        $this->expectException(ValidationException::class);
        app(StudioSettingsSchema::class)->validate($settings);
    }

    public function test_wildcard_source_is_not_inherited_and_settings_access_can_be_revoked(): void
    {
        $user = $this->staff();
        $this->policy($user, ['*', 'agent']);
        $token = $this->login($user);
        $this->callApi('GET', '/api/studio/auth/me', $token)->assertOk()->assertJsonPath('data.abilities', ['studio:session']);
        $user->permissions()->detach(Permission::where('key', 'studio.access')->value('id'));
        $this->callApi('GET', '/api/studio/auth/me', $token)->assertForbidden();
    }

    public function test_grant_updates_revoke_sessions_without_modifying_existing_agent_policy(): void
    {
        $employee = $this->staff();
        $source = $this->policy($employee, ['agent', 'agent:catalog.products', 'agent:catalog.product.42']);
        $before = $source->fresh()->getRawOriginal();
        $token = $this->login($employee);
        $manager = $this->staff(['studio.settings.manage', 'studio.sessions.manage']);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($manager)->put('/admin/studio-settings/employees/'.$employee->id, ['agent_token_id' => $source->id, 'confirmed' => true])->assertRedirect();
        $this->callApi('GET', '/api/studio/auth/me', $token)->assertUnauthorized();
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertSame(2, $employee->permissions()->count());
    }

    public function test_publish_rejects_missing_top_level_fields_and_rollback_missing_asset_is_atomic(): void
    {
        $user = $this->staff();
        $service = app(StudioSettingsService::class);
        $asset = $service->upload(UploadedFile::fake()->image('back.png'), $user);
        $settings = StudioSettingsSchema::defaults();
        $settings['default_back_cover'] = ['asset_id' => $asset->id, 'crop' => ['focalX' => 0.5, 'focalY' => 0.5, 'zoom' => 1]];
        $one = $this->publish($user, $settings);
        $two = $this->publish($user, null, $one->id);
        Storage::disk('studio_private')->delete($asset->path);
        $manager = $this->staff(['studio.settings.manage']);
        $this->actingAs($manager)->postJson('/admin/studio-settings/revisions/'.$one->id.'/rollback', ['base_revision' => $two->id, 'reason' => 'Missing test asset', 'confirmed' => true])->assertUnprocessable();
        $this->assertSame($two->id, $service->current()->id);
        $this->assertDatabaseCount('studio_settings_revisions', 2);
        unset($settings['ui_preferences']);
        $this->expectException(ValidationException::class);
        app(StudioSettingsSchema::class)->validate($settings);
    }

    public function test_studio_sessions_cannot_be_converted_to_legacy_tokens_by_the_agent_editor(): void
    {
        $employee = $this->staff(['studio.access', 'orders.view', 'orders.photos.view']);
        $source = $this->policy($employee, ['agent', 'agent:orders.read']);
        $secret = $this->login($employee);
        $sessionToken = PersonalAccessToken::findToken($secret);
        $manager = $this->staff(['agent_api.tokens.manage']);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($manager)->get('/admin/agent-api-tokens')->assertOk()->assertViewHas('tokens', fn ($tokens) => $tokens->contains('id', $source->id) && ! $tokens->contains('id', $sessionToken->id));
        $this->get('/admin/agent-api-tokens/'.$sessionToken->id.'/edit')->assertNotFound();
        $this->patch('/admin/agent-api-tokens/'.$sessionToken->id, [])->assertNotFound();
        $this->assertContains('studio:session', $sessionToken->fresh()->abilities);
        $this->callApi('GET', '/api/studio/auth/me', $secret)->assertOk();
    }
}
