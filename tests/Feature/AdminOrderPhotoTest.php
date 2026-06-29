<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
