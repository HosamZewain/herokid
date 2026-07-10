<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Story;
use App\Models\TemporaryPhotoUpload;
use App\Models\User;
use App\Services\Uploads\TemporaryPhotoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TemporaryPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_upload_one_child_photo_and_attach_it_to_cart_without_resending_file(): void
    {
        Storage::fake('local');
        $story = $this->story();
        $sessionToken = $this->uploadSessionToken();

        $uploadResponse = $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => $this->tinyPngUpload(),
        ])->assertCreated();

        $uploadId = $uploadResponse->json('id');

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), $this->cartPayload([
                'upload_session_token' => $sessionToken,
                'photo_upload_ids' => [$uploadId],
            ]))
            ->assertRedirect(route('cart.index'))
            ->assertSessionDoesntHaveErrors();

        $cartItem = collect(session('cart.items'))->first();
        $this->assertCount(1, $cartItem['uploaded_photos']);
        $this->assertStringStartsWith('temporary-uploads/child-photos/', $cartItem['uploaded_photos'][0]);
        $this->assertDatabaseHas('temporary_photo_uploads', [
            'public_id' => $uploadId,
            'status' => 'attached',
            'attached_cart_key' => $cartItem['key'],
        ]);
    }

    public function test_checkout_marks_attached_temporary_uploads_with_final_order(): void
    {
        Storage::fake('local');
        $story = $this->story();
        $sessionToken = $this->uploadSessionToken();
        $uploadId = $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => $this->tinyPngUpload(),
        ])->assertCreated()->json('id');

        $this->post(route('cart.store', $story->slug), $this->cartPayload([
            'upload_session_token' => $sessionToken,
            'photo_upload_ids' => [$uploadId],
        ]))->assertRedirect(route('cart.index'));

        [$egypt, $cairo] = $this->deliveryZone();
        $this->post(route('checkout.store'), [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $egypt->id,
            'delivery_governorate_id' => $cairo->id,
            'city' => 'Nasr City',
            'street' => 'Street 1',
            'address_details' => 'Building 2',
        ])->assertRedirect(route('checkout.success'));

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('temporary_photo_uploads', [
            'public_id' => $uploadId,
            'attached_order_id' => $order->id,
        ]);
        $this->assertCount(1, $order->uploaded_photos);
    }

    public function test_upload_endpoint_returns_json_for_invalid_mime_and_oversized_files(): void
    {
        Storage::fake('local');
        $sessionToken = $this->uploadSessionToken();

        $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => UploadedFile::fake()->create('child.txt', 4, 'text/plain'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'صيغة الصورة غير مدعومة. ارفع صور JPG أو PNG أو WebP. HEIC/HEIF قد لا يعمل على كل الأجهزة.');

        $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => UploadedFile::fake()->create('large-child.jpg', 15361, 'image/jpeg'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'حجم كل صورة يجب ألا يزيد عن 15 ميجا.');
    }

    public function test_temp_uploads_are_limited_per_upload_session(): void
    {
        Storage::fake('local');
        config(['photo_uploads.max_files' => 1]);
        $sessionToken = $this->uploadSessionToken();

        $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => $this->tinyPngUpload('first.png'),
        ])->assertCreated();

        $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => $this->tinyPngUpload('second.png'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يمكنك رفع 1 صور كحد أقصى.');
    }

    public function test_upload_ids_from_another_session_or_expired_uploads_cannot_be_attached(): void
    {
        Storage::fake('local');
        $story = $this->story();
        $sessionToken = $this->uploadSessionToken();

        $foreign = TemporaryPhotoUpload::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'session_hash' => 'foreign-session',
            'disk' => 'local',
            'path' => 'temporary-uploads/child-photos/foreign.png',
            'mime_type' => 'image/png',
            'file_size' => 100,
            'status' => 'uploaded',
            'expires_at' => now()->addHour(),
        ]);

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), $this->cartPayload([
                'upload_session_token' => $sessionToken,
                'photo_upload_ids' => [$foreign->public_id],
            ]))
            ->assertRedirect(route('stories.show', $story->slug))
            ->assertSessionHasErrors('photo_upload_ids');

        $expired = $this->uploadedRecord($sessionToken, ['expires_at' => now()->subMinute()]);

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), $this->cartPayload([
                'upload_session_token' => $sessionToken,
                'photo_upload_ids' => [$expired->public_id],
            ]))
            ->assertRedirect(route('stories.show', $story->slug))
            ->assertSessionHasErrors('photo_upload_ids');
    }

    public function test_attached_upload_cannot_be_reused_for_another_cart_item(): void
    {
        Storage::fake('local');
        $story = $this->story();
        $sessionToken = $this->uploadSessionToken();
        $uploadId = $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => $this->tinyPngUpload(),
        ])->assertCreated()->json('id');

        $payload = $this->cartPayload([
            'upload_session_token' => $sessionToken,
            'photo_upload_ids' => [$uploadId],
        ]);

        $this->post(route('cart.store', $story->slug), $payload)->assertRedirect(route('cart.index'));

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), $payload)
            ->assertRedirect(route('stories.show', $story->slug))
            ->assertSessionHasErrors('photo_upload_ids');
    }

    public function test_authenticated_upload_is_associated_with_user(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $sessionToken = $this->actingAs($user)->uploadSessionToken();

        $uploadId = $this->actingAs($user)->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => $this->tinyPngUpload(),
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('temporary_photo_uploads', [
            'public_id' => $uploadId,
            'user_id' => $user->id,
        ]);
    }

    public function test_expired_unattached_uploads_are_cleaned_but_attached_files_remain(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('temporary-uploads/child-photos/expired.png', 'expired');
        Storage::disk('local')->put('temporary-uploads/child-photos/attached.png', 'attached');

        TemporaryPhotoUpload::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'session_hash' => 'expired',
            'disk' => 'local',
            'path' => 'temporary-uploads/child-photos/expired.png',
            'mime_type' => 'image/png',
            'file_size' => 7,
            'status' => 'uploaded',
            'expires_at' => now()->subHour(),
        ]);

        TemporaryPhotoUpload::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'session_hash' => 'attached',
            'disk' => 'local',
            'path' => 'temporary-uploads/child-photos/attached.png',
            'mime_type' => 'image/png',
            'file_size' => 8,
            'status' => 'attached',
            'expires_at' => now()->subHour(),
        ]);

        $result = app(TemporaryPhotoUploadService::class)->cleanupExpired();

        $this->assertSame(1, $result['expired']);
        Storage::disk('local')->assertMissing('temporary-uploads/child-photos/expired.png');
        Storage::disk('local')->assertExists('temporary-uploads/child-photos/attached.png');
    }

    public function test_temporary_child_photos_are_not_publicly_accessible(): void
    {
        Storage::fake('local');
        $sessionToken = $this->uploadSessionToken();
        $uploadId = $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $sessionToken,
            'photo' => $this->tinyPngUpload(),
        ])->assertCreated()->json('id');

        $upload = TemporaryPhotoUpload::where('public_id', $uploadId)->firstOrFail();
        $this->get('/storage/'.$upload->path)->assertStatus(403);
        $response = $this->get(route('photo-uploads.show', $uploadId))->assertOk();
        $cacheControl = $response->headers->get('Cache-Control', '');
        foreach (['no-store', 'no-cache', 'must-revalidate', 'private'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
    }

    private function uploadSessionToken(): string
    {
        return $this->getJson(route('photo-uploads.session'))
            ->assertOk()
            ->json('upload_session_token');
    }

    private function uploadedRecord(string $sessionToken, array $overrides = []): TemporaryPhotoUpload
    {
        $path = $overrides['path'] ?? 'temporary-uploads/child-photos/'.\Illuminate\Support\Str::uuid().'.png';
        Storage::disk('local')->put($path, 'image-bytes');

        return TemporaryPhotoUpload::create(array_merge([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'session_hash' => app(TemporaryPhotoUploadService::class)->sessionHash($sessionToken),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'image/png',
            'file_size' => 100,
            'status' => 'uploaded',
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function story(): Story
    {
        return Story::create([
            'title' => 'رحلة الفضاء',
            'slug' => 'space-story',
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 100,
            'active' => true,
        ]);
    }

    private function deliveryZone(): array
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();

        return [$egypt, $cairo];
    }

    private function cartPayload(array $overrides = []): array
    {
        return array_merge([
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'interests' => 'الرسم',
            'gift_note' => 'إهداء خاص',
            'parent_notes' => 'ملاحظات للطلب',
            'privacy_consent' => '1',
            'next' => 'cart',
        ], $overrides);
    }

    private function tinyPngUpload(string $name = 'child.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'herokid-child-photo-');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
