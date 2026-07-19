<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminOrderPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_private_uploaded_child_photo(): void
    {
        Storage::fake('local');

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $story = Story::create([
            'title' => 'Test Story',
            'slug' => 'test-story',
            'language' => 'ar',
            'price' => 100,
            'active' => true,
        ]);

        $photoPath = 'orders/photos/2026-06/kid.png';
        Storage::disk('local')->put($photoPath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        $order = Order::create([
            'order_number' => 'HK-2026-TEST01',
            'story_id' => $story->id,
            'child_name' => 'Rina',
            'child_age' => 4,
            'child_gender' => 'girl',
            'delivery_details' => ['email' => 'parent@example.test', 'phone' => '201000000000'],
            'uploaded_photos' => [$photoPath],
            'status' => 'new',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.orders.photo', [$order, 0]));

        $response->assertOk();
        $cacheControl = $response->headers->get('Cache-Control', '');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_admin_can_append_new_child_photos_and_prompt_contains_every_image(): void
    {
        Storage::fake('local');

        $admin = $this->admin();
        $order = $this->orderWithPhotos(['orders/photos/original.png']);
        Storage::disk('local')->put('orders/photos/original.png', $this->validPngBytes());

        $response = $this->actingAs($admin)->post(route('admin.orders.photos.store', $order), [
            'photos' => [
                UploadedFile::fake()->image('clear-face.png', 300, 300),
                UploadedFile::fake()->image('clear-body.jpg', 600, 900),
            ],
        ]);

        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHas('success');

        $photos = $order->fresh()->uploaded_photos;
        $this->assertCount(3, $photos);
        $this->assertSame('orders/photos/original.png', $photos[0]);
        Storage::disk('local')->assertExists($photos[1]);
        Storage::disk('local')->assertExists($photos[2]);

        $prompt = StoryProductionPrompt::forOrder($order->fresh());
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/0', $prompt);
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/1', $prompt);
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/2', $prompt);

        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $admin->id,
            'action' => 'order.child_photos_added',
            'subject_id' => $order->id,
        ]);
    }

    public function test_new_photos_refresh_managed_references_in_order_specific_prompt(): void
    {
        Storage::fake('local');

        $admin = $this->admin();
        $order = $this->orderWithPhotos(['orders/photos/original.png']);
        Storage::disk('local')->put('orders/photos/original.png', $this->validPngBytes());
        $order->productionPromptOverride()->create([
            'prompt_text' => 'Keep this custom production direction.',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('admin.orders.photos.store', $order), [
            'photos' => [UploadedFile::fake()->image('new-clear-photo.png', 500, 500)],
        ])->assertRedirect(route('admin.orders.show', $order));

        $prompt = StoryProductionPrompt::forOrder($order->fresh());
        $this->assertStringContainsString('Keep this custom production direction.', $prompt);
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/0', $prompt);
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/1', $prompt);
        $this->assertSame(1, substr_count($prompt, 'HERO_KID_CHILD_IMAGES_START'));
    }

    public function test_current_images_are_appended_when_global_template_omits_image_variable(): void
    {
        Storage::fake('local');

        $order = $this->orderWithPhotos(['orders/photos/original.png']);
        Storage::disk('local')->put('orders/photos/original.png', $this->validPngBytes());
        Setting::create([
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => 'Custom global production instructions.',
        ]);

        $prompt = StoryProductionPrompt::forOrder($order);

        $this->assertStringContainsString('Custom global production instructions.', $prompt);
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/0', $prompt);
        $this->assertSame(1, substr_count($prompt, 'HERO_KID_CHILD_IMAGES_START'));
    }

    public function test_invalid_supplemental_file_is_rejected_without_changing_order_photos(): void
    {
        Storage::fake('local');

        $admin = $this->admin();
        $order = $this->orderWithPhotos(['orders/photos/original.png']);
        Storage::disk('local')->put('orders/photos/original.png', $this->validPngBytes());

        $this->actingAs($admin)->post(route('admin.orders.photos.store', $order), [
            'photos' => [UploadedFile::fake()->create('not-a-photo.txt', 4, 'text/plain')],
        ])->assertSessionHasErrors('photos');

        $this->assertSame(['orders/photos/original.png'], $order->fresh()->uploaded_photos);
        $this->assertSame(['orders/photos/original.png'], Storage::disk('local')->allFiles());
    }

    public function test_total_photo_limit_is_enforced_and_partial_uploads_are_cleaned_up(): void
    {
        Storage::fake('local');
        config(['photo_uploads.admin_max_files' => 2]);

        $admin = $this->admin();
        $order = $this->orderWithPhotos(['orders/photos/original.png']);
        Storage::disk('local')->put('orders/photos/original.png', $this->validPngBytes());

        $this->actingAs($admin)->post(route('admin.orders.photos.store', $order), [
            'photos' => [
                UploadedFile::fake()->image('one.png'),
                UploadedFile::fake()->image('two.png'),
            ],
        ])->assertSessionHasErrors('photos');

        $this->assertSame(['orders/photos/original.png'], $order->fresh()->uploaded_photos);
        $this->assertSame(['orders/photos/original.png'], Storage::disk('local')->allFiles());
    }

    public function test_uploading_order_photos_requires_update_and_sensitive_photo_permissions(): void
    {
        Storage::fake('local');

        $admin = $this->admin();
        $admin->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update'])->pluck('id'));
        $admin->unsetRelation('permissions');
        $order = $this->orderWithPhotos([]);

        $this->actingAs($admin)->post(route('admin.orders.photos.store', $order), [
            'photos' => [UploadedFile::fake()->image('clear.png')],
        ])->assertForbidden();

        $this->assertSame([], $order->fresh()->uploaded_photos);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
    }

    private function orderWithPhotos(array $photos): Order
    {
        $story = Story::create([
            'title' => 'Supplemental Photo Story',
            'slug' => 'supplemental-photo-story-'.fake()->unique()->numerify('####'),
            'language' => 'ar',
            'price' => 100,
            'active' => true,
        ]);

        return Order::create([
            'order_number' => 'HK-PHOTO-'.fake()->unique()->numerify('######'),
            'story_id' => $story->id,
            'child_name' => 'Rina',
            'child_age' => 4,
            'child_gender' => 'girl',
            'delivery_details' => ['email' => 'parent@example.test', 'phone' => '201000000000'],
            'uploaded_photos' => $photos,
            'status' => 'new',
        ]);
    }

    private function validPngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=');
    }
}
