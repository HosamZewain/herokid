<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Services\Analytics\AppLandingAnalytics;
use App\Services\Analytics\AppLandingRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AppLandingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $extra = []): array
    {
        return array_merge(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)', 'Referer' => route('app.download'), 'X-HeroKid-App-Metric' => '1', 'Accept' => 'application/json'], $extra);
    }

    private function visit(string $view): string
    {
        return route('app.metrics.visit', ['v' => $view]);
    }

    public function test_visits_are_pseudonymous_cookie_identified_and_idempotent(): void
    {
        $this->withCookie(AppLandingRecorder::COOKIE, str_repeat('a', 64));
        $view = (string) Str::uuid();
        $this->get($this->visit($view), $this->headers())->assertNoContent();
        $this->get($this->visit($view), $this->headers())->assertNoContent();
        $this->get($this->visit((string) Str::uuid()), $this->headers())->assertNoContent();
        $this->assertDatabaseCount('app_landing_events', 2);
        $this->assertSame(1, DB::table('app_landing_events')->distinct()->count('visitor_hash'));
        $row = (array) DB::table('app_landing_events')->first();
        $this->assertSame(hash_hmac('sha256', str_repeat('a', 64), config('app.key')), $row['visitor_hash']);
        $this->assertEqualsCanonicalizing(['id', 'dedup_key', 'visitor_hash', 'event_type', 'occurred_on', 'occurred_at'], array_keys($row));
        $this->assertStringNotContainsString($view, json_encode($row));
    }

    public function test_new_visitor_cookie_is_private_and_report_uses_no_personal_information(): void
    {
        config(['app.url' => 'https://hero-kid.com']);
        $response = $this->get('http://localhost/app/metrics/visit?v='.(string) Str::uuid(), $this->headers(['Referer' => 'http://localhost/app']))->assertNoContent()->assertCookie(AppLandingRecorder::COOKIE);
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === AppLandingRecorder::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_both_store_links_redirect_to_fixed_targets_and_deduplicate_repeated_clicks(): void
    {
        $this->withCookie(AppLandingRecorder::COOKIE, str_repeat('a', 64));
        $view = (string) Str::uuid();
        $this->get($this->visit($view), $this->headers())->assertNoContent();
        foreach (['ios', 'ios', 'android', 'android'] as $platform) {
            $response = $this->get(route('app.store', ['platform' => $platform, 'v' => $view, 'url' => 'https://evil.example']), $this->headers())
                ->assertRedirect(config('mobile_app.'.$platform.'_url'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString($view, $response->headers->get('Location'));
        }
        $this->assertDatabaseCount('app_landing_events', 3);
        $this->assertSame(1, DB::table('app_landing_events')->where('event_type', 'view')->count());
        $this->get(route('app.store', ['platform' => 'ios', 'v' => (string) Str::uuid()]), $this->headers())->assertRedirect();
        $this->assertDatabaseCount('app_landing_events', 5);
    }

    public function test_without_javascript_clicks_still_work_and_record_a_visit_once_per_short_bucket(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00 UTC');
        $this->withCookie(AppLandingRecorder::COOKIE, str_repeat('b', 64));
        foreach (range(1, 2) as $attempt) {
            $this->get(route('app.store', 'ios'), $this->headers())->assertRedirect(config('mobile_app.ios_url'));
        }
        $this->assertDatabaseCount('app_landing_events', 2);
        Carbon::setTestNow();
    }

    public function test_bots_prefetch_cross_site_head_missing_referrer_and_privacy_opt_out_are_not_counted(): void
    {
        foreach ([
            ['User-Agent' => 'Googlebot'], ['User-Agent' => 'HeadlessChrome'], ['User-Agent' => 'facebookexternalhit'],
            ['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prerender'], ['Sec-Fetch-Site' => 'cross-site'],
            ['Referer' => 'https://evil.example/app'], ['Referer' => route('home')], ['Referer' => ''],
            ['DNT' => '1'], ['Sec-GPC' => '1'],
        ] as $extra) {
            $this->get($this->visit((string) Str::uuid()), $this->headers($extra))->assertNoContent();
            $this->get(route('app.store', 'android'), $this->headers($extra))->assertRedirect(config('mobile_app.android_url'));
        }
        $this->head(route('app.store', 'ios'), $this->headers())->assertRedirect();
        $this->assertDatabaseCount('app_landing_events', 0);
    }

    public function test_admin_testing_does_not_add_visits_or_clicks(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get($this->visit((string) Str::uuid()), $this->headers())->assertNoContent();
        $this->get(route('app.store', 'ios'), $this->headers())->assertRedirect();
        $this->assertDatabaseCount('app_landing_events', 0);
    }

    public function test_trailing_slash_and_campaign_referrer_work_and_rate_limit_does_not_block_store(): void
    {
        $this->get($this->visit((string) Str::uuid()), $this->headers(['Referer' => route('app.download').'/?utm_source=qa']))->assertNoContent();
        $this->assertDatabaseCount('app_landing_events', 1);
        $key = 'app-landing:'.hash_hmac('sha256', '127.0.0.1', config('app.key'));
        RateLimiter::hit($key, 60);
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($key, 60);
        }
        $this->get(route('app.store', 'ios'), $this->headers())->assertRedirect(config('mobile_app.ios_url'));
        $this->assertDatabaseCount('app_landing_events', 1);
    }

    public function test_metric_request_validation_and_download_platform_allowlist(): void
    {
        $this->get($this->visit('invalid'), $this->headers())->assertUnprocessable();
        $this->get($this->visit((string) Str::uuid()), $this->headers(['X-HeroKid-App-Metric' => '']))->assertBadRequest();
        $this->get('/app/download/windows')->assertNotFound();
        $this->assertDatabaseCount('app_landing_events', 0);
    }

    public function test_analytics_failure_never_blocks_download_and_visit_reports_failure_safely(): void
    {
        $recorder = Mockery::mock(AppLandingRecorder::class);
        $recorder->shouldReceive('record')->twice()->andThrow(new \RuntimeException('Secret storage details'));
        $this->app->instance(AppLandingRecorder::class, $recorder);
        Log::spy();
        $this->get(route('app.store', 'ios'), $this->headers())->assertRedirect(config('mobile_app.ios_url'))->assertDontSee('Secret storage details');
        $this->get($this->visit((string) Str::uuid()), $this->headers())->assertStatus(503)->assertDontSee('Secret storage details');
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_report_requires_analytics_permission_and_does_not_require_ga4(): void
    {
        $url = route('admin.app-download-analytics.index');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'user']))->get($url)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->permissions()->sync([]);
        $this->actingAs($admin->fresh())->get($url)->assertForbidden();
        $admin->permissions()->attach(Permission::where('key', 'analytics.view')->firstOrFail());
        config(['analytics.ga4.property_id' => null]);
        $this->actingAs($admin->fresh())->get($url)->assertOk()->assertSee('إحصائيات صفحة التطبيق')->assertSee('ليست تنزيلات أو تثبيتات مؤكدة');
    }

    public function test_summary_uniques_do_not_sum_daily_uniques_and_store_click_counts_are_separate(): void
    {
        foreach ([
            ['a', '2026-10-16', 'view'], ['a', '2026-10-16', 'ios_click'],
            ['a', '2026-10-17', 'view'], ['a', '2026-10-17', 'ios_click'], ['a', '2026-10-17', 'android_click'],
            ['b', '2026-10-17', 'view'], ['b', '2026-10-17', 'ios_click'],
            ['c', '2026-10-17', 'view'], ['c', '2026-10-17', 'android_click'],
            ['z', '2026-10-15', 'view'],
        ] as [$visitor, $day, $type]) {
            $this->event($visitor, $day, $type);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = app(AppLandingAnalytics::class)->report(Request::create('/', 'GET', ['range' => 'custom', 'start_date' => '2026-10-16', 'end_date' => '2026-10-17']));
        $this->assertCount(3, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(['visitors' => 3, 'views' => 4, 'ios_visitors' => 2, 'android_visitors' => 2, 'ios_clicks' => 3, 'android_clicks' => 2, 'clicked_visitors' => 3], $report['summary']);
        $this->assertEquals(4, array_sum(array_column($report['daily'], 'visitors')));
        $this->assertSame(2, count($report['daily']));
        $this->assertSame('2026-10-17', $report['daily'][0]['date']);
    }

    public function test_server_cairo_day_is_used_even_when_client_supplies_a_different_timestamp(): void
    {
        $this->withCookie(AppLandingRecorder::COOKIE, str_repeat('c', 64));
        Carbon::setTestNow('2026-10-17 20:59:59 UTC');
        $this->get($this->visit((string) Str::uuid()).'&occurred_on=2000-01-01', $this->headers())->assertNoContent();
        Carbon::setTestNow('2026-10-17 21:00:00 UTC');
        $this->get($this->visit((string) Str::uuid()), $this->headers())->assertNoContent();
        $this->assertEquals(['2026-10-17', '2026-10-18'], DB::table('app_landing_events')->orderBy('id')->pluck('occurred_on')->all());
        Carbon::setTestNow();
    }

    public function test_empty_period_zero_fills_and_invalid_or_excessive_dates_are_rejected(): void
    {
        $report = app(AppLandingAnalytics::class)->report(Request::create('/', 'GET', ['range' => 'custom', 'start_date' => '2026-10-17', 'end_date' => '2026-10-16']));
        $this->assertSame(0, $report['summary']['visitors']);
        $this->assertSame(2, count($report['daily']));
        $this->assertSame(0, $report['daily'][0]['views']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->permissions()->sync([Permission::where('key', 'analytics.view')->value('id')]);
        $this->actingAs($admin->fresh());
        $this->getJson(route('admin.app-download-analytics.index', ['range' => 'custom', 'start_date' => 'invalid', 'end_date' => '2026-10-17']))->assertUnprocessable();
        $this->getJson(route('admin.app-download-analytics.index', ['range' => 'custom', 'start_date' => '2020-01-01', 'end_date' => '2026-10-17']))->assertUnprocessable();
    }

    private function event(string $visitor, string $day, string $type): void
    {
        DB::table('app_landing_events')->insert(['dedup_key' => hash('sha256', (string) Str::uuid()), 'visitor_hash' => hash('sha256', $visitor), 'event_type' => $type, 'occurred_on' => $day, 'occurred_at' => $day.' 10:00:00']);
    }
}
