<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Analytics\AnalyticsDateRange;
use App\Services\Analytics\Ga4AnalyticsClient;
use App\Services\Analytics\Ga4AnalyticsRepository;
use App\Services\Analytics\Ga4ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_setup_state_when_ga4_is_missing(): void
    {
        config([
            'analytics.ga4.property_id' => null,
            'analytics.ga4.credentials_path' => null,
            'analytics.ga4.credentials_base64' => null,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.analytics.index'))
            ->assertOk()
            ->assertSee('إعداد Google Analytics غير مكتمل');
    }

    public function test_non_admin_cannot_view_analytics(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('admin.analytics.index'))
            ->assertForbidden();
    }

    public function test_api_failure_shows_safe_error_message(): void
    {
        $this->configureFakeCredentials();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token'], 200),
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'Bad request']], 400),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.analytics.index'))
            ->assertOk()
            ->assertSee('تعذر تحميل بيانات التحليلات')
            ->assertDontSee('test-token');
    }

    public function test_repository_uses_cache_for_repeated_dashboard_requests(): void
    {
        Cache::flush();
        $this->configureFakeCredentials();

        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'test-token'], 200);
            }

            if (str_contains((string) $request->url(), 'runRealtimeReport')) {
                return Http::response(['rows' => [['metricValues' => [['value' => '3']]]]], 200);
            }

            return Http::response(['rows' => []], 200);
        });

        $repository = app(Ga4AnalyticsRepository::class);
        $range = new AnalyticsDateRange('last_7_days', 'آخر 7 أيام', '2026-07-04', '2026-07-10');

        $repository->dashboard($range);
        $firstCount = count(Http::recorded());
        $repository->dashboard($range);
        $secondCount = count(Http::recorded());

        $this->assertSame($firstCount, $secondCount);
    }

    public function test_ga4_client_uses_v1beta_endpoint_for_standard_and_realtime_reports(): void
    {
        Cache::flush();
        $this->configureFakeCredentials();

        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'test-token'], 200);
            }

            return Http::response(['rows' => []], 200);
        });

        $client = app(Ga4AnalyticsClient::class);

        $client->runReport(['metrics' => [['name' => 'activeUsers']]]);
        $client->runRealtimeReport(['metrics' => [['name' => 'activeUsers']]]);

        $urls = collect(Http::recorded())->map(fn (array $record) => (string) $record[0]->url());

        $this->assertTrue($urls->contains('https://analyticsdata.googleapis.com/v1beta/properties/123456:runReport'));
        $this->assertTrue($urls->contains('https://analyticsdata.googleapis.com/v1beta/properties/123456:runRealtimeReport'));
    }

    public function test_ga4_404_response_logs_diagnostic_endpoint_context(): void
    {
        Cache::flush();
        $this->configureFakeCredentials();
        Log::spy();

        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'test-token'], 200);
            }

            return Http::response(['error' => ['message' => 'Endpoint not found']], 404);
        });

        try {
            app(Ga4AnalyticsClient::class)->runReport(['metrics' => [['name' => 'activeUsers']]]);
            $this->fail('Expected GA4 API exception was not thrown.');
        } catch (Ga4ApiException) {
            // Expected.
        }

        Log::shouldHaveReceived('warning')
            ->with('GA4 API returned an error.', \Mockery::on(function (array $context): bool {
                return ($context['method'] ?? null) === 'runReport'
                    && ($context['property_id'] ?? null) === '123456'
                    && ($context['api_base_url'] ?? null) === 'https://analyticsdata.googleapis.com/v1beta'
                    && ($context['url'] ?? null) === 'https://analyticsdata.googleapis.com/v1beta/properties/123456:runReport'
                    && ($context['status'] ?? null) === 404
                    && ($context['error'] ?? null) === 'Endpoint not found';
            }))
            ->once();
    }

    public function test_refresh_route_clears_analytics_cache(): void
    {
        config(['analytics.ga4.property_id' => '123456']);
        Cache::put('analytics:ga4:123456:cache-keys', ['analytics:ga4:123456:test'], now()->addHour());
        Cache::put('analytics:ga4:123456:test', 'cached', now()->addHour());
        Cache::put('analytics:local-cart:cache-keys', ['analytics:local-cart:test'], now()->addHour());
        Cache::put('analytics:local-cart:test', 'cached', now()->addHour());

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.analytics.refresh'))
            ->assertRedirect();

        $this->assertFalse(Cache::has('analytics:ga4:123456:test'));
        $this->assertFalse(Cache::has('analytics:local-cart:test'));
    }

    private function configureFakeCredentials(): void
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $privateKey);

        config([
            'analytics.ga4.property_id' => '123456',
            'analytics.ga4.credentials_path' => null,
            'analytics.ga4.credentials_base64' => base64_encode(json_encode([
                'client_email' => 'analytics@example.test',
                'private_key' => $privateKey,
            ], JSON_THROW_ON_ERROR)),
        ]);
    }
}
