<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
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

    public function test_story_page_sets_session_cookie_for_cart_csrf_submission(): void
    {
        $story = $this->story('space-story', 'رحلة الفضاء', 100);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertHeader('Set-Cookie');
    }

    public function test_meta_pixel_tracks_page_views_and_purchase_once_after_checkout(): void
    {
        Storage::fake('local');
        config(['services.meta_pixel.id' => '1241523867742555']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee("fbq('track', 'PageView')", false)
            ->assertSee('www.facebook.com/tr?id=1241523867742555', false);

        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $cairo->update(['delivery_fee' => 40]);
        $story = $this->story('pixel-story', 'رحلة التتبع', 100);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee("fbq('track', 'ViewContent'", false)
            ->assertSee('"content_ids":["story:'.$story->id.'"]', false);

        $this->post(route('cart.store', $story->slug), $this->cartPayload('رينا', 'الرسم والنجوم'))
            ->assertRedirect(route('cart.index'));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee("fbq('track', 'AddToCart'", false)
            ->assertSee('"content_ids":["story:'.$story->id.'"]', false);

        $this->post(route('checkout.store'), [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $egypt->id,
            'delivery_governorate_id' => $cairo->id,
            'city' => 'Nasr City',
            'street' => 'Street 1',
            'address_details' => 'Building 2, Apartment 3',
        ])
            ->assertRedirect(route('checkout.success'))
            ->assertSessionHas('facebook_purchase_event');

        $this->get(route('checkout.success'))
            ->assertOk()
            ->assertSee("fbq('track', 'Purchase'", false)
            ->assertSee('"currency":"EGP"', false)
            ->assertSee('"value":140', false)
            ->assertSee('"content_ids":["story:', false);

        $this->get(route('checkout.success'))
            ->assertOk()
            ->assertDontSee("fbq('track', 'Purchase'", false);
    }

    public function test_story_cart_validation_errors_are_readable_arabic_messages(): void
    {
        $story = $this->story('space-story', 'رحلة الفضاء', 100);

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), [])
            ->assertRedirect(route('stories.show', $story->slug))
            ->assertSessionHasErrors([
                'child_name',
                'child_age',
                'child_gender',
                'privacy_consent',
                'photos',
            ]);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('id="story-order-errors"', false)
            ->assertSee('data-scroll-on-load', false)
            ->assertSee('يرجى مراجعة البيانات التالية')
            ->assertSee('يرجى إدخال اسم الطفل')
            ->assertSee('يرجى إدخال عمر الطفل')
            ->assertSee('يرجى اختيار جنس الطفل')
            ->assertSee('يجب الموافقة على استخدام الصور لإكمال الطلب')
            ->assertSee('يرجى رفع صورة واحدة واضحة للطفل على الأقل')
            ->assertSee('اختيار الصور')
            ->assertSee('لم يتم اختيار صور');
    }

    public function test_story_page_renders_wildcard_photo_validation_errors(): void
    {
        Storage::fake('local');
        $story = $this->story('invalid-photo-story', 'صورة غير صحيحة', 100);

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), array_merge($this->cartPayload('رينا', 'الرسم'), [
                'photos' => [
                    UploadedFile::fake()->create('child.txt', 4, 'text/plain'),
                ],
            ]))
            ->assertRedirect(route('stories.show', $story->slug))
            ->assertSessionHasErrors('photos.0');

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('صيغة الصورة غير مدعومة')
            ->assertSee('اختيار الصور');
    }

    public function test_story_cart_accepts_mobile_image_formats_up_to_15_mb_each(): void
    {
        Storage::fake('local');
        $story = $this->story('mobile-photo-story', 'صورة موبايل', 100);

        $this->post(route('cart.store', $story->slug), array_merge($this->cartPayload('رينا', 'الرسم'), [
            'photos' => [
                UploadedFile::fake()->create('child.webp', 512, 'image/webp'),
                UploadedFile::fake()->create('child.heic', 512, 'image/heic'),
            ],
        ]))
            ->assertRedirect(route('cart.index'))
            ->assertSessionDoesntHaveErrors();

        $cart = session('cart.items');
        $this->assertCount(1, $cart);
        $this->assertCount(2, collect($cart)->first()['uploaded_photos']);
    }

    public function test_story_cart_rejects_images_larger_than_15_mb_with_clear_message(): void
    {
        Storage::fake('local');
        $story = $this->story('large-photo-story', 'صورة كبيرة', 100);

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), array_merge($this->cartPayload('رينا', 'الرسم'), [
                'photos' => [
                    UploadedFile::fake()->create('large-child.jpg', 15361, 'image/jpeg'),
                ],
            ]))
            ->assertRedirect(route('stories.show', $story->slug))
            ->assertSessionHasErrors('photos.0');

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('حجم كل صورة يجب ألا يزيد عن 15 ميجا');
    }

    public function test_parent_can_checkout_multiple_personalized_stories_with_shared_delivery_details(): void
    {
        Storage::fake('local');
        Setting::create(['key' => 'delivery_fee', 'value' => '75']);
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $egypt->update(['delivery_fee' => 75]);
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $cairo->update(['delivery_fee' => 40]);

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
            ->assertSee('75')
            ->assertSee('رقم الموبايل / واتساب')
            ->assertSee('المحافظة')
            ->assertSee('القاهرة')
            ->assertDontSee('البريد الإلكتروني');

        $this->post(route('checkout.store'), [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $egypt->id,
            'delivery_governorate_id' => $cairo->id,
            'city' => 'Nasr City',
            'street' => 'Street 1',
            'address_details' => 'Building 2, Apartment 3',
        ])->assertRedirect(route('checkout.success'));

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame([], session('cart.items', []));

        $orders = Order::with('story')->orderBy('id')->get();
        $this->assertSame(['رينا', 'سليم'], $orders->pluck('child_name')->all());
        $this->assertSame('Parent Name', $orders[0]->parent_name);
        $this->assertArrayNotHasKey('email', $orders[0]->delivery_details);
        $this->assertSame('201000000000', $orders[0]->delivery_details['phone']);
        $this->assertSame($egypt->id, $orders[0]->delivery_details['delivery_country_id']);
        $this->assertSame($cairo->id, $orders[0]->delivery_details['delivery_governorate_id']);
        $this->assertSame('Egypt', $orders[0]->delivery_details['country']);
        $this->assertSame('القاهرة', $orders[0]->delivery_details['governorate']);
        $this->assertSame('Nasr City', $orders[0]->delivery_details['city']);
        $this->assertSame('Street 1', $orders[0]->delivery_details['street']);
        $this->assertSame('Building 2, Apartment 3', $orders[0]->delivery_details['address_details']);
        $this->assertSame(250.0, (float) $orders[0]->delivery_details['subtotal']);
        $this->assertSame(40.0, (float) $orders[0]->delivery_details['delivery_fee']);
        $this->assertSame(290.0, (float) $orders[0]->delivery_details['total']);
        $this->assertNotEmpty($orders[0]->delivery_details['checkout_group']);
        $this->assertSame($orders[0]->delivery_details['checkout_group'], $orders[1]->delivery_details['checkout_group']);
        $this->assertCount(1, $orders[0]->uploaded_photos);

        $this->post(route('track.search'), [
            'order_number' => $orders[0]->order_number,
            'phone' => '201000000000',
        ])
            ->assertOk()
            ->assertSee($orders[0]->order_number);
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

    public function test_admin_order_details_show_new_delivery_address_fields_without_checkout_email(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $story = $this->story('space-story', 'رحلة الفضاء', 100);

        $order = Order::create([
            'order_number' => 'HK-2026-TEST01',
            'parent_name' => 'Parent Name',
            'story_id' => $story->id,
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => [
                'phone' => '201000000000',
                'country' => 'Egypt',
                'governorate' => 'القاهرة',
                'city' => 'Nasr City',
                'street' => 'Street 1',
                'address_details' => 'Building 2, Apartment 3',
            ],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Egypt')
            ->assertSee('القاهرة')
            ->assertSee('Nasr City')
            ->assertSee('Street 1')
            ->assertSee('Building 2, Apartment 3')
            ->assertDontSee('البريد الإلكتروني');
    }

    public function test_admin_can_manage_country_and_governorate_delivery_fees(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->post(route('admin.delivery-zones.countries.store'), [
            'name' => 'Saudi Arabia',
            'code' => 'SA',
            'delivery_fee' => 120,
            'active' => 1,
        ])->assertRedirect(route('admin.delivery-zones.index'));

        $country = DeliveryCountry::where('code', 'SA')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.delivery-zones.governorates.store'), [
            'delivery_country_id' => $country->id,
            'name' => 'Riyadh',
            'delivery_fee' => 90,
            'active' => 1,
        ])->assertRedirect(route('admin.delivery-zones.index'));

        $this->assertDatabaseHas('delivery_countries', [
            'name' => 'Saudi Arabia',
            'code' => 'SA',
            'delivery_fee' => 120,
        ]);
        $this->assertDatabaseHas('delivery_governorates', [
            'delivery_country_id' => $country->id,
            'name' => 'Riyadh',
            'delivery_fee' => 90,
        ]);
    }

    public function test_cart_prefills_registered_user_phone_and_latest_delivery_address(): void
    {
        $user = User::factory()->create([
            'name' => 'Parent User',
            'phone' => '201555555555',
        ]);
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $story = $this->story('saved-address-story', 'قصة العنوان', 149);

        Order::create([
            'order_number' => 'HK-2026-ADDR01',
            'user_id' => $user->id,
            'parent_name' => 'Parent User',
            'story_id' => $story->id,
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => [
                'phone' => '201000000000',
                'delivery_country_id' => $egypt->id,
                'delivery_governorate_id' => $cairo->id,
                'country' => 'Egypt',
                'governorate' => 'القاهرة',
                'city' => 'Nasr City',
                'street' => 'Street 9',
                'address_details' => 'Building 10, Apartment 4',
            ],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        $this->withSession([
            'cart.items' => [
                'cart-key' => [
                    'key' => 'cart-key',
                    'story_id' => $story->id,
                    'story_title' => $story->title,
                    'story_slug' => $story->slug,
                    'story_price' => (float) $story->price,
                    'child_name' => 'رينا',
                    'child_age' => 6,
                    'child_gender' => 'girl',
                    'uploaded_photos' => ['orders/cart/test/child.png'],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('value="Parent User"', false)
            ->assertSee('value="201555555555"', false)
            ->assertSee('value="Nasr City"', false)
            ->assertSee('value="Street 9"', false)
            ->assertSee('Building 10, Apartment 4')
            ->assertSee('value="'.$egypt->id.'" data-fee', false)
            ->assertSee('value="'.$cairo->id.'"', false);
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
