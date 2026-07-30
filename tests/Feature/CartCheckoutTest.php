<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\MetaConversionEvent;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCart;
use App\Services\Cart\CartTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
            ->assertHeader('Set-Cookie')
            ->assertDontSee('خطوات الطلب كاملة')
            ->assertSee('إضافة للسلة')
            ->assertSee('املا بيانات طفلك لطلب القصة')
            ->assertDontSee('خطوتان فقط لإضافة القصة للسلة')
            ->assertDontSee('بياناتك محفوظة أثناء التنقل')
            ->assertSee('بيانات الطفل والصور')
            ->assertSee('إضافات اختيارية')
            ->assertDontSee('المطلوب هنا: اسم الطفل وعمره وجنسه')
            ->assertSee('data-story-stage="1"', false)
            ->assertSee('data-story-stage="2"', false)
            ->assertDontSee('data-story-stage="3"', false)
            ->assertDontSee('data-story-stage="4"', false)
            ->assertSee('data-story-cart-actions', false)
            ->assertSeeInOrder([
                'data-story-stage="1"',
                'data-photo-input',
                'data-story-next="2"',
                'data-story-stage="2"',
            ], false)
            ->assertDontSee('موافقة صريحة على استخدام الصور');
    }

    public function test_story_detail_sections_use_three_item_mobile_previews_and_expand_accessibly(): void
    {
        $story = $this->story('about-story', 'قصة الوصف', 100);
        $story->update([
            'full_desc' => 'هذا وصف طويل للقصة يشرح المغامرة والشخصيات والقيمة التي يتعلمها الطفل، ويحتوي على تفاصيل كافية ليمتد لأكثر من سطرين عند عرضه على شاشة الهاتف.',
        ]);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('data-story-about', false)
            ->assertSee('data-story-about-text', false)
            ->assertSee('style="max-height: 5.25rem"', false)
            ->assertSee('lineHeight) || 28) * 3', false)
            ->assertSee('data-story-about-fade', false)
            ->assertSee('opacity-100 transition-opacity', false)
            ->assertSee('bg-gradient-to-b from-transparent to-white', false)
            ->assertDontSee('via-white/90', false)
            ->assertSee('data-story-includes', false)
            ->assertSee('data-story-includes-list', false)
            ->assertSee('data-story-includes-fade', false)
            ->assertSee('items[Math.min(2, items.length - 1)]', false)
            ->assertSee("window.matchMedia('(min-width: 768px)')", false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('عرض المزيد')
            ->assertSee('عرض أقل');
    }

    public function test_story_photo_previews_use_a_compact_three_column_grid_with_file_names(): void
    {
        $story = $this->story('compact-photo-preview', 'قصة الصور', 349);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('class="mt-3 grid grid-cols-3 gap-2" data-photo-queue', false)
            ->assertSee('class="relative aspect-square overflow-hidden rounded-lg bg-slate-100"', false)
            ->assertSee('title="${escapedName}"', false)
            ->assertSee('name: item.name', false)
            ->assertSee("const isUploaded = item.status === 'uploaded';", false)
            ->assertSee("isUploaded ? 'hidden' : ''", false)
            ->assertSee('function escapeHtml(value)', false);
    }

    public function test_public_tracking_keeps_page_views_and_purchase_has_egp_currency(): void
    {
        Storage::fake('local');
        config(['services.meta_pixel.id' => '1011553001490691']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee("fbq('track', 'PageView')", false)
            ->assertSee("fbq('init', \"1011553001490691\")", false)
            ->assertSee('www.facebook.com/tr?id=1011553001490691', false)
            ->assertDontSee('1241523867742555', false);

        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $cairo->update(['delivery_fee' => 40]);
        $story = $this->story('pixel-story', 'رحلة التتبع', 100);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertDontSee("fbq('track', 'ViewContent'", false)
            ->assertDontSee('view_item', false);

        $this->post(route('cart.store', $story->slug), $this->cartPayload('رينا', 'الرسم والنجوم'))
            ->assertRedirect(route('cart.index'));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertDontSee("fbq('track', 'AddToCart'", false)
            ->assertDontSee('add_to_cart', false)
            ->assertDontSee('begin_checkout', false);

        $this->withUnencryptedCookie('_fbp', 'fb.1.1234567890.checkout')->post(route('checkout.store'), [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $egypt->id,
            'delivery_governorate_id' => $cairo->id,
            'city' => 'Nasr City',
            'street' => 'Street 1',
            'address_details' => 'Building 2, Apartment 3',
        ])
            ->assertRedirect(route('checkout.success'))
            ->assertSessionHas('meta.purchase_event', fn (array $event): bool => $event['currency'] === 'EGP'
                && $event['value'] === 140.0
                && str_starts_with($event['event_id'], 'purchase-'));
        $this->assertSame(
            'fb.1.1234567890.checkout',
            MetaConversionEvent::query()->sole()->user_data_encrypted['fbp'],
        );

        $this->get(route('checkout.success'))
            ->assertOk()
            ->assertSee("window.fbq('track', 'Purchase'", false)
            ->assertSee('"currency":"EGP"', false)
            ->assertSee('"value":140', false)
            ->assertSee('eventID:', false)
            ->assertDontSee("gtag('event'", false)
            ->assertSessionMissing('meta.purchase_event');
    }

    public function test_adding_a_story_and_continuing_shopping_redirects_to_shop_with_named_toast(): void
    {
        Storage::fake('local');
        $story = $this->story('continue-shopping-story', 'رحلة النجوم', 349);

        $response = $this->post(route('cart.store', $story->slug), array_merge(
            $this->cartPayload('ليلى', 'الفضاء'),
            ['next' => 'stories'],
        ));

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shop.index'))
            ->assertSessionHas('cart_added_notice', fn (array $notice): bool => $notice['story_title'] === 'رحلة النجوم');

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('data-cart-added-toast', false)
            ->assertSee('تمت إضافة «رحلة النجوم» إلى السلة')
            ->assertSee('الذهاب إلى السلة')
            ->assertSee('اختيار منتج آخر')
            ->assertSee(route('cart.index'), false);
    }

    public function test_adding_a_story_and_completing_order_keeps_cart_redirect_with_named_toast(): void
    {
        Storage::fake('local');
        $story = $this->story('complete-order-story', 'مغامرة البحر', 349);

        $this->post(route('cart.store', $story->slug), $this->cartPayload('سليم', 'البحر'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('cart_added_notice', fn (array $notice): bool => $notice['story_title'] === 'مغامرة البحر');

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('data-cart-added-toast', false)
            ->assertSee('تمت إضافة «مغامرة البحر» إلى السلة')
            ->assertDontSee('تمت إضافة القصة إلى السلة بنجاح.');
    }

    public function test_public_pages_initialize_only_the_configured_meta_pixel(): void
    {
        config(['services.meta_pixel.id' => '1011553001490691']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee("fbq('init', \"1011553001490691\")", false)
            ->assertSee("fbq('track', 'PageView')", false)
            ->assertSee('www.facebook.com/tr?id=1011553001490691', false)
            ->assertDontSee('1241523867742555', false);
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
                'photo_upload_ids',
            ]);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('id="story-order-errors"', false)
            ->assertSee('data-scroll-on-load', false)
            ->assertSee('يرجى مراجعة البيانات التالية')
            ->assertSee('يرجى إدخال اسم الطفل')
            ->assertSee('يرجى إدخال عمر الطفل')
            ->assertSee('يرجى اختيار جنس الطفل')
            ->assertSee('يرجى رفع صورتين واضحتين للطفل على الأقل')
            ->assertSee('اختيار ٢ أو ٣ صور')
            ->assertSee('لم يتم اختيار صور')
            ->assertSee('ارفع صورتين أو ٣ صور واضحة للوجه')
            ->assertDontSee('صورتان مطلوبتان، والصورة الثالثة اختيارية')
            ->assertDontSee('تقبل صور JPG وPNG وWebP وHEIC/HEIF')
            ->assertDontSee('سيتم رفع كل صورة وحدها قبل إرسال الطلب')
            ->assertSee('اختياري — يمكنك الإضافة للسلة بدون فتح هذا القسم')
            ->assertSee('data-story-requirements', false);
    }

    public function test_story_cart_requires_two_to_three_photos_and_accepts_ages_three_to_twelve_for_all_stories(): void
    {
        Storage::fake('local');
        $story = $this->story('policy-story', 'قصة الفئة العمرية', 100);
        $story->update(['age_range' => '٣ - ٦ سنوات']);

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), array_merge($this->cartPayload('رينا', 'الرسم'), [
                'photos' => [$this->tinyPngUpload()],
            ]))
            ->assertSessionHasErrors('photos');

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), array_merge($this->cartPayload('رينا', 'الرسم'), [
                'child_age' => 2,
            ]))
            ->assertSessionHasErrors('child_age');

        $this->from(route('stories.show', $story->slug))
            ->post(route('cart.store', $story->slug), array_merge($this->cartPayload('رينا', 'الرسم'), [
                'child_age' => 9,
            ]))
            ->assertRedirect(route('cart.index'))
            ->assertSessionDoesntHaveErrors();

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('<option value="3"', false)
            ->assertSee('<option value="9"', false)
            ->assertSee('<option value="12"', false)
            ->assertDontSee('<option value="2"', false)
            ->assertDontSee('<option value="13"', false);
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
            ->assertSee('اختيار ٢ أو ٣ صور');
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

        $cartResponse = $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('رحلة الفضاء')
            ->assertSee('سر البحر')
            ->assertSee('75')
            ->assertSee('data-cart-mobile-delivery-notice', false)
            ->assertSee('الرجاء إدخال بيانات التوصيل لإتمام الطلب')
            ->assertSee('class="mb-6 hidden justify-start sm:flex"', false)
            ->assertSee('hidden sm:block', false)
            ->assertSeeInOrder(['ملخص الطلب قبل إدخال العنوان', 'بيانات ولي الأمر والتوصيل'])
            ->assertSee('إتمام الطلب')
            ->assertSee('سيتواصل فريقنا معك على الواتساب للمعاينة قبل الطباعة وتأكيد الطلب')
            ->assertDontSee('سنتواصل معك عبر واتساب لتأكيد المعاينة وطريقة الدفع. لا يوجد دفع الآن.')
            ->assertSee('class="grid grid-cols-3 gap-2 p-3 text-sm sm:gap-3 sm:p-6"', false)
            ->assertSee('type="tel"', false)
            ->assertSee('inputmode="tel"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('for="checkout-parent-name"', false)
            ->assertSee('رقم الموبايل / واتساب')
            ->assertSee('المحافظة')
            ->assertSee('القاهرة')
            ->assertDontSee('البريد الإلكتروني');

        $this->assertSame(2, substr_count($cartResponse->getContent(), 'data-cart-mobile-item'));
        $cartResponse
            ->assertSee('class="flex items-center justify-between border-b border-slate-100 p-3 md:hidden"', false)
            ->assertSee('class="hidden divide-y divide-slate-100 md:block"', false);

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
        $this->assertSame('مصر', $orders[0]->delivery_details['country']);
        $this->assertSame('القاهرة', $orders[0]->delivery_details['governorate']);
        $this->assertSame('Nasr City', $orders[0]->delivery_details['city']);
        $this->assertSame('Street 1', $orders[0]->delivery_details['street']);
        $this->assertSame('Building 2, Apartment 3', $orders[0]->delivery_details['address_details']);
        $this->assertSame(250.0, (float) $orders[0]->delivery_details['subtotal']);
        $this->assertSame(40.0, (float) $orders[0]->delivery_details['delivery_fee']);
        $this->assertSame(290.0, (float) $orders[0]->delivery_details['total']);
        $this->assertNotEmpty($orders[0]->delivery_details['checkout_group']);
        $this->assertSame($orders[0]->delivery_details['checkout_group'], $orders[1]->delivery_details['checkout_group']);
        $this->assertCount(2, $orders[0]->uploaded_photos);

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

    public function test_story_offer_price_is_snapshotted_in_cart_and_order_while_products_remain_separate(): void
    {
        Storage::fake('local');
        foreach ([
            'story_global_price_enabled' => '1',
            'story_regular_price' => '399',
            'story_offer_enabled' => '1',
            'story_offer_price' => '349',
            'story_offer_label' => 'عرض خاص',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget('site_settings');

        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $story = $this->story('global-offer-checkout', 'قصة العرض', 120);

        $this->post(route('cart.store', $story->slug), $this->cartPayload('رينا', 'القراءة'))
            ->assertRedirect(route('cart.index'));

        $cartItem = collect(session('cart.items'))->first();
        $this->assertSame(349.0, $cartItem['story_price']);
        $this->assertSame(399.0, $cartItem['story_regular_price']);
        $this->assertTrue($cartItem['story_offer_applied']);

        Setting::where('key', 'story_offer_price')->update(['value' => '329']);
        Cache::forget('site_settings');

        $this->post(route('checkout.store'), [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $egypt->id,
            'delivery_governorate_id' => $cairo->id,
            'city' => 'Nasr City',
            'street' => 'Street 1',
            'address_details' => 'Building 2',
        ])->assertRedirect(route('checkout.success'));

        $order = Order::with('items')->latest('id')->firstOrFail();
        $storyOrderItem = $order->items->firstWhere('item_type', 'story');

        $this->assertSame(349.0, (float) $order->delivery_details['item_price']);
        $this->assertSame(399.0, (float) $order->delivery_details['story_regular_price']);
        $this->assertSame(34900, $storyOrderItem->unit_price_cents);
        $this->assertSame(34900, $storyOrderItem->total_price_cents);
        $this->assertSame(399.0, (float) $storyOrderItem->item_snapshot['regular_price']);
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

    public function test_guest_cart_is_tracked_locally_without_raw_session_or_uploaded_photos(): void
    {
        Storage::fake('local');
        $story = $this->story('guest-cart-story', 'سلة زائر', 100);

        $this->get(route('stories.show', [
            'slug' => $story->slug,
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'summer',
        ]))->assertOk();

        $this->post(route('cart.store', $story->slug), $this->cartPayload('رينا', 'الرسم'))
            ->assertRedirect(route('cart.index'));

        $cart = VisitorCart::with(['items', 'activities'])->firstOrFail();
        $this->assertNull($cart->user_id);
        $this->assertNotNull($cart->cart_identifier);
        $this->assertNotNull($cart->visitor_hash);
        $this->assertNotSame(session()->getId(), $cart->visitor_hash);
        $this->assertSame('facebook', $cart->utm_source);
        $this->assertSame('active', $cart->status);
        $this->assertSame(1, $cart->items->count());
        $this->assertArrayNotHasKey('uploaded_photos', $cart->items->first()->item_snapshot);
        $this->assertDatabaseHas('visitor_cart_activities', ['type' => 'item_added']);
    }

    public function test_authenticated_cart_is_associated_with_customer_and_admin_page_requires_permission(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'user', 'phone' => '201555555555']);
        $admin = User::factory()->create(['role' => 'admin']);
        $story = $this->story('known-cart-story', 'سلة عميل', 100);

        $this->actingAs($user)
            ->post(route('cart.store', $story->slug), $this->cartPayload('رينا', 'الرسم'))
            ->assertRedirect(route('cart.index'));

        $this->assertDatabaseHas('visitor_carts', ['user_id' => $user->id, 'status' => 'active']);

        $this->actingAs($user)->get(route('admin.visitor-carts.index'))->assertForbidden();
        $this->actingAs($admin)
            ->get(route('admin.visitor-carts.index'))
            ->assertOk()
            ->assertSee('سلات الزوار')
            ->assertSee('سلة عميل');
    }

    public function test_guest_cart_associates_when_user_logs_in_before_checkout_and_converts_to_order(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'user', 'phone' => '201555555555']);
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $story = $this->story('guest-login-cart', 'سلة تتحول', 100);

        $this->post(route('cart.store', $story->slug), $this->cartPayload('رينا', 'الرسم'))
            ->assertRedirect(route('cart.index'));

        $trackingId = session('cart.tracking_id');
        $this->actingAs($user)
            ->post(route('checkout.store'), [
                'parent_name' => 'Parent Name',
                'phone' => '201555555555',
                'delivery_country_id' => $egypt->id,
                'delivery_governorate_id' => $cairo->id,
                'city' => 'Nasr City',
                'street' => 'Street 1',
                'address_details' => 'Building 2, Apartment 3',
            ])->assertRedirect(route('checkout.success'));

        $order = Order::firstOrFail();
        $cart = VisitorCart::where('cart_identifier', $trackingId)->firstOrFail();
        $this->assertSame($user->id, $cart->user_id);
        $this->assertSame('converted', $cart->status);
        $this->assertSame($order->id, $cart->related_order_id);
        $this->assertDatabaseHas('visitor_cart_activities', ['visitor_cart_id' => $cart->id, 'type' => 'checkout_started']);
        $this->assertDatabaseHas('visitor_cart_activities', ['visitor_cart_id' => $cart->id, 'type' => 'order_completed']);
    }

    public function test_cart_remove_abandonment_and_retention_cleanup_are_local_and_idempotent(): void
    {
        Storage::fake('local');
        $story = $this->story('cleanup-cart-story', 'سلة تنظيف', 100);

        $this->post(route('cart.store', $story->slug), $this->cartPayload('رينا', 'الرسم'))
            ->assertRedirect(route('cart.index'));

        $key = array_key_first(session('cart.items'));
        $this->delete(route('cart.destroy', $key))->assertRedirect(route('cart.index'));
        $cart = VisitorCart::with('items')->firstOrFail();
        $this->assertNotNull($cart->items->first()->removed_at);
        $this->assertDatabaseHas('visitor_cart_activities', ['type' => 'item_removed']);

        $cart->forceFill(['status' => 'active', 'last_activity_at' => now()->subHours(7)])->save();
        $result = app(CartTrackingService::class)->maintainStatuses(abandonedAfterMinutes: 360, retentionDays: 60);
        $this->assertSame(1, $result['abandoned']);
        $this->assertSame('abandoned', $cart->refresh()->status);

        $cart->forceFill(['status' => 'converted', 'last_activity_at' => now()->subHours(7)])->save();
        $result = app(CartTrackingService::class)->maintainStatuses(abandonedAfterMinutes: 360, retentionDays: 60);
        $this->assertSame(0, $result['abandoned']);
        $this->assertSame('converted', $cart->refresh()->status);

        $cart->activities()->update(['created_at' => now()->subDays(70)]);
        $result = app(CartTrackingService::class)->maintainStatuses(abandonedAfterMinutes: 360, retentionDays: 60);
        $this->assertGreaterThanOrEqual(1, $result['deletedActivities']);
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
            'next' => 'cart',
            'photos' => [
                $this->tinyPngUpload(),
                $this->tinyPngUpload('child-second.png'),
            ],
        ];
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
