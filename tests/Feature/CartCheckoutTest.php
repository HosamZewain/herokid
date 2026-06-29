<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_checkout_multiple_personalized_stories_with_shared_delivery_details(): void
    {
        Storage::fake('local');
        Setting::create(['key' => 'delivery_fee', 'value' => '75']);

        $spaceStory = $this->story('space-story', 'رحلة الفضاء', 100);
        $seaStory = $this->story('sea-story', 'سر البحر', 150);

        $this->post(route('cart.store', $spaceStory->slug), $this->cartPayload('رينا', 'الرسم والنجوم'))
            ->assertRedirect(route('cart.index'));

        $this->post(route('cart.store', $seaStory->slug), $this->cartPayload('سليم', 'البحر والقوارب'))
            ->assertRedirect(route('cart.index'));

        $this->assertCount(2, session('cart.items'));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('رحلة الفضاء')
            ->assertSee('سر البحر')
            ->assertSee('75');

        $this->post(route('checkout.store'), [
            'parent_name' => 'Parent Name',
            'email' => 'parent@example.test',
            'phone' => '201000000000',
            'governorate' => 'Cairo',
            'city' => 'Nasr City',
            'address' => 'Street 1, Building 2',
        ])->assertRedirect(route('checkout.success'));

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame([], session('cart.items', []));

        $orders = Order::with('story')->orderBy('id')->get();
        $this->assertSame(['رينا', 'سليم'], $orders->pluck('child_name')->all());
        $this->assertSame('Parent Name', $orders[0]->parent_name);
        $this->assertSame('parent@example.test', $orders[0]->delivery_details['email']);
        $this->assertSame('201000000000', $orders[0]->delivery_details['phone']);
        $this->assertSame(250.0, (float) $orders[0]->delivery_details['subtotal']);
        $this->assertSame(75.0, (float) $orders[0]->delivery_details['delivery_fee']);
        $this->assertSame(325.0, (float) $orders[0]->delivery_details['total']);
        $this->assertNotEmpty($orders[0]->delivery_details['checkout_group']);
        $this->assertSame($orders[0]->delivery_details['checkout_group'], $orders[1]->delivery_details['checkout_group']);
        $this->assertCount(1, $orders[0]->uploaded_photos);
    }

    public function test_admin_can_control_delivery_fee_setting(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'settings' => [
                'site_name' => 'HeroKid',
                'site_email' => 'hello@example.test',
                'whatsapp_number' => '201000000000',
                'price_soft_cover' => '99',
                'price_hard_cover' => '149',
                'delivery_fee' => '65',
            ],
        ])->assertRedirect(route('admin.settings.index'));

        $this->assertDatabaseHas('settings', [
            'key' => 'delivery_fee',
            'value' => '65',
        ]);
    }

    private function story(string $slug, string $title, int $price): Story
    {
        return Story::create([
            'title' => $title,
            'slug' => $slug,
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => $price,
            'active' => true,
        ]);
    }

    private function cartPayload(string $childName, string $interests): array
    {
        return [
            'child_name' => $childName,
            'child_age' => 6,
            'child_gender' => 'girl',
            'interests' => $interests,
            'gift_note' => 'إهداء خاص',
            'parent_notes' => 'ملاحظات للطلب',
            'privacy_consent' => '1',
            'next' => 'cart',
            'photos' => [
                $this->tinyPngUpload(),
            ],
        ];
    }

    private function tinyPngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'herokid-child-photo-');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        return new UploadedFile($path, 'child.png', 'image/png', null, true);
    }
}
