<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Services\MobileGames\GameConfiguration;
use App\Services\MobileGames\GameEvents;
use App\Services\MobileGames\GameRegistry;
use App\Services\MobileGames\GameReports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileGamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(bool $manage = true): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->permissions()->sync(Permission::whereIn('key', $manage ? ['settings.mobile.view', 'settings.mobile.manage'] : ['settings.mobile.view'])->pluck('id'));

        return $user;
    }

    private function game(): array
    {
        $game = GameRegistry::defaults()['games'][0];
        unset($game['id'], $game['card_image'], $game['rules_version']);

        return $game + ['config_version' => 1];
    }

    private function event(string $name = 'app_opened', array $extra = []): array
    {
        return array_replace(['id' => (string) Str::uuid(), 'name' => $name, 'occurred_at' => now()->utc()->toIso8601String()], $extra);
    }

    private function send(array $events, array $extra = [])
    {
        return $this->postJson('/api/v1/mobile-app/events', array_replace(['schema_version' => 1, 'app_version' => '1.1.0', 'platform' => 'ios', 'events' => $events], $extra));
    }

    private function round(string $name, string $round, string $time, array $extra = []): array
    {
        return $this->event($name, array_replace(['game_id' => 'memory', 'round_id' => $round, 'level' => 3, 'rules_version' => 1, 'occurred_at' => $time], $extra));
    }

    public function test_config_is_public_complete_and_conditional_without_identity(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer fake', 'X-Device-Installation' => 'private-id'])->getJson('/api/v1/mobile-app/config')->assertOk()->assertJsonCount(9, 'data.games')->assertJsonPath('data.config_version', 1)->assertJsonPath('data.games.0.card_image.url', null);
        $this->assertArrayNotHasKey('raw_retention_days', $result->json('data'));
        $this->assertStringNotContainsString('changed_by', $result->getContent());
        $this->withHeaders(['If-None-Match' => $result->headers->get('ETag')])->getJson('/api/v1/mobile-app/config')->assertStatus(304);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_updates_rules_and_restores_monotonically(): void
    {
        $user = $this->admin();
        $payload = $this->game();
        $payload['settings']['default_level'] = 6;
        $this->actingAs($user)->put(route('admin.mobile-operations.games.update', 'memory'), $payload)->assertRedirect();
        $config = app(GameConfiguration::class)->current();
        $this->assertSame(2, $config['version']);
        $this->assertSame(2, $config['data']['games'][0]['rules_version']);
        $this->assertSame(1, $config['data']['games'][0]['card_image']['version']);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'mobile.games.config_updated', 'user_id' => $user->id]);
        $this->putJson(route('admin.mobile-operations.games.update', 'memory'), $payload)->assertStatus(409);
        $this->post(route('admin.mobile-operations.games.restore', 1), ['config_version' => 2])->assertRedirect();
        $config = app(GameConfiguration::class)->current();
        $this->assertSame(3, $config['version']);
        $this->assertSame(3, $config['data']['games'][0]['rules_version']);
        $this->assertDatabaseHas('mobile_game_config_versions', ['version' => 3, 'restored_from' => 1]);
    }

    public function test_admin_views_render_and_view_only_cannot_write(): void
    {
        $this->actingAs($this->admin(false));
        foreach (['games', 'overview', 'game-reports'] as $page) {
            $this->get(route('admin.mobile-operations.'.$page))->assertOk()->assertSee('إعدادات تطبيق الموبايل');
        }
        $this->putJson(route('admin.mobile-operations.games.update', 'memory'), $this->game())->assertForbidden();
        $this->putJson(route('admin.mobile-operations.games.settings'), [])->assertForbidden();
        $this->postJson(route('admin.mobile-operations.games.restore', 1), ['config_version' => 1])->assertForbidden();
    }

    public function test_unauthorized_admin_and_customer_cannot_read_reports(): void
    {
        foreach (['admin', 'customer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $user->permissions()->detach();
            $user->adminRoles()->detach();
            $this->actingAs($user)->getJson(route('admin.mobile-operations.game-reports'))->assertForbidden();
        }
    }

    public function test_typed_settings_reject_unsupported_levels_html_and_free_properties(): void
    {
        $this->actingAs($this->admin());
        foreach (['level', 'html', 'free', 'scalar'] as $invalid) {
            $payload = $this->game();
            if ($invalid === 'level') {
                $payload['settings']['enabled_levels'] = [99];
            }
            if ($invalid === 'html') {
                $payload['title']['ar'] = '<script>bad</script>';
            }
            if ($invalid === 'free') {
                $payload['settings']['url'] = 'https://example.com';
            }
            if ($invalid === 'scalar') {
                $payload['settings'] = 'bad';
            }
            $this->putJson(route('admin.mobile-operations.games.update', 'memory'), $payload)->assertUnprocessable();
        }
        $this->putJson(route('admin.mobile-operations.games.update', 'unknown'), $this->game())->assertNotFound();
        $this->assertSame(1, app(GameConfiguration::class)->current()['version']);
    }

    public function test_public_images_are_reencoded_contained_and_do_not_change_rules(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin())->post(route('admin.mobile-operations.games.update', 'memory'), $this->game() + ['_method' => 'PUT', 'image' => UploadedFile::fake()->image('card.png', 1600, 800)])->assertRedirect();
        $game = app(GameConfiguration::class)->current()['data']['games'][0];
        Storage::disk('public')->assertExists($game['card_image']['path']);
        $size = getimagesizefromstring(Storage::disk('public')->get($game['card_image']['path']));
        $this->assertSame([1024, 512, function_exists('imagewebp') ? 'image/webp' : 'image/png'], [$size[0], $size[1], $size['mime']]);
        $this->assertSame(2, $game['card_image']['version']);
        $this->assertSame(1, $game['rules_version']);
        $url = app(GameConfiguration::class)->publicData()['games'][0]['card_image']['url'];
        $this->assertStringStartsWith('https://', $url);
        $payload = array_replace($this->game(), ['config_version' => 2, 'remove_image' => true]);
        $this->put(route('admin.mobile-operations.games.update', 'memory'), $payload)->assertRedirect();
        Storage::disk('public')->assertExists($game['card_image']['path']); // Historical restore remains possible.
    }

    public function test_spoofed_svg_and_undersized_images_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach ([UploadedFile::fake()->createWithContent('bad.png', '<svg onload="x()"></svg>'), UploadedFile::fake()->image('tiny.png', 32, 32)] as $file) {
            $this->postJson(route('admin.mobile-operations.games.update', 'memory'), $this->game() + ['_method' => 'PUT', 'image' => $file])->assertUnprocessable();
        }
    }

    public function test_events_ignore_account_headers_and_do_not_touch_authentication(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        $event = $this->event();
        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken, 'X-Device-Installation' => 'secret-device'])->send([$event])->assertStatus(202)->assertJsonPath('data.accepted_ids.0', $event['id']);
        $this->assertNull($token->accessToken->fresh()->last_used_at);
        $stored = (array) DB::table('mobile_game_events')->first();
        foreach (['user_id', 'ip', 'headers', 'installation_id'] as $key) {
            $this->assertArrayNotHasKey($key, $stored);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('mobile_game_sessions', 0);
    }

    public function test_event_and_round_deduplication_and_conflicting_replays(): void
    {
        $round = (string) Str::uuid();
        $event = $this->round('game_started', $round, now()->utc()->toIso8601String());
        $this->send([$event, $event])->assertJsonCount(1, 'data.accepted_ids')->assertJsonCount(1, 'data.duplicate_ids');
        $new = array_replace($event, ['id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid()]);
        $this->send([$new])->assertJsonPath('data.duplicate_ids.0', $new['id']);
        $this->assertDatabaseCount('mobile_game_sessions', 0);
        $this->assertSame(1, (int) DB::table('mobile_game_daily')->sum('game_started'));
        $this->send([array_replace($event, ['level' => 6])])->assertJsonPath('data.rejected.0.code', 'event_id_conflict');
        $this->send([array_replace($new, ['id' => (string) Str::uuid(), 'level' => 6])])->assertJsonPath('data.rejected.0.code', 'round_conflict');
    }

    public function test_reverse_arrival_and_late_completion_use_start_cohort(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T15:00:00Z'));
        $round = (string) Str::uuid();
        $start = $this->round('game_started', $round, '2026-11-01T21:59:00Z');
        $end = $this->round('game_completed', $round, '2026-11-01T22:01:00Z', ['duration_ms' => 60000, 'moves' => 9]);
        $this->send([$end])->assertJsonCount(1, 'data.accepted_ids');
        $this->assertSame(0, (int) DB::table('mobile_game_daily')->sum('matched_completed'));
        $this->send([$start, $end])->assertJsonCount(1, 'data.accepted_ids')->assertJsonCount(1, 'data.duplicate_ids');
        $this->assertDatabaseHas('mobile_game_daily', ['day' => '2026-11-01', 'game_started' => 1, 'matched_completed' => 1, 'duration_count' => 1, 'duration_sum' => 60000]);
        $this->assertDatabaseHas('mobile_game_daily', ['day' => '2026-11-02', 'game_completed' => 1, 'matched_completed' => 0]);
        $report = app(GameReports::class)->read(Request::create('/', 'GET', ['from' => '2026-11-01', 'to' => '2026-11-01', 'platform' => 'ios', 'game_id' => 'memory', 'level' => 3]));
        $this->assertSame(1, (int) $report['totals']->matched_completed);
        $this->assertSame(0, (int) $report['totals']->game_completed);
    }

    public function test_invalid_clock_duration_is_excluded_from_average(): void
    {
        $round = (string) Str::uuid();
        $this->send([$this->round('game_started', $round, now()->subSeconds(10)->utc()->toIso8601String()), $this->round('game_completed', $round, now()->utc()->toIso8601String(), ['duration_ms' => 120000])])->assertJsonCount(2, 'data.accepted_ids');
        $this->assertSame(1, (int) DB::table('mobile_game_daily')->sum('matched_completed'));
        $this->assertSame(0, (int) DB::table('mobile_game_daily')->sum('duration_count'));
    }

    public function test_envelopes_are_bounded_and_partially_invalid_events_are_safe(): void
    {
        $this->send([])->assertUnprocessable();
        $this->send(array_fill(0, 51, $this->event()))->assertUnprocessable();
        $this->send([$this->event()], ['user_id' => 1])->assertUnprocessable();
        $this->send([$this->event('unknown')])->assertStatus(202)->assertJsonPath('data.rejected.0.retryable', false);
        $this->send([$this->event('app_opened', ['email' => 'private@example.test']), $this->event()])->assertJsonCount(1, 'data.accepted_ids')->assertJsonPath('data.rejected.0.code', 'unknown_field');
        $this->send([$this->event('app_opened', ['occurred_at' => now()->subDays(8)->utc()->toIso8601String()])])->assertJsonPath('data.rejected.0.code', 'event_outside_window');
        $this->send([$this->event('app_opened', ['occurred_at' => now()->addMinutes(6)->utc()->toIso8601String()])])->assertJsonPath('data.rejected.0.code', 'event_outside_window');
        $this->send([$this->event('app_opened', ['screen' => 'home'])])->assertJsonPath('data.rejected.0.code', 'invalid_fields_for_event');
        $this->post('/api/v1/mobile-app/events', [])->assertStatus(415);
        $this->send([$this->event('app_opened', ['extra' => str_repeat('x', 66000)])])->assertStatus(413);
    }

    public function test_old_rules_remain_valid_after_cosmetic_changes_and_new_rules_are_rejected(): void
    {
        $this->actingAs($this->admin())->put(route('admin.mobile-operations.games.update', 'memory'), array_replace_recursive($this->game(), ['title' => ['ar' => 'اسم جديد']]))->assertRedirect();
        $this->assertSame(1, app(GameConfiguration::class)->current()['data']['games'][0]['rules_version']);
        $service = app(GameEvents::class);
        $event = $this->round('game_started', (string) Str::uuid(), now()->utc()->toIso8601String());
        $this->assertNull($service->validate($event));
        $this->assertSame('unsupported_rules_version', $service->validate(array_replace($event, ['rules_version' => 2])));
    }

    public function test_session_out_of_order_cross_day_is_counted_once_and_not_permanent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T15:00:00Z'));
        $session = (string) Str::uuid();
        $this->send([$this->event('app_opened', ['occurred_at' => '2026-11-01T22:01:00Z', 'session_id' => $session]), $this->event('app_opened', ['occurred_at' => '2026-11-01T21:59:00Z', 'session_id' => $session])])->assertJsonCount(2, 'data.accepted_ids');
        $this->assertDatabaseHas('mobile_game_daily', ['day' => '2026-11-01', 'sessions_started' => 1]);
        $this->assertDatabaseHas('mobile_game_daily', ['day' => '2026-11-02', 'sessions_started' => 0]);
        $this->send([$this->event('app_opened', ['occurred_at' => '2026-10-30T21:59:00Z', 'session_id' => $session])])->assertJsonPath('data.rejected.0.code', 'session_conflict');
        $this->assertSame(2, (int) DB::table('mobile_game_daily')->sum('app_opened'));
    }

    public function test_retention_requires_explicit_cutoff_and_preserves_aggregates(): void
    {
        $event = $this->event();
        app(GameEvents::class)->record($event, 'ios', '1.1.0');
        DB::table('mobile_game_events')->where('id', $event['id'])->update(['received_at' => now()->subDays(100)]);
        $this->artisan('mobile-games:retention', ['--execute' => true])->assertFailed();
        $this->assertDatabaseCount('mobile_game_events', 1);
        $this->artisan('mobile-games:retention', ['--execute' => true, '--confirm-cutoff' => now()->utc()->subDays(90)->toDateString()])->assertSuccessful();
        $this->assertDatabaseCount('mobile_game_events', 0);
        $this->assertSame(1, (int) DB::table('mobile_game_daily')->sum('app_opened'));
        $this->assertDatabaseCount('mobile_game_config_versions', 1);
    }

    public function test_global_toggle_order_and_config_etag_change_without_customer_data(): void
    {
        $before = $this->getJson('/api/v1/mobile-app/config')->headers->get('ETag');
        $this->actingAs($this->admin())->put(route('admin.mobile-operations.games.settings'), ['config_version' => 1, 'games_enabled' => false, 'games_position' => 4, 'raw_retention_days' => 60])->assertRedirect();
        $result = $this->withHeaders(['If-None-Match' => $before])->getJson('/api/v1/mobile-app/config')->assertOk()->assertJsonPath('data.games_enabled', false)->assertJsonPath('data.games_position', 4)->assertJsonPath('data.config_version', 2);
        $this->assertNotSame($before, $result->headers->get('ETag'));
        $this->assertStringNotContainsString('raw_retention_days', $result->getContent());
    }

    public function test_cairo_dst_boundary_and_report_filters(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
        $service = app(GameEvents::class);
        foreach (['2026-10-29T20:59:00Z', '2026-10-29T21:01:00Z', '2026-10-29T22:01:00Z'] as $time) {
            $service->record($this->round('game_started', (string) Str::uuid(), $time), 'android', '1.1.0');
        }
        $this->assertDatabaseHas('mobile_game_daily', ['day' => '2026-10-29', 'game_started' => 2]);
        $this->assertDatabaseHas('mobile_game_daily', ['day' => '2026-10-30', 'game_started' => 1]);
        $report = app(GameReports::class)->read(Request::create('/', 'GET', ['from' => '2026-10-29', 'to' => '2026-10-30', 'platform' => 'ios']));
        $this->assertSame(0, (int) $report['totals']->game_started);
        $this->actingAs($this->admin(false))->getJson(route('admin.mobile-operations.game-reports', ['from' => '2026-11-02', 'to' => '2026-10-01']))->assertUnprocessable();
        $this->getJson(route('admin.mobile-operations.game-reports', ['game_id' => 'memory', 'level' => 16]))->assertUnprocessable();
    }

    public function test_rate_limit_does_not_require_account_and_retention_is_read_only(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->send([$this->event()])->assertStatus(202);
        }
        $this->send([$this->event()])->assertStatus(429);
        $this->artisan('mobile-games:retention')->assertSuccessful();
        $this->assertDatabaseCount('mobile_game_events', 30);
    }
}
