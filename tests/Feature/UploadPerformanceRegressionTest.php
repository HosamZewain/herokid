<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use App\Models\TemporaryPhotoUpload;
use App\Models\User;
use App\Services\Analytics\Ga4AnalyticsRepository;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderFinancialStatistics;
use App\Services\Orders\OrderProductPreviewImageService;
use App\Support\RequestSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadPerformanceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_read_once_per_request_and_invalidated_after_an_edit(): void
    {
        config(['cache.default' => 'database']);
        Setting::updateOrCreate(['key' => 'currency_label'], ['value' => 'EGP']);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame('EGP', setting('currency_label'));
            RequestSettings::all();
        }
        $this->assertLessThanOrEqual(2, count(array_filter($queries, fn ($q) => str_contains($q, '`cache`'))));
        Setting::where('key', 'currency_label')->first()->update(['value' => 'ج.م']);
        $this->assertSame('ج.م', setting('currency_label'));
    }

    public function test_sql_financial_totals_match_historical_algorithm_including_deleted_and_legacy_orders(): void
    {
        $story = Story::create(['title' => 'Fixture', 'slug' => 'financial-fixture', 'price' => 100.25, 'active' => true]);
        for ($i = 0; $i < 30; $i++) {
            $order = Order::create([
                'order_number' => 'PERF-'.$i, 'checkout_group_key' => 'PERF-'.intdiv($i, 3),
                'story_id' => $i % 2 ? $story->id : null,
                'parent_name' => 'Fixture', 'child_name' => 'Fixture',
                'status' => $i % 4 === 0 ? 'cancelled' : 'new',
                'shipping_status' => $i % 3 === 0 ? 'shipped' : 'unknown',
                'payment_status' => $i % 2 ? 'paid_in_full' : 'unknown',
                'paid_amount_cents' => $i * 900, 'discount_cents' => $i * 75,
                'delivery_details' => ['delivery_fee' => $i % 2 ? -3 : 50.25, 'item_price' => $i % 5 ? null : 123.45],
            ]);
            if ($i % 6 >= 3) {
                $order->items()->create(['item_type' => $i % 2 ? 'story' : 'product', 'title' => 'Fixture', 'quantity' => 2, 'unit_price_cents' => 7500, 'total_price_cents' => 15000]);
            }
            if ($i % 4 === 0 || $i >= 27) {
                $order->delete();
            }
        }
        $service = app(AdminOrderGroupService::class);
        $keys = Order::withTrashed()->distinct()->pluck('checkout_group_key');
        foreach ([false, true] as $deleted) {
            $orders = (new \ReflectionMethod($service, 'ordersForStats'))->invoke($service, $keys, $deleted);
            $visible = (new \ReflectionMethod($service, 'visibleOrdersForStats'))->invoke($service, $orders, $deleted);
            $expected = (new \ReflectionMethod($service, 'financialStats'))->invoke($service, $visible);
            $actual = app(OrderFinancialStatistics::class)->summarize($keys, $deleted);
            $this->assertEquals($expected, array_intersect_key($actual, $expected));
        }
        $this->assertSame(0, app(OrderFinancialStatistics::class)->summarize([], true)['checkouts']);
    }

    public function test_restoration_only_returns_live_unattached_uploads_owned_by_current_session(): void
    {
        Storage::fake('local');
        $session = $this->getJson(route('photo-uploads.session'))->assertOk()->json();
        $this->assertNotEmpty($session['csrf_token']);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->postJson(route('photo-uploads.store'), [
                'upload_session_token' => $session['upload_session_token'],
                'photo' => UploadedFile::fake()->image('test.jpg'),
            ])->assertCreated()->json('id');
        }
        TemporaryPhotoUpload::where('public_id', $ids[1])->update(['expires_at' => now()->subMinute()]);
        TemporaryPhotoUpload::where('public_id', $ids[2])->update(['status' => 'attached']);
        $this->getJson(route('photo-uploads.session', ['ids' => $ids]))->assertOk()->assertJsonPath('valid_upload_ids', [$ids[0]]);
        $this->withSession(['photo_upload.token' => 'different-session'])
            ->getJson(route('photo-uploads.session', ['ids' => $ids]))->assertOk()->assertJsonPath('valid_upload_ids', []);
    }

    public function test_admin_thumbnail_is_small_private_revalidated_and_permission_protected(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('child.jpg', 1200, 900)->store('photos', 'local');
        $order = Order::create(['order_number' => 'THUMB-1', 'parent_name' => 'Fixture', 'status' => 'new', 'uploaded_photos' => [$path]]);
        $url = route('admin.orders.photo', [$order, 0, 'thumbnail' => 1]);
        $this->get($url)->assertRedirect();
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get($url)->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $size = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertLessThanOrEqual(400, max($size[0], $size[1]));
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get($url)->assertStatus(304);
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        Storage::disk('local')->assertExists($path);
    }

    public function test_dashboard_does_not_call_external_analytics_during_navigation(): void
    {
        Http::preventStrayRequests();
        $this->mock(Ga4AnalyticsRepository::class)->shouldNotReceive('widget');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard.index'))->assertOk()->assertSee('data-analytics-widget', false);
    }

    public function test_ajax_attachment_upload_returns_json_and_preserves_private_storage(): void
    {
        Storage::fake('local');
        $order = Order::create(['order_number' => 'AJAX-1', 'parent_name' => 'Fixture', 'status' => 'new']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('admin.orders.attachments.store', $order), [
            'attachments' => [UploadedFile::fake()->image('reference.jpg')],
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseCount('order_attachments', 1);
        Storage::disk('local')->assertExists($order->attachments()->first()->path);
    }

    public function test_admin_preview_thumbnail_does_not_generate_customer_watermark_and_is_not_public(): void
    {
        Storage::fake('local');
        $order = Order::create(['order_number' => 'PREVIEW-THUMB', 'parent_name' => 'Fixture', 'status' => 'new']);
        $this->mock(OrderProductPreviewImageService::class)->shouldNotReceive('customerImage');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.orders.product-previews.store', $order), ['preview_images' => [UploadedFile::fake()->image('preview.jpg', 900, 800)]])
            ->assertOk()->assertJsonPath('success', true);
        $preview = $order->previews()->first();
        $url = route('admin.orders.product-previews.thumbnail', $preview);
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $size = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertLessThanOrEqual(400, max($size[0], $size[1]));
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    public function test_thumbnail_cleanup_removes_only_old_derived_files(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('order-thumbnails/aa/old.jpg', 'old-cache');
        $disk->put('order-thumbnails/aa/new.jpg', 'new-cache');
        $disk->put('photos/original.jpg', 'original');
        touch($disk->path('order-thumbnails/aa/old.jpg'), now()->subDays(8)->timestamp);
        $this->artisan('order-thumbnails:cleanup')->assertSuccessful();
        $disk->assertMissing('order-thumbnails/aa/old.jpg');
        $disk->assertExists('order-thumbnails/aa/new.jpg');
        $disk->assertExists('photos/original.jpg');
    }

    public function test_order_list_hydrates_only_its_page_even_when_statistics_cover_more_orders(): void
    {
        for ($i = 0; $i < 60; $i++) {
            Order::create(['order_number' => 'BOUNDED-'.$i, 'checkout_group_key' => 'BOUNDED-'.$i, 'parent_name' => 'Fixture', 'status' => 'new']);
        }
        $hydrated = 0;
        Order::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.orders.index', ['catalog_type' => 'all', 'q' => 'BOUNDED-', 'per_page' => 25]))->assertOk();
        $this->assertLessThanOrEqual(50, $hydrated);
    }
}
