<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMobileGuestScope;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\MobilePromoCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileGuestSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_has_a_secret_expiring_commerce_session_without_registration(): void
    {
        $response = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.guest', true)->assertJsonPath('data.principal.email', null)->assertJsonPath('data.principal.phone', null);
        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/guest-session')->assertOk()->assertJsonPath('data.guest', true);
        $this->withToken($token)->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertSame('mobile_guest', User::findOrFail($response->json('data.principal.id'))->role);
        $this->withToken($token)->postJson('/api/v1/children', ['name' => 'Synthetic child', 'age' => 6, 'preferred_language' => 'ar'])->assertCreated();
    }

    public function test_guest_session_cannot_access_account_or_private_library_or_notifications(): void
    {
        // This private module is not released on main yet. Verify that the
        // authenticated group's scope also denies future private library routes.
        Route::get('api/v1/library/books', fn () => response()->json(['data' => []]))
            ->middleware(['auth:sanctum', EnsureMobileGuestScope::class]);
        $session = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated()->json('data');
        foreach (['/me', '/library/books', '/devices', '/notifications', '/sessions', '/privacy/consents', '/favorites', '/drafts'] as $path) {
            $this->withToken($session['token'])->getJson('/api/v1'.$path)->assertForbidden();
        }
        $this->withToken($session['token'])->postJson('/api/v1/uploads', ['purpose' => 'identity'])->assertForbidden();
    }

    public function test_installation_uuid_does_not_recover_another_guests_data(): void
    {
        $installation = (string) Str::uuid();
        $first = $this->postJson('/api/v1/guest-session', ['installation_id' => $installation])->json('data');
        $second = $this->postJson('/api/v1/guest-session', ['installation_id' => $installation])->json('data');
        $this->assertNotSame($first['principal']['id'], $second['principal']['id']);
        $child = $this->withToken($first['token'])->postJson('/api/v1/children', ['name' => 'Private fixture', 'preferred_language' => 'ar'])->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->withToken($second['token'])->getJson('/api/v1/children/'.$child)->assertNotFound();
    }

    public function test_guest_can_upload_private_photos_and_complete_checkout_once_without_registration(): void
    {
        Storage::fake('local');
        Setting::updateOrCreate(['key' => 'story_global_price_enabled'], ['value' => '0']);
        Setting::updateOrCreate(['key' => 'cash_on_delivery_enabled'], ['value' => '1']);
        $guest = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->withToken($guest['token']);
        $child = $this->postJson('/api/v1/children', ['name' => 'Synthetic QA', 'age' => 6, 'gender' => 'boy', 'preferred_language' => 'ar'])->assertCreated()->json('data.id');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z1h8AAAAASUVORK5CYII=', true);
        $photos = [];
        $uploadIds = [];
        foreach (['one.png', 'two.png'] as $filename) {
            $upload = $this->postJson('/api/v1/uploads', ['purpose' => 'child_reference', 'child_profile_id' => $child, 'filename' => $filename, 'mime_type' => 'image/png', 'size' => strlen($bytes)])->assertCreated()->json('data.id');
            $uploadIds[] = $upload;
            $this->getJson('/api/v1/uploads/'.$upload)->assertOk()->assertJsonPath('data.status', 'pending');
            $this->post('/api/v1/uploads/'.$upload.'/chunks/0', ['chunk' => UploadedFile::fake()->createWithContent('part', $bytes), 'checksum' => hash('sha256', $bytes)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'completed');
            $photos[] = $this->postJson('/api/v1/uploads/'.$upload.'/attach-child-photo', ['child_profile_id' => $child, 'reuse_consent' => true])->assertCreated()->json('data.id');
        }
        $this->getJson('/api/v1/children/'.$child.'/photos')->assertOk()->assertJsonCount(2, 'data');
        $media = '/api/v1/children/'.$child.'/photos/'.$photos[0].'/media';
        $this->get($media, ['Accept' => 'image/png'])->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $story = Story::create(['title' => 'Guest synthetic fixture', 'slug' => 'guest-qa-fixture', 'short_desc' => 'QA', 'language' => 'ar', 'gender' => 'both', 'age_range' => '6-9', 'price' => 200, 'active' => true]);
        $item = $this->postJson('/api/v1/cart/items', ['item_type' => 'story', 'story_id' => $story->id, 'child_profile_id' => $child, 'child_photo_ids' => $photos, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.totals.subtotal', 200)->json('data.items.0.id');
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $governorate->update(['delivery_fee' => 50, 'active' => true]);
        $address = $this->postJson('/api/v1/addresses', ['recipient_name' => 'Guest QA', 'phone' => '01012345678', 'delivery_country_id' => $country->id, 'delivery_governorate_id' => $governorate->id, 'city' => 'QA', 'street' => 'Test street', 'details' => 'Test fixture', 'is_default' => true])->assertCreated()->json('data.id');
        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address])->assertOk()->json('data');
        $payload = ['address_id' => $address, 'payment_method' => 'cash_on_delivery', 'terms_accepted' => true, 'terms_document_version' => '2026-08-03', 'image_processing_consent' => true, 'consent_document_version' => '2026-08-03', 'idempotency_key' => (string) Str::uuid(), 'quote_fingerprint' => $quote['fingerprint']];
        $orderId = $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.status', 'completed')->json('data.orders.0.id');
        $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.orders.0.id', $orderId);
        $this->assertDatabaseCount('orders', 1);
        $order = Order::findOrFail($orderId);
        $this->assertSame($guest['principal']['id'], $order->user_id);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertCount(2, $order->uploaded_photos);
        foreach ($order->uploaded_photos as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->getJson('/api/v1/orders/'.$orderId)->assertOk();
        $this->app['auth']->forgetGuards();
        $other = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->app['auth']->forgetGuards();
        $this->withToken($other['token']);
        foreach ([$media, '/api/v1/uploads/'.$uploadIds[0], '/api/v1/addresses/'.$address, '/api/v1/orders/'.$orderId] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->patchJson('/api/v1/cart/items/'.$item, ['quantity' => 2])->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $guest['principal']['id'], 'email' => null, 'phone' => null, 'role' => 'mobile_guest']);
    }

    public function test_revoked_and_expired_guest_tokens_cannot_access_commerce(): void
    {
        $guest = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->json('data');
        $this->withToken($guest['token'])->deleteJson('/api/v1/guest-session')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/cart')->assertUnauthorized();
        $guest = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->json('data');
        PersonalAccessToken::findToken($guest['token'])->update(['expires_at' => now()->subMinute()]);
        $this->app['auth']->forgetGuards();
        $this->withToken($guest['token'])->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_guest_cannot_bypass_account_limited_discount_codes(): void
    {
        MobilePromoCode::create(['code' => 'ACCOUNT-ONLY', 'discount_type' => 'fixed', 'discount_value' => 1000, 'per_user_limit' => 1, 'is_active' => true]);
        $guest = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->json('data');
        $this->withToken($guest['token'])->putJson('/api/v1/cart/promo-code', ['code' => 'ACCOUNT-ONLY'])->assertUnprocessable();
        $this->putJson('/api/v1/cart/promo-code', ['code' => ['ACCOUNT-ONLY']])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_only_a_secret_token_can_restore_a_session_and_it_expires_after_seven_days(): void
    {
        $this->freezeTime();
        foreach ([[], ['installation_id' => 'invalid']] as $payload) {
            $this->postJson('/api/v1/guest-session', $payload)->assertUnprocessable()->assertJsonValidationErrors('installation_id');
        }
        $this->assertDatabaseCount('users', 0);
        $installation = (string) Str::uuid();
        $session = $this->postJson('/api/v1/guest-session', [
            'installation_id' => $installation, 'email' => 'ignored@example.test',
            'phone' => '01012345678', 'role' => 'admin',
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $token = PersonalAccessToken::findToken($session['token']);
        $this->assertSame(now()->addDays(7)->timestamp, $token->expires_at->timestamp);
        $this->assertSame(['mobile:guest'], $token->abilities);
        $this->assertSame(hash('sha256', explode('|', $session['token'], 2)[1]), $token->token);
        $this->assertDatabaseHas('users', ['id' => $session['principal']['id'], 'role' => 'mobile_guest', 'email' => null, 'phone' => null]);
        $this->getJson('/api/v1/guest-session?installation_id='.$installation)->assertUnauthorized();
        $this->deleteJson('/api/v1/guest-session', ['installation_id' => $installation])->assertUnauthorized();
        $this->withToken($session['token'])->getJson('/api/v1/guest-session')
            ->assertOk()->assertJsonPath('data.principal.id', $session['principal']['id'])->assertJsonMissingPath('data.token');
        $this->travel(7)->days();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/guest-session')->assertUnauthorized();
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_guest_scope_is_enforced_for_writes_and_requires_a_scoped_expiring_bearer_token(): void
    {
        $session = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->withToken($session['token']);
        foreach ([
            ['PATCH', 'me'], ['POST', 'devices'], ['POST', 'favorites'], ['POST', 'drafts'],
            ['POST', 'notifications/read-all'], ['POST', 'privacy/requests'], ['POST', 'child-identities'],
            ['POST', 'auth/logout-all'],
        ] as [$method, $path]) {
            $this->json($method, '/api/v1/'.$path, [])->assertForbidden();
        }
        foreach (['identity', 'review', 'personalization'] as $purpose) {
            $this->postJson('/api/v1/uploads', ['purpose' => $purpose])->assertForbidden();
        }
        $this->getJson('/api/agent/studio/connection')->assertForbidden();
        $this->getJson('/admin')->assertForbidden();
        $this->assertFalse(User::findOrFail($session['principal']['id'])->isAdmin());
        $this->assertDatabaseCount('device_installations', 0);
        $this->assertDatabaseCount('child_identity_requests', 0);

        $guest = User::findOrFail($session['principal']['id']);
        foreach ([['mobile'], ['mobile:guest']] as $abilities) {
            $token = $guest->createToken('invalid-scope', $abilities, $abilities === ['mobile'] ? now()->addDay() : null);
            $this->app['auth']->forgetGuards();
            $this->withToken($token->plainTextToken)->getJson('/api/v1/cart')->assertForbidden();
        }
    }

    public function test_existing_customer_sessions_are_not_changed_or_revoked_by_guest_endpoints(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('existing-customer', ['mobile'], now()->addDays(90));
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.id', $customer->id);
        $this->getJson('/api/v1/guest-session')->assertNotFound();
        $this->deleteJson('/api/v1/guest-session')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertOk();
        $this->assertNotNull(PersonalAccessToken::findToken($token->plainTextToken));
    }

    public function test_guest_photo_upload_resumes_out_of_order_without_duplicate_chunks(): void
    {
        Storage::fake('local');
        $guest = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->withToken($guest['token']);
        $child = $this->postJson('/api/v1/children', ['name' => 'Resumable fixture'])->assertCreated()->json('data.id');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z1h8AAAAASUVORK5CYII=', true).str_repeat("\0", 1024 * 1024 + 311);
        $upload = $this->postJson('/api/v1/uploads', ['purpose' => 'child_reference', 'child_profile_id' => $child, 'filename' => 'resume.png', 'mime_type' => 'image/png', 'size' => strlen($bytes)])->assertCreated()->json('data');
        $chunks = str_split($bytes, $upload['chunk_size']);
        $chunk = ['chunk' => UploadedFile::fake()->createWithContent('part', $chunks[1]), 'checksum' => hash('sha256', $chunks[1])];
        $this->post('/api/v1/uploads/'.$upload['id'].'/chunks/1', $chunk, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'uploading');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/uploads/'.$upload['id'])->assertOk()->assertJsonPath('data.received_chunks', [1]);
        $this->post('/api/v1/uploads/'.$upload['id'].'/chunks/1', $chunk, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.received_size', strlen($chunks[1]));
        $this->post('/api/v1/uploads/'.$upload['id'].'/chunks/0', ['chunk' => UploadedFile::fake()->createWithContent('part', $chunks[0]), 'checksum' => hash('sha256', $chunks[0])], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.received_size', strlen($bytes));
        $this->postJson('/api/v1/uploads/'.$upload['id'].'/attach-child-photo', ['child_profile_id' => $child, 'reuse_consent' => true])->assertCreated();
        $this->assertDatabaseCount('child_profile_photos', 1);
    }

    public function test_guest_creation_is_rate_limited_without_creating_extra_principals(): void
    {
        foreach (range(1, 10) as $index) {
            $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated();
        }
        $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertStatus(429);
        $this->assertDatabaseCount('users', 10);
    }

    public function test_guest_batch_cart_reuses_server_prices_and_idempotency_and_rolls_back_invalid_items(): void
    {
        $category = ProductCategory::create(['name_ar' => 'Guest QA', 'slug' => 'guest-qa', 'is_active' => true, 'show_in_store' => true]);
        $product = Product::create(['product_category_id' => $category->id, 'name_ar' => 'Guest product fixture', 'slug' => 'guest-product-fixture', 'price_cents' => 9000, 'is_active' => true, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone', 'personalization_mode' => 'none', 'inventory_mode' => 'no_tracking']);
        $guest = $this->postJson('/api/v1/guest-session', ['installation_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->withToken($guest['token']);
        $item = ['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 2, 'idempotency_key' => (string) Str::uuid(), 'client_price' => 1];
        $this->postJson('/api/v1/cart/items/batch', ['items' => [$item, array_replace($item, ['product_id' => 999999, 'idempotency_key' => (string) Str::uuid()])]])->assertUnprocessable();
        $this->assertDatabaseCount('mobile_cart_items', 0);
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/cart/items/batch', ['items' => [$item]])->assertCreated()->assertJsonCount(1, 'data.items')->assertJsonPath('data.totals.subtotal', 180);
        }
        $this->assertDatabaseCount('mobile_cart_items', 1);
    }
}
