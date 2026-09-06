<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\HomepageStoreSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUpsellRule;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\TemporaryPhotoUpload;
use App\Models\User;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Support\ProductProductionPrompt;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_activate_deactivate_and_reorder_categories(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.product-categories.store'), [
            'name_ar' => 'كتب تدريب',
            'name_en' => 'Practice Books',
            'slug' => 'practice-books',
            'sort_order' => 70,
            'is_active' => 1,
            'show_in_store' => 1,
        ])->assertRedirect(route('admin.product-categories.index'));

        $category = ProductCategory::where('slug', 'practice-books')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.product-categories.update', $category), [
            'name_ar' => 'كتب تدريب جديدة',
            'name_en' => 'Practice Books',
            'slug' => 'practice-books',
            'sort_order' => 5,
            'is_active' => 0,
            'show_in_store' => 0,
        ])->assertRedirect(route('admin.product-categories.index'));

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'name_ar' => 'كتب تدريب جديدة',
            'sort_order' => 5,
            'is_active' => false,
            'show_in_store' => false,
        ]);
    }

    public function test_admin_can_create_product_and_public_filters_hide_inactive_products(): void
    {
        $admin = $this->admin();
        $category = ProductCategory::create(['name_ar' => 'كتب ألغاز', 'slug' => 'puzzles', 'is_active' => true, 'show_in_store' => true]);

        $this->actingAs($admin)->post(route('admin.products.store'), [
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب متاهات',
            'name_en' => 'Maze Book',
            'slug' => 'maze-book',
            'price' => 120,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
            'age_groups' => ['6-9'],
            'is_active' => 1,
        ])->assertRedirect();

        Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'منتج مخفي',
            'slug' => 'hidden-product',
            'price_cents' => 10000,
            'is_active' => false,
        ]);

        $this->get(route('shop.category', $category))
            ->assertOk()
            ->assertSee('كتاب متاهات')
            ->assertDontSee('منتج مخفي');

        $this->get(route('shop.index', ['age' => '6-9']))
            ->assertOk()
            ->assertSee('كتاب متاهات');
    }

    public function test_products_without_age_groups_are_treated_as_all_ages(): void
    {
        $category = ProductCategory::create(['name_ar' => 'قصص', 'slug' => 'public-ready', 'is_active' => true, 'show_in_store' => true]);
        Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'قصة لكل الأعمار',
            'slug' => 'all-ages-story',
            'price_cents' => 9000,
            'is_active' => true,
            'age_groups' => [],
        ]);

        $this->get(route('shop.index', ['age' => '9-12']))
            ->assertOk()
            ->assertSee('قصة لكل الأعمار');
    }

    public function test_regular_products_can_be_added_to_cart_without_child_data_and_variant_price_is_used(): void
    {
        $product = $this->product('poster', 100);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'A3',
            'sku' => 'POSTER-A3',
            'image' => 'store/products/variants/a3.jpg',
            'gallery_images' => ['store/products/variants/gallery/a3-side.jpg'],
            'price_override_cents' => 15000,
            'attributes' => ['مقاس A3', 'إطار أبيض'],
            'is_active' => true,
        ]);

        $this->get(route('shop.product.show', $product))
            ->assertOk()
            ->assertSee('data-product-variants', false)
            ->assertSee('data-variant-option', false)
            ->assertSee('POSTER-A3')
            ->assertSee('١٥٠ ج.م');

        $this->post(route('cart.products.store', $product), ['quantity' => 1])
            ->assertSessionHasErrors(['variant_id']);

        $this->post(route('cart.products.store', $product), [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart.index'));

        $item = collect(session('cart.items'))->first();
        $this->assertSame('product', $item['item_type']);
        $this->assertSame(30000, $item['line_total_cents']);
        $this->assertSame('A3', $item['variant_name']);
        $this->assertSame($variant->id, $item['variant_id']);
        $this->assertSame('poster — A3', $item['product_title']);
        $this->assertSame('store/products/variants/a3.jpg', $item['product_image']);
        $this->assertSame(15000, $item['variant_snapshot']['effective_price_cents']);
        $this->assertSame(['مقاس A3', 'إطار أبيض'], $item['variant_snapshot']['attributes']);
    }

    public function test_checkout_records_the_exact_selected_variant_as_the_purchased_item(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('variant-product', 100);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'الحجم الكبير',
            'sku' => 'VARIANT-LARGE',
            'image' => 'store/products/variants/large.jpg',
            'gallery_images' => ['store/products/variants/gallery/large-2.jpg'],
            'price_override_cents' => 17500,
            'is_active' => true,
        ]);

        $this->post(route('cart.products.store', $product), [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart.index'));

        $variant->update([
            'name_ar' => 'اسم تغير بعد الإضافة للسلة',
            'sku' => 'CHANGED-AFTER-CART',
            'image' => 'store/products/variants/changed-after-cart.jpg',
            'gallery_images' => [],
            'price_override_cents' => 25000,
        ]);

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $item = Order::with('items')->firstOrFail()->items->firstOrFail();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame('variant-product — الحجم الكبير', $item->title);
        $this->assertSame('VARIANT-LARGE', $item->sku);
        $this->assertSame(17500, $item->unit_price_cents);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(35000, $item->total_price_cents);
        $this->assertSame('store/products/variants/large.jpg', $item->variant_snapshot['image']);
        $this->assertSame('VARIANT-LARGE', $item->variant_snapshot['sku']);
        $this->assertSame('الحجم الكبير', $item->variant_snapshot['name_ar']);
        $this->assertSame(17500, $item->variant_snapshot['effective_price_cents']);
    }

    public function test_variant_without_own_images_inherits_the_product_gallery(): void
    {
        $product = $this->product('variant-gallery-fallback', 100, [
            'featured_image' => 'store/products/base-main.jpg',
            'gallery_images' => ['store/products/base-side.jpg'],
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'نسخة بدون صور مستقلة',
            'is_active' => true,
        ]);

        $response = $this->get(route('shop.product.show', $product))->assertOk();
        preg_match("/data-images='([^']+)'/", $response->getContent(), $matches);
        $images = json_decode(html_entity_decode($matches[1] ?? ''), true);

        $this->assertSame([
            $product->featured_image_url,
            Seo::imageUrl(Storage::disk('public')->url('store/products/base-side.jpg')),
        ], $images);
    }

    public function test_admin_cannot_delete_a_purchased_variant_or_its_images(): void
    {
        Storage::fake('public');
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('protected-purchased-variant', 100);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'متغير مستخدم',
            'image' => 'store/products/variants/protected.jpg',
            'gallery_images' => ['store/products/variants/gallery/protected-side.jpg'],
            'is_active' => true,
        ]);
        Storage::disk('public')->put($variant->image, 'main');
        Storage::disk('public')->put($variant->gallery_images[0], 'side');

        $this->post(route('cart.products.store', $product), [
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertRedirect(route('cart.index'));
        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $this->actingAs($this->admin())
            ->delete(route('admin.product-variants.destroy', $variant))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
        Storage::disk('public')->assertExists($variant->image);
        Storage::disk('public')->assertExists($variant->gallery_images[0]);
    }

    public function test_admin_can_store_multiple_images_for_a_product_variant(): void
    {
        Storage::fake('public');
        $product = $this->product('variant-gallery-product', 100);

        $this->actingAs($this->admin())->post(route('admin.products.variants.store', $product), [
            'name_ar' => 'نسخة الصور',
            'sku' => 'GALLERY-1',
            'price_override' => 125,
            'image' => UploadedFile::fake()->image('main.jpg'),
            'gallery_images' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
            'is_active' => 1,
        ])->assertRedirect();

        $variant = $product->variants()->firstOrFail();
        $this->assertCount(2, $variant->gallery_images);
        Storage::disk('public')->assertExists($variant->image);
        foreach ($variant->gallery_images as $image) {
            Storage::disk('public')->assertExists($image);
        }
    }

    public function test_collect_child_details_product_displays_and_requires_personalization_fields(): void
    {
        $product = $this->product('school-sticker', 195, [
            'name_ar' => 'ستيكر مخصص باسم وصورة طفلك',
            'personalization_mode' => 'collect_child_details',
        ]);

        $this->get(route('shop.product.show', $product))
            ->assertOk()
            ->assertSee('بيانات وصور الطفل')
            ->assertSee('name="personalizations[0][child_name]"', false)
            ->assertSee('name="personalizations[0][child_age]"', false)
            ->assertSee('name="personalizations[0][child_gender]"', false)
            ->assertSee('استخدم نفس بيانات وصور الطفل الأول')
            ->assertSee('data-identity-photo-input', false);

        $this->post(route('cart.products.store', $product), ['quantity' => 1])
            ->assertSessionHasErrors(['child_name', 'child_age', 'child_gender', 'photo_upload_ids']);

        $this->assertSame([], session('cart.items', []));
    }

    public function test_product_can_collect_only_its_configured_personalization_fields(): void
    {
        $product = $this->product('custom-school-sticker', 195, [
            'name_ar' => 'ستيكر المدرسة المخصص',
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
        ]);

        $this->get(route('shop.product.show', $product))
            ->assertOk()
            ->assertSee('اسم الطفل كاملًا')
            ->assertSee('name="personalizations[0][school_name]"', false)
            ->assertSee('name="personalizations[0][class_name]"', false)
            ->assertSee('data-identity-photo-input', false)
            ->assertDontSee('name="personalizations[0][child_age]"', false)
            ->assertDontSee('name="personalizations[0][child_gender]"', false);

        $this->post(route('cart.products.store', $product), ['quantity' => 1])
            ->assertSessionHasErrors(['child_name', 'school_name', 'class_name', 'photo_upload_ids'])
            ->assertSessionDoesntHaveErrors(['child_age', 'child_gender']);

        $this->assertSame([], session('cart.items', []));
    }

    public function test_admin_can_control_enabled_required_and_labels_for_product_personalization(): void
    {
        $admin = $this->admin();
        $product = $this->product('admin-configured-sticker', 195, [
            'name_ar' => 'ستيكر المدرسة',
            'personalization_mode' => 'collect_child_details',
        ]);

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'product_category_id' => $product->product_category_id,
            'name_ar' => $product->name_ar,
            'slug' => $product->slug,
            'price' => 195,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'collect_child_details',
            'inventory_mode' => 'no_tracking',
            'personalization_fields' => [
                'child_name' => ['enabled' => 1, 'required' => 1, 'label' => 'الاسم الرباعي للطفل'],
                'school_name' => ['enabled' => 1, 'required' => 1, 'label' => 'المدرسة'],
                'class_name' => ['enabled' => 1, 'required' => 0, 'label' => 'الفصل (اختياري)'],
                'photos' => ['enabled' => 1, 'required' => 1, 'label' => 'صور واضحة', 'min_files' => 2, 'max_files' => 3],
            ],
            'is_active' => 1,
        ])->assertRedirect(route('admin.products.edit', $product));

        $product->refresh();
        $fields = $product->personalization_fields['fields'];

        $this->assertTrue($fields['school_name']['enabled']);
        $this->assertTrue($fields['school_name']['required']);
        $this->assertFalse($fields['class_name']['required']);
        $this->assertFalse($fields['child_age']['enabled']);
        $this->assertSame('الاسم الرباعي للطفل', $fields['child_name']['label']);
        $this->assertSame(2, $fields['photos']['min_files']);
        $this->assertSame(3, $fields['photos']['max_files']);
    }

    public function test_custom_product_personalization_is_snapshotted_and_survives_product_changes(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('school-sticker-snapshot', 195, [
            'name_ar' => 'ستيكر المدرسة',
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
        ]);
        $sessionToken = 'custom-product-personalization-token';
        $firstUpload = $this->temporaryPhoto($sessionToken, 'custom-one');
        $secondUpload = $this->temporaryPhoto($sessionToken, 'custom-two');

        $this->withSession(['photo_upload.token' => $sessionToken])
            ->post(route('cart.products.store', $product), [
                'quantity' => 1,
                'upload_session_token' => $sessionToken,
                'child_name' => 'سليم أحمد',
                'school_name' => 'مدرسة النور',
                'class_name' => '3A',
                'photo_upload_ids' => [$firstUpload->public_id, $secondUpload->public_id],
            ])->assertRedirect(route('cart.index'));

        $item = collect(session('cart.items'))->first();
        $this->assertSame('سليم أحمد', $item['personalization_snapshot']['child_name']);
        $this->assertSame('مدرسة النور', $item['personalization_snapshot']['school_name']);
        $this->assertSame('3A', $item['personalization_snapshot']['class_name']);
        $this->assertArrayNotHasKey('child_age', $item['personalization_snapshot']['fields']);

        $product->update(['personalization_fields' => null]);

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $order = Order::with('items')->firstOrFail();
        $productItem = $order->items->firstWhere('product_id', $product->id);

        $this->assertNotNull($productItem);
        $this->assertSame('مدرسة النور', $productItem->personalization_snapshot['school_name']);
        $this->assertSame('3A', $productItem->personalization_snapshot['class_name']);
        $schoolValue = collect($productItem->personalizationDisplayValues())->firstWhere('key', 'school_name');
        $this->assertSame('اسم المدرسة', $schoolValue['label']);
        $this->assertSame('مدرسة النور', $schoolValue['value']);
    }

    public function test_collect_child_details_product_keeps_child_data_and_photos_through_checkout(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('school-sticker-order', 195, [
            'name_ar' => 'ستيكر المدرسة المخصص',
            'personalization_mode' => 'collect_child_details',
            'production_prompt_template' => 'Sticker prompt for {{child_full_name}}',
        ]);
        $sessionToken = 'product-personalization-test-token';
        $firstUpload = $this->temporaryPhoto($sessionToken, 'product-child-one');
        $secondUpload = $this->temporaryPhoto($sessionToken, 'product-child-two');

        $this->withSession(['photo_upload.token' => $sessionToken])
            ->post(route('cart.products.store', $product), [
                'quantity' => 1,
                'upload_session_token' => $sessionToken,
                'child_name' => 'سليم',
                'child_age' => 7,
                'child_gender' => 'boy',
                'interests' => 'كرة القدم',
                'photo_upload_ids' => [$firstUpload->public_id, $secondUpload->public_id],
            ])->assertRedirect(route('cart.index'));

        $item = collect(session('cart.items'))->first();
        $this->assertSame('collect_child_details', $item['personalization_mode']);
        $this->assertSame('سليم', $item['child_name']);
        $this->assertSame(7, $item['child_age']);
        $this->assertSame('boy', $item['child_gender']);
        $this->assertSame('كرة القدم', $item['interests']);
        $this->assertCount(2, $item['uploaded_photos']);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('سليم')
            ->assertSee('2 صورة');

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $order = Order::with('items')->firstOrFail();
        $productItem = $order->items->firstWhere('product_id', $product->id);

        $this->assertNull($order->story_id);
        $this->assertSame('سليم', $order->child_name);
        $this->assertSame(7, $order->child_age);
        $this->assertSame('boy', $order->child_gender);
        $this->assertSame('كرة القدم', $order->interests);
        $this->assertCount(2, $order->uploaded_photos);
        $this->assertNotNull($productItem);
        $this->assertSame('سليم', $productItem->personalization_snapshot['child_name']);
        $this->assertSame(2, $productItem->personalization_snapshot['uploaded_photos_count']);
        $this->assertArrayNotHasKey('production_prompt_template', $productItem->item_snapshot);
        $this->assertSame(
            'Sticker prompt for سليم',
            ProductProductionPrompt::renderForItem($productItem),
        );
        $this->assertDatabaseHas('temporary_photo_uploads', [
            'public_id' => $firstUpload->public_id,
            'status' => 'attached',
            'attached_order_id' => $order->id,
        ]);
    }

    public function test_multiple_personalized_product_units_create_independent_children_orders(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('multi-child-school-sticker', 195, [
            'name_ar' => 'ستيكر المدرسة لعدة أطفال',
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
            'production_prompt_template' => 'Sticker for {{child_full_name}} at {{school_name}}',
        ]);
        $sessionToken = 'multi-child-product-token';
        $uploads = collect(['one-a', 'one-b', 'two-a', 'two-b'])
            ->map(fn (string $id) => $this->temporaryPhoto($sessionToken, $id));

        $this->withSession(['photo_upload.token' => $sessionToken])
            ->post(route('cart.products.store', $product), [
                'quantity' => 2,
                'upload_session_token' => $sessionToken,
                'personalizations' => [
                    [
                        'child_name' => 'سليم أحمد',
                        'school_name' => 'مدرسة النور',
                        'class_name' => '3A',
                        'photo_upload_ids' => $uploads->take(2)->pluck('public_id')->all(),
                    ],
                    [
                        'child_name' => 'مريم أحمد',
                        'school_name' => 'مدرسة الأمل',
                        'class_name' => '2B',
                        'photo_upload_ids' => $uploads->skip(2)->pluck('public_id')->all(),
                    ],
                ],
            ])->assertRedirect(route('cart.index'));

        $cart = collect(session('cart.items'));
        $this->assertCount(2, $cart);
        $this->assertSame(['سليم أحمد', 'مريم أحمد'], $cart->pluck('child_name')->values()->all());
        $this->assertSame([1, 1], $cart->pluck('quantity')->values()->all());
        $this->assertSame([2, 2], $cart->map(fn (array $item): int => count($item['uploaded_photos']))->values()->all());

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $orders = Order::query()->with('items')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(1, $orders->pluck('checkout_group_key')->unique()->count());
        $this->assertSame(['سليم أحمد', 'مريم أحمد'], $orders->pluck('child_name')->all());
        $this->assertSame([2, 2], $orders->map(fn (Order $order): int => count($order->uploaded_photos ?? []))->all());
        $this->assertSame(['مدرسة النور', 'مدرسة الأمل'], $orders->map(fn (Order $order) => $order->items->sole()->personalization_snapshot['school_name'])->all());
        $this->assertSame([1, 1], $orders->map(fn (Order $order): int => $order->items->sole()->quantity)->all());
    }

    public function test_personalized_product_can_reuse_first_child_data_and_photos_for_another_unit(): void
    {
        $product = $this->product('reused-child-school-sticker', 195, [
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
        ]);
        $sessionToken = 'reuse-child-product-token';
        $firstUpload = $this->temporaryPhoto($sessionToken, 'reuse-one');
        $secondUpload = $this->temporaryPhoto($sessionToken, 'reuse-two');

        $this->withSession(['photo_upload.token' => $sessionToken])
            ->post(route('cart.products.store', $product), [
                'quantity' => 2,
                'upload_session_token' => $sessionToken,
                'personalizations' => [
                    [
                        'child_name' => 'سليم أحمد',
                        'school_name' => 'مدرسة النور',
                        'class_name' => '3A',
                        'photo_upload_ids' => [$firstUpload->public_id, $secondUpload->public_id],
                    ],
                    ['reuse_first' => 1],
                ],
            ])->assertRedirect(route('cart.index'));

        $items = collect(session('cart.items'))->values();
        $this->assertCount(2, $items);
        $this->assertSame('سليم أحمد', $items[1]['child_name']);
        $this->assertSame($items[0]['uploaded_photos'], $items[1]['uploaded_photos']);
        $this->assertSame(1, $items[1]['reused_from_unit']);
        $this->assertNotSame($items[0]['key'], $items[1]['key']);
    }

    public function test_standalone_personalized_product_does_not_overwrite_story_child_in_mixed_checkout(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $story = $this->story('mixed-child-story', 'قصة رينا');
        $product = $this->product('mixed-child-sticker', 195, [
            'name_ar' => 'ستيكر سليم',
            'personalization_mode' => 'collect_child_details',
        ]);
        $sessionToken = 'mixed-product-personalization-token';
        $firstUpload = $this->temporaryPhoto($sessionToken, 'mixed-child-one');
        $secondUpload = $this->temporaryPhoto($sessionToken, 'mixed-child-two');

        $this->withSession([
            'photo_upload.token' => $sessionToken,
            'cart.items' => [
                'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
            ],
        ])->post(route('cart.products.store', $product), [
            'upload_session_token' => $sessionToken,
            'child_name' => 'سليم',
            'child_age' => 8,
            'child_gender' => 'boy',
            'photo_upload_ids' => [$firstUpload->public_id, $secondUpload->public_id],
        ])->assertRedirect(route('cart.index'));

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $orders = Order::with('items')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame('رينا', $orders->firstWhere('story_id', $story->id)->child_name);

        $productOrder = $orders->first(fn (Order $order) => $order->items->contains('product_id', $product->id));
        $this->assertNotNull($productOrder);
        $this->assertSame('سليم', $productOrder->child_name);
        $this->assertSame(8, $productOrder->child_age);
        $this->assertCount(2, $productOrder->uploaded_photos);
    }

    public function test_product_views_and_add_to_cart_do_not_emit_external_ecommerce_events(): void
    {
        config(['services.meta_pixel.id' => '1011553001490691']);
        $product = $this->product('pixel-poster', 100);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'A3',
            'price_override_cents' => 15000,
            'is_active' => true,
        ]);

        $this->get(route('shop.product.show', $product))
            ->assertOk()
            ->assertDontSee("fbq('track', 'ViewContent'", false)
            ->assertDontSee('view_item', false);

        $this->post(route('cart.products.store', $product), [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart.index'));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertDontSee("fbq('track', 'AddToCart'", false)
            ->assertDontSee('add_to_cart', false)
            ->assertDontSee('begin_checkout', false);
    }

    public function test_personalized_addons_require_and_inherit_story_context(): void
    {
        $addon = $this->product('hero-poster', 75, [
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
        ]);

        $this->post(route('cart.products.store', $addon))
            ->assertSessionHas('error');

        $story = $this->story('moon-story', 'رحلة القمر');
        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'مراد'),
        ]]);

        $this->post(route('cart.products.store', $addon))
            ->assertRedirect(route('cart.index'));

        $addonItem = collect(session('cart.items'))->firstWhere('item_type', 'product_add_on');
        $this->assertSame('story-key', $addonItem['linked_story_key']);
        $this->assertSame('مراد', $addonItem['linked_story_snapshot']['child_name']);
    }

    public function test_multiple_story_cart_requires_explicit_addon_child_selection(): void
    {
        $addon = $this->product('stickers', 50, [
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
        ]);
        $storyOne = $this->story('story-one', 'قصة أولى');
        $storyTwo = $this->story('story-two', 'قصة ثانية');

        $this->withSession(['cart.items' => [
            'one' => $this->storyCartItem('one', $storyOne, 'رينا'),
            'two' => $this->storyCartItem('two', $storyTwo, 'سليم'),
        ]]);

        $this->post(route('cart.products.store', $addon))
            ->assertSessionHasErrors('linked_story_key');

        $this->post(route('cart.products.store', $addon), ['linked_story_key' => 'two'])
            ->assertRedirect(route('cart.index'));

        $this->assertSame('سليم', collect(session('cart.items'))->firstWhere('item_type', 'product_add_on')['linked_story_snapshot']['child_name']);
    }

    public function test_removing_story_removes_linked_addons(): void
    {
        $story = $this->story('hero-story', 'قصة البطل');

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
            'addon-key' => [
                'key' => 'addon-key',
                'item_type' => 'product_add_on',
                'product_id' => 99,
                'product_title' => 'بوستر',
                'linked_story_key' => 'story-key',
                'line_total_cents' => 5000,
                'quantity' => 1,
            ],
        ]]);

        $this->delete(route('cart.destroy', 'story-key'))->assertRedirect(route('cart.index'));

        $this->assertSame([], session('cart.items'));
    }

    public function test_cart_item_can_be_removed_as_json_without_reloading_and_linked_addons_are_reported(): void
    {
        $story = $this->story('ajax-remove-story', 'قصة الحذف الفوري');

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
            'addon-key' => [
                'key' => 'addon-key',
                'item_type' => 'product_add_on',
                'product_id' => 99,
                'product_title' => 'بوستر',
                'linked_story_key' => 'story-key',
                'line_total_cents' => 5000,
                'quantity' => 1,
            ],
        ]]);

        $this->deleteJson(route('cart.destroy', 'story-key'))
            ->assertOk()
            ->assertJsonPath('cart_count', 0)
            ->assertJsonPath('subtotal', 0)
            ->assertJsonPath('cart_empty', true)
            ->assertJsonPath('removed_keys.0', 'story-key')
            ->assertJsonPath('removed_keys.1', 'addon-key');

        $this->assertSame([], session('cart.items'));
    }

    public function test_cart_recommendations_make_personalized_addon_target_child_clear(): void
    {
        $addon = $this->product('hero-gift', 65, [
            'name_ar' => 'بوستر البطل',
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
            'is_featured' => true,
        ]);
        $storyOne = $this->story('story-one', 'قصة أولى');
        $storyTwo = $this->story('story-two', 'قصة ثانية');

        $this->withSession([
            'cart.items' => [
                'one' => $this->storyCartItem('one', $storyOne, 'رينا'),
                'two' => $this->storyCartItem('two', $storyTwo, 'سليم'),
            ],
            'upsell_story_key' => 'two',
        ]);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('أضف نشاطًا مع القصة')
            ->assertSee('قد يعجب طفلك أيضًا')
            ->assertSee('بوستر البطل')
            ->assertSee('لطفل: رينا')
            ->assertSee('لطفل: سليم')
            ->assertSee('data-cart-upsell-form', false)
            ->assertSee('data-upsell-submit', false)
            ->assertSee('Accept: \'application/json\'', false)
            ->assertSee('إضافة');
    }

    public function test_admin_can_link_a_source_product_to_a_specific_recommended_product(): void
    {
        $admin = $this->admin();
        $sticker = $this->product('upsell-source-sticker', 245, ['name_ar' => 'ستيكر المدرسة']);
        $poster = $this->product('upsell-target-poster', 150, ['name_ar' => 'بوستر الطفل']);

        $this->actingAs($admin)->post(route('admin.upsell-rules.store'), [
            'source_product_id' => $sticker->id,
            'target_product_id' => $poster->id,
            'priority' => 90,
            'is_active' => 1,
            'trigger_scope' => 'story_added',
        ])->assertRedirect(route('admin.upsell-rules.index'));

        $this->assertDatabaseHas('product_upsell_rules', [
            'source_product_id' => $sticker->id,
            'target_product_id' => $poster->id,
            'trigger_scope' => 'product_added',
            'priority' => 90,
            'is_active' => true,
            'source_story_id' => null,
        ]);

        $this->actingAs($admin)->post(route('admin.upsell-rules.store'), [
            'source_product_id' => $sticker->id,
            'target_product_id' => $sticker->id,
            'priority' => 10,
            'is_active' => 1,
            'trigger_scope' => 'product_added',
        ])->assertSessionHasErrors('source_product_id');
    }

    public function test_configured_product_recommendations_appear_on_product_page_and_product_only_cart(): void
    {
        $sourceCategory = ProductCategory::create(['name_ar' => 'ستيكر', 'slug' => 'upsell-source-category', 'is_active' => true, 'show_in_store' => true]);
        $targetCategory = ProductCategory::create(['name_ar' => 'بوسترات', 'slug' => 'upsell-target-category', 'is_active' => true, 'show_in_store' => true]);
        $sticker = $this->product('configured-source-sticker', 245, [
            'name_ar' => 'ستيكر مخصص',
            'product_category_id' => $sourceCategory->id,
        ]);
        $poster = $this->product('configured-target-poster', 150, [
            'name_ar' => 'بوستر مقترح يدويًا',
            'product_category_id' => $targetCategory->id,
        ]);
        $identityCard = $this->product('configured-target-card', 100, [
            'name_ar' => 'بطاقة هوية مخصصة',
            'product_category_id' => $targetCategory->id,
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => [
                'child_name' => ['enabled' => true, 'required' => true],
            ],
        ]);
        ProductUpsellRule::create(['source_product_id' => $sticker->id, 'target_product_id' => $poster->id, 'trigger_scope' => 'product_added', 'priority' => 50, 'is_active' => true]);
        ProductUpsellRule::create(['source_product_id' => $sticker->id, 'target_product_id' => $identityCard->id, 'trigger_scope' => 'product_added', 'priority' => 40, 'is_active' => true]);

        $this->get(route('shop.product.show', $sticker))
            ->assertOk()
            ->assertSee('أكمل مجموعة طفلك')
            ->assertSee('بوستر مقترح يدويًا')
            ->assertSee('بطاقة هوية مخصصة')
            ->assertSee('property="og:type" content="product"', false)
            ->assertSee('"@type":"Product"', false)
            ->assertSee('"priceCurrency":"EGP"', false);

        $response = $this->withSession(['cart.items' => [
            'sticker-key' => [
                'key' => 'sticker-key',
                'item_type' => 'product',
                'product_id' => $sticker->id,
                'product_title' => $sticker->name_ar,
                'product_slug' => $sticker->slug,
                'line_total_cents' => 24_500,
                'quantity' => 1,
            ],
        ]])->get(route('cart.index'))->assertOk();

        $this->assertSame([$poster->id, $identityCard->id], $response->viewData('recommendedProducts')->pluck('id')->all());
        $response
            ->assertSee('منتجات مختارة تناسب طلبك')
            ->assertSee('بوستر مقترح يدويًا')
            ->assertSee('بطاقة هوية مخصصة')
            ->assertSee(route('shop.product.show', $identityCard), false)
            ->assertSee('عرض المنتج واختيار التفاصيل');
    }

    public function test_admin_can_manage_all_related_products_from_the_product_edit_page(): void
    {
        $admin = $this->admin();
        $sticker = $this->product('edit-page-source-sticker', 245, ['name_ar' => 'ستيكر المدرسة']);
        $poster = $this->product('edit-page-target-poster', 150, ['name_ar' => 'بوستر الطفل']);
        $identityCard = $this->product('edit-page-target-card', 100, ['name_ar' => 'بطاقة هوية الطفل']);
        $advancedTarget = $this->product('edit-page-advanced-target', 90, ['name_ar' => 'كتاب أنشطة مشروط']);
        $advancedRule = ProductUpsellRule::create([
            'source_product_id' => $sticker->id,
            'target_product_id' => $advancedTarget->id,
            'age_group' => '8-10',
            'trigger_scope' => 'product_added',
            'priority' => 25,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.products.edit', $sticker))
            ->assertOk()
            ->assertSee('المنتجات المقترحة مع هذا المنتج')
            ->assertSee('بوستر الطفل')
            ->assertSee('بطاقة هوية الطفل')
            ->assertDontSee('name="recommended_product_ids[]" value="'.$sticker->id.'"', false);

        $payload = [
            'product_category_id' => $sticker->product_category_id,
            'name_ar' => $sticker->name_ar,
            'slug' => $sticker->slug,
            'price' => 245,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
            'is_active' => 1,
            'recommendations_present' => 1,
            'recommended_product_ids' => [$poster->id, $identityCard->id],
        ];

        $this->actingAs($admin)->put(route('admin.products.update', $sticker), $payload)
            ->assertRedirect(route('admin.products.edit', $sticker));

        $this->assertEqualsCanonicalizing(
            [$poster->id, $identityCard->id],
            ProductUpsellRule::query()
                ->where('source_product_id', $sticker->id)
                ->whereNull('source_story_id')
                ->whereNull('source_story_category_id')
                ->whereNull('age_group')
                ->whereNull('gender')
                ->where('is_active', true)
                ->pluck('target_product_id')
                ->all()
        );

        $this->actingAs($admin)->get(route('admin.products.edit', $sticker))
            ->assertOk()
            ->assertSee('value="'.$poster->id.'"', false)
            ->assertSee('value="'.$identityCard->id.'"', false);

        $payload['recommended_product_ids'] = [$poster->id];
        $this->actingAs($admin)->put(route('admin.products.update', $sticker), $payload)
            ->assertRedirect(route('admin.products.edit', $sticker));

        $this->assertDatabaseHas('product_upsell_rules', [
            'source_product_id' => $sticker->id,
            'target_product_id' => $poster->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('product_upsell_rules', [
            'source_product_id' => $sticker->id,
            'target_product_id' => $identityCard->id,
        ]);
        $this->assertDatabaseHas('product_upsell_rules', [
            'id' => $advancedRule->id,
            'age_group' => '8-10',
            'is_active' => true,
        ]);
    }

    public function test_cart_recommends_multiple_small_activity_products_and_excludes_products_already_added(): void
    {
        $story = $this->story('recommendation-story', 'قصة الأنشطة');
        $coloring = $this->product('coloring-book', 90, ['name_ar' => 'كتاب تلوين']);
        $maze = $this->product('maze-book', 95, [
            'name_ar' => 'كتاب متاهات',
            'age_groups' => ['3-6'],
            'is_featured' => false,
        ]);
        $puzzles = $this->product('puzzle-book', 100, [
            'name_ar' => 'كتاب ألغاز',
            'age_groups' => ['6-9'],
            'is_featured' => false,
        ]);
        $requiresSeparateDetails = $this->product('separate-details', 110, [
            'name_ar' => 'منتج يحتاج بيانات منفصلة',
            'personalization_mode' => 'collect_child_details',
        ]);

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
            'coloring-key' => [
                'key' => 'coloring-key',
                'item_type' => 'product',
                'product_id' => $coloring->id,
                'product_title' => $coloring->name_ar,
                'product_slug' => $coloring->slug,
                'line_total_cents' => 9000,
                'quantity' => 1,
            ],
        ]]);

        $response = $this->get(route('cart.index'))->assertOk();
        $recommended = $response->viewData('recommendedProducts')->pluck('id');

        $this->assertTrue($recommended->contains($maze->id));
        $this->assertTrue($recommended->contains($puzzles->id));
        $this->assertFalse($recommended->contains($coloring->id));
        $this->assertFalse($recommended->contains($requiresSeparateDetails->id));

        $response
            ->assertSee('كتاب متاهات')
            ->assertSee('كتاب ألغاز')
            ->assertSee('snap-mandatory', false)
            ->assertSee('w-[calc(50%_-_0.25rem)]', false);
    }

    public function test_cart_upsell_can_be_added_as_json_without_redirecting_or_replacing_cart_session(): void
    {
        $story = $this->story('ajax-story', 'قصة أجاكس');
        $maze = $this->product('ajax-maze-book', 95, ['name_ar' => 'كتاب متاهات']);

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
        ]]);

        $response = $this->postJson(route('cart.products.store', $maze), ['quantity' => 1]);

        $response
            ->assertOk()
            ->assertJsonPath('product_name', 'كتاب متاهات')
            ->assertJsonPath('added_line_total', 95)
            ->assertJsonPath('cart_count', 2);
        $this->assertStringContainsString('data-cart-mobile-item', $response->json('mobile_item_html'));
        $this->assertStringContainsString('data-cart-remove-form', $response->json('mobile_item_html'));
        $this->assertStringContainsString('aria-label="حذف كتاب متاهات"', $response->json('mobile_item_html'));
        $this->assertStringContainsString('كتاب متاهات', $response->json('mobile_item_html'));
        $this->assertStringNotContainsString('>عرض<', $response->json('mobile_item_html'));
        $this->assertStringNotContainsString('>حذف<', $response->json('mobile_item_html'));

        $cart = collect(session('cart.items'));
        $this->assertSame('رينا', $cart->get('story-key')['child_name']);
        $this->assertSame('كتاب متاهات', $cart->firstWhere('product_id', $maze->id)['product_title']);
    }

    public function test_cart_shows_linked_addons_under_their_story_with_product_details(): void
    {
        $story = $this->story('linked-addon-story', 'قصة الهدية');
        $addon = $this->product('linked-poster', 50, [
            'name_ar' => 'بوستر البطل',
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
        ]);

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
            'addon-key' => [
                'key' => 'addon-key',
                'item_type' => 'product_add_on',
                'product_id' => $addon->id,
                'product_title' => 'بوستر البطل',
                'product_slug' => $addon->slug,
                'linked_story_key' => 'story-key',
                'variant_name' => 'A3',
                'line_total_cents' => 10000,
                'quantity' => 2,
            ],
        ]]);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('إضافات مرتبطة بهذه القصة')
            ->assertSee('1 إضافة')
            ->assertSee('بوستر البطل')
            ->assertSee('النوع: A3')
            ->assertSee('الكمية: 2')
            ->assertSee('١٠٠ ج.م');
    }

    public function test_stock_limits_are_respected(): void
    {
        $product = $this->product('limited', 80, [
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 1,
        ]);

        $this->post(route('cart.products.store', $product), ['quantity' => 2])
            ->assertSessionHas('error');
    }

    public function test_checkout_preserves_product_snapshots_and_linked_addons(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $story = $this->story('production-story', 'قصة الإنتاج');
        $addon = $this->product('certificate', 60, [
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
        ]);
        $regular = $this->product('maze-book-order', 90);

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
        ]]);
        $this->post(route('cart.products.store', $addon), ['linked_story_key' => 'story-key']);
        $this->post(route('cart.products.store', $regular), ['quantity' => 1]);

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $order = Order::with('items')->firstOrFail();
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'item_type' => 'story', 'title' => 'قصة الإنتاج']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'item_type' => 'product_add_on', 'title' => 'certificate']);
        $this->assertDatabaseHas('order_items', ['item_type' => 'product', 'title' => 'maze-book-order']);
        $this->assertNotNull($order->items->firstWhere('item_type', 'product_add_on')->linked_order_item_id);
    }

    public function test_regular_product_only_cart_can_checkout_without_story_or_child_data(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('ready-activity-book', 110);

        $this->post(route('cart.products.store', $product), ['quantity' => 1])
            ->assertRedirect(route('cart.index'));

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $order = Order::with('items')->firstOrFail();
        $this->assertNull($order->story_id);
        $this->assertNull($order->child_name);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_type' => 'product',
            'title' => 'ready-activity-book',
            'total_price_cents' => 11000,
        ]);
    }

    public function test_checkout_rejects_legacy_personalized_product_cart_item_without_child_data(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('legacy-personalized-product', 195, [
            'personalization_mode' => 'collect_child_details',
        ]);

        $this->withSession(['cart.items' => [
            'legacy-personalized-product' => [
                'key' => 'legacy-personalized-product',
                'item_type' => 'product',
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price_cents' => 19500,
                'line_total_cents' => 19500,
                'personalization_mode' => 'collect_child_details',
            ],
        ]]);

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_homepage_sections_render_only_when_category_has_active_products(): void
    {
        $visibleCategory = ProductCategory::create(['name_ar' => 'قسم ظاهر', 'slug' => 'visible-home', 'is_active' => true, 'show_in_store' => true]);
        $emptyCategory = ProductCategory::create(['name_ar' => 'قسم فارغ', 'slug' => 'empty-home', 'is_active' => true, 'show_in_store' => true]);
        HomepageStoreSection::create(['product_category_id' => $visibleCategory->id, 'title_ar' => 'قسم منتجات ظاهر', 'max_products' => 4, 'is_active' => true]);
        HomepageStoreSection::create(['product_category_id' => $emptyCategory->id, 'title_ar' => 'قسم منتجات فارغ', 'max_products' => 4, 'is_active' => true]);
        Product::create(['product_category_id' => $visibleCategory->id, 'name_ar' => 'منتج رئيسي', 'slug' => 'home-product', 'price_cents' => 10000, 'is_active' => true]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('قسم منتجات ظاهر')
            ->assertSee('منتج رئيسي')
            ->assertDontSee('قسم منتجات فارغ');
    }

    public function test_public_users_cannot_access_admin_store_management(): void
    {
        $this->get(route('admin.products.index'))->assertRedirect(route('login'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(string $slug, int $price, array $overrides = []): Product
    {
        $category = ProductCategory::firstOrCreate(
            ['slug' => 'test-store-category'],
            ['name_ar' => 'تصنيف اختبار', 'is_active' => true, 'show_in_store' => true]
        );

        return Product::create(array_merge([
            'product_category_id' => $category->id,
            'name_ar' => $slug,
            'slug' => $slug,
            'price_cents' => $price * 100,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ], $overrides));
    }

    private function story(string $slug, string $title): Story
    {
        return Story::create([
            'title' => $title,
            'slug' => $slug,
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 149,
            'active' => true,
        ]);
    }

    private function storyCartItem(string $key, Story $story, string $childName): array
    {
        return [
            'key' => $key,
            'item_type' => 'story',
            'story_id' => $story->id,
            'story_title' => $story->title,
            'story_slug' => $story->slug,
            'story_price' => (float) $story->price,
            'child_name' => $childName,
            'child_age' => 6,
            'child_gender' => 'girl',
            'interests' => 'الرسم',
            'gift_note' => 'إهداء',
            'parent_notes' => 'ملاحظة',
            'uploaded_photos' => ['orders/cart/test/child.png'],
        ];
    }

    private function checkoutPayload(DeliveryCountry $country, DeliveryGovernorate $governorate): array
    {
        return [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'Cairo',
            'street' => 'Street 1',
            'address_details' => 'Building 2',
        ];
    }

    private function temporaryPhoto(string $sessionToken, string $publicId): TemporaryPhotoUpload
    {
        return TemporaryPhotoUpload::create([
            'public_id' => (string) Str::uuid(),
            'session_hash' => app(TemporaryPhotoUploadService::class)->sessionHash($sessionToken),
            'batch_hash' => hash('sha256', 'product-personalization-tests'),
            'disk' => 'local',
            'path' => 'temporary-uploads/child-photos/tests/'.$publicId.'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'width' => 800,
            'height' => 800,
            'checksum' => hash('sha256', $publicId),
            'status' => 'uploaded',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function schoolStickerPersonalizationFields(): array
    {
        return [
            'version' => 1,
            'fields' => [
                'child_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الطفل كاملًا'],
                'school_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم المدرسة'],
                'class_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الفصل / الكلاس'],
                'photos' => ['enabled' => true, 'required' => true, 'label' => 'صور الطفل', 'min_files' => 2, 'max_files' => 3],
            ],
        ];
    }
}
