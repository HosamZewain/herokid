<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\TemporaryPhotoUpload;
use App\Models\User;
use App\Services\Bosta\BostaAddressCatalogService;
use App\Services\Bosta\BostaAddressResolver;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\AdminOrderUpdateService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\Orders\OrderAttachmentService;
use App\Support\OrderDeliveryAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Synthetic regression coverage for the priority audit findings.
class PriorityRemediationTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        $order = Order::create([
            'order_number' => 'AUDIT-SYNTHETIC', 'checkout_group_key' => 'CHK-AUDIT-SYNTHETIC',
            'parent_name' => 'Synthetic Parent', 'status' => 'new', 'payment_status' => 'unpaid',
            'printing_status' => 'not_started', 'shipping_status' => 'not_ready',
            'delivery_details' => ['phone' => '01012345678', 'country' => 'مصر',
                'governorate' => 'القاهرة', 'city' => 'مدينة نصر', 'street' => 'Synthetic Private Street',
                'address_details' => 'Synthetic address', 'delivery_fee' => 50,
                'bosta_city_id' => 'OLD-CITY', 'bosta_district_id' => 'OLD-DISTRICT'],
        ]);
        $order->items()->create(['item_type' => 'product', 'title' => 'Synthetic Product',
            'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);

        return $order->fresh(['checkoutReference']);
    }

    public function test_later_child_validation_failure_preserves_uploads_and_allows_retry(): void
    {
        Storage::fake('local');
        $product = Product::create(['name_ar' => 'Synthetic Photo Product', 'slug' => 'audit-photo-product',
            'price_cents' => 10000, 'is_active' => true, 'purchase_mode' => 'standalone',
            'inventory_mode' => 'no_tracking', 'personalization_mode' => 'collect_child_details',
            'personalization_fields' => ['fields' => [
                'child_name' => ['enabled' => true, 'required' => true],
                'photos' => ['enabled' => true, 'required' => true, 'min_files' => 1, 'max_files' => 3],
            ]],
        ]);
        $session = $this->getJson(route('photo-uploads.session'))->assertOk()->json();
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = $this->postJson(route('photo-uploads.store'), [
                'upload_session_token' => $session['upload_session_token'],
                'photo' => UploadedFile::fake()->image('synthetic.jpg'),
            ])->assertCreated()->json('id');
        }
        $payload = ['quantity' => 2, 'upload_session_token' => $session['upload_session_token'],
            'personalizations' => [
                ['child_name' => 'Synthetic First', 'photo_upload_ids' => [$ids[0]]],
                ['child_name' => '', 'photo_upload_ids' => [$ids[1]]],
            ]];
        $this->postJson(route('cart.products.store', $product), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('personalizations.1.child_name');
        $this->assertSame('uploaded', TemporaryPhotoUpload::where('public_id', $ids[0])->value('status'));
        $this->assertEmpty(session('cart.items', []));
        $payload['personalizations'][1]['child_name'] = 'Synthetic Second';
        $duplicatePayload = $payload;
        $duplicatePayload['personalizations'][1]['photo_upload_ids'] = [$ids[0]];
        $this->postJson(route('cart.products.store', $product), $duplicatePayload)
            ->assertUnprocessable()->assertJsonValidationErrors('personalizations.1.photo_upload_ids');
        $this->assertSame('uploaded', TemporaryPhotoUpload::where('public_id', $ids[0])->value('status'));
        $this->assertEmpty(session('cart.items', []));
        $this->postJson(route('cart.products.store', $product), $payload)
            ->assertOk();
        $this->assertCount(2, session('cart.items'));
    }

    public function test_customer_address_edit_discards_obsolete_bosta_destination_ids(): void
    {
        $order = $this->order();
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)
            ->where('name', 'الإسكندرية')->firstOrFail();
        $country->update(['active' => true]);
        $governorate->update(['active' => true]);
        $reference = $order->checkoutReference->short_reference;
        $this->post(route('track.search'), ['phone' => '01012345678', 'order_number' => $reference])->assertOk();
        $this->put(route('track.update', $reference), [
            'parent_name' => 'Synthetic Parent', 'phone' => '01012345678',
            'delivery_country_id' => $country->id, 'delivery_governorate_id' => $governorate->id,
            'city' => 'Synthetic New Area', 'street' => 'Synthetic New Street',
            'address_details' => 'Synthetic New Address',
        ])->assertRedirect()->assertSessionHas('success');
        $delivery = $order->fresh()->delivery_details;
        $this->assertSame('الإسكندرية', $delivery['governorate']);
        $this->assertArrayNotHasKey('bosta_city_id', $delivery);
        $this->assertArrayNotHasKey('bosta_district_id', $delivery);
        $this->mock(BostaAddressCatalogService::class, function ($mock): void {
            $mock->shouldReceive('findCityByName')->with('الإسكندرية')->once()->andReturn(['id' => 'NEW-CITY', 'name' => 'Alexandria']);
        });
        $resolved = app(BostaAddressResolver::class)->resolve($delivery);
        $this->assertSame('NEW-CITY', $resolved['cityId']);
        $this->assertSame('Synthetic New Area', $resolved['districtName']);
        $this->assertSame('Synthetic New Street', $resolved['firstLine']);
    }

    public function test_replaying_a_checkout_cart_snapshot_reuses_the_existing_purchase(): void
    {
        Http::fake();
        config(['bosta.enabled' => false]);
        $product = Product::create(['name_ar' => 'Synthetic Product', 'slug' => 'audit-checkout-product',
            'price_cents' => 10000, 'is_active' => true, 'purchase_mode' => 'standalone',
            'inventory_mode' => 'no_tracking', 'personalization_mode' => 'none']);
        $added = $this->postJson(route('cart.products.store', $product), ['quantity' => 1])->assertOk();
        $cart = session('cart.items');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
        $this->get(route('cart.index'))->assertOk()->assertSee($added->json('checkout_submission_token'));
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->where('name', 'القاهرة')->firstOrFail();
        $country->update(['active' => true]);
        $governorate->update(['active' => true]);
        $payload = ['parent_name' => 'Synthetic Parent', 'phone' => '01012345678',
            'delivery_country_id' => $country->id, 'delivery_governorate_id' => $governorate->id,
            'city' => 'Synthetic Area', 'street' => 'Synthetic Street', 'address_details' => 'Synthetic Address'];
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('checkout.success'));
        $firstId = Order::firstOrFail()->id;
        // Models two requests that loaded the same cart before either cleared the session.
        $this->withSession(['cart.items' => $cart]);
        session()->save();
        $this->post(route('checkout.store'), $payload)
            ->assertRedirect(route('checkout.success'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, Order::distinct()->count('checkout_group_key'));
        $this->assertSame($firstId, Order::latest('id')->first()->id);
        $payload['checkout_submission_token'] = DB::table('checkout_submissions')->value('key_hash');
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('checkout.success'));
        $this->assertDatabaseCount('orders', 1);
        // Retrying the previous form must not clear a newer, intentional cart.
        $newAddition = $this->postJson(route('cart.products.store', $product), ['quantity' => 1])->assertOk();
        $newCart = session('cart.items');
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('checkout.success'));
        $this->assertSame($newCart, session('cart.items'));
        $payload['checkout_submission_token'] = $newAddition->json('checkout_submission_token');
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('checkout.success'));
        $this->assertDatabaseCount('orders', 2);
        $kept = $this->postJson(route('cart.products.store', $product), ['quantity' => 1])->assertOk();
        $removed = $this->postJson(route('cart.products.store', $product), ['quantity' => 1])->assertOk();
        $removal = $this->deleteJson(route('cart.destroy', $removed->json('item_key')))->assertOk();
        $this->assertSame($kept->json('checkout_submission_token'), $removal->json('checkout_submission_token'));
        $payload['checkout_submission_token'] = $removal->json('checkout_submission_token');
        $this->post(route('checkout.store'), $payload)->assertRedirect(route('checkout.success'));
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_failed_order_edit_preserves_a_different_orders_concurrent_photo(): void
    {
        Storage::fake('local');
        config(['photo_uploads.disk' => 'local']);
        $order = $this->order();
        $otherPath = 'orders/photos/another-order/concurrent-synthetic.jpg';
        $otherOrder = Order::create(['order_number' => 'AUDIT-OTHER', 'checkout_group_key' => 'CHK-AUDIT-OTHER',
            'parent_name' => 'Synthetic Other Parent', 'status' => 'new', 'uploaded_photos' => [$otherPath]]);
        $this->mock(AdminOrderGroupService::class, function ($mock) use ($otherPath): void {
            $mock->shouldReceive('present')->once()->andReturnUsing(function () use ($otherPath): void {
                // Interleave an unrelated upload after the edit's initial global directory listing.
                Storage::disk('local')->put($otherPath, 'synthetic independent upload');
                throw new \RuntimeException('Synthetic edit failure');
            });
        });
        try {
            app(AdminOrderUpdateService::class)->update($order, [], User::factory()->create(['role' => 'admin']), request());
            $this->fail('Expected the simulated edit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic edit failure', $exception->getMessage());
        }
        Storage::disk('local')->assertExists($otherPath);
        $this->assertSame([$otherPath], $otherOrder->fresh()->uploaded_photos);
    }

    public function test_street_and_contact_edits_preserve_provider_mapping_and_other_metadata(): void
    {
        $previous = ['city' => 'مدينة نصر', 'bosta_city_id' => 'CITY', 'bosta_district_id' => 'DISTRICT',
            'bosta_zone_id' => 'ZONE', 'alternate_phone' => '+971501234567', 'delivery_fee' => 50];
        $result = OrderDeliveryAddress::mergeEdited($previous, ['city' => 'مدينة نصر', 'street' => 'شارع جديد', 'phone' => '01012345678']);
        foreach ($previous as $key => $value) {
            $this->assertSame($value, $result[$key]);
        }
        $this->assertSame('شارع جديد', $result['street']);
    }

    public function test_submission_rollback_releases_claim_and_new_cart_can_be_purchased(): void
    {
        $request = Request::create('/checkout', 'POST');
        $request->setLaravelSession(app('session.store'));
        $service = app(CheckoutSubmissionService::class);
        $cart = ['first-unique-cart-item' => ['product_id' => 1]];
        try {
            DB::transaction(function () use ($service, $request, $cart): void {
                $service->claim($request, $cart);
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('checkout_submissions', 0);
        DB::transaction(function () use ($service, $request, $cart): void {
            $claim = $service->claim($request, $cart);
            $this->assertNull($claim['order_ids']);
            $service->complete($claim['key'], [123]);
        });
        $this->assertSame([123], DB::transaction(fn () => $service->claim($request, $cart))['order_ids']);
        $newCart = ['second-unique-cart-item' => ['product_id' => 1]];
        $this->assertNull(DB::transaction(fn () => $service->claim($request, $newCart))['order_ids']);
        $this->assertDatabaseCount('checkout_submissions', 2);
        $request->merge(['checkout_submission_token' => $service->token($request, $cart)]);
        $this->assertSame([123], $service->completed($request));
        $request->session()->regenerate();
        $this->assertNull($service->completed($request));
    }

    public function test_invalid_submission_key_cannot_claim_current_cart(): void
    {
        $request = Request::create('/checkout', 'POST', ['checkout_submission_token' => str_repeat('a', 64)]);
        $request->setLaravelSession(app('session.store'));
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(CheckoutSubmissionService::class)->claim($request, ['unique-cart-item' => ['product_id' => 1]]));
    }

    public function test_tracking_search_has_defense_in_depth_throttling(): void
    {
        $route = app('router')->getRoutes()->getByName('track.search');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    public function test_attachment_contract_still_accepts_large_pdf_and_rejects_oversize_or_executable_files(): void
    {
        $rules = ['file' => OrderAttachmentService::fileRules()];
        $this->assertTrue(Validator::make(['file' => UploadedFile::fake()->create('preview.pdf', 25 * 1024, 'application/pdf')], $rules)->passes());
        $this->assertTrue(Validator::make(['file' => UploadedFile::fake()->create('preview.pdf', 50 * 1024, 'application/pdf')], $rules)->passes());
        $this->assertTrue(Validator::make(['file' => UploadedFile::fake()->create('preview.pdf', 51 * 1024, 'application/pdf')], $rules)->fails());
        $this->assertTrue(Validator::make(['file' => UploadedFile::fake()->create('script.php', 1, 'application/x-httpd-php')], $rules)->fails());
        $ini = parse_ini_file(public_path('.user.ini'));
        $this->assertGreaterThan(50, (int) $ini['upload_max_filesize']);
        $this->assertGreaterThan((int) $ini['upload_max_filesize'], (int) $ini['post_max_size']);
    }
}
