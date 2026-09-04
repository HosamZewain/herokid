<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\ProductVariantSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminOrderFullEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DeliveryCountry $country;

    private DeliveryGovernorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Setting::query()->updateOrCreate(['key' => 'story_global_price_enabled'], ['value' => '0']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->country = DeliveryCountry::query()->where('code', 'EG')->firstOrFail();
        $this->governorate = DeliveryGovernorate::query()
            ->where('delivery_country_id', $this->country->id)
            ->where('name', 'القاهرة')
            ->firstOrFail();
        $this->governorate->update(['delivery_fee' => 50, 'active' => true]);
    }

    public function test_admin_can_open_creation_form_as_full_checkout_editor(): void
    {
        [$first] = $this->createCheckout();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.edit', $first->id))
            ->assertOk()
            ->assertSee('تعديل الطلب بالكامل')
            ->assertSee('حفظ كل تعديلات الطلب')
            ->assertSee($first->order_number)
            ->assertSee('لن تُحذف عند الحفظ');
    }

    public function test_full_editor_requires_orders_update_permission(): void
    {
        [$first] = $this->createCheckout();
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');

        $this->actingAs($limited)
            ->get(route('admin.orders.groups.edit', $first->id))
            ->assertForbidden();
    }

    public function test_admin_can_change_remove_and_add_stories_products_delivery_discount_and_payment(): void
    {
        [$first, $second, $direct, $addOn] = $this->createCheckout();
        $groupKey = $first->checkout_group_key;
        $firstNumber = $first->order_number;
        $firstPhotos = $first->uploaded_photos;
        $replacement = $this->story('القصة البديلة', 450);
        $newStory = $this->story('قصة الطفل الجديد', 250);

        $response = $this->actingAs($this->admin)->put(
            route('admin.orders.groups.update', $first->id),
            [
                'parent_name' => 'ولي أمر بعد التعديل',
                'phone' => '01099998888',
                'order_source' => 'phone',
                'source_notes' => 'تم التعديل بعد مكالمة العميل',
                'delivery_country_id' => $this->country->id,
                'delivery_governorate_id' => $this->governorate->id,
                'city' => 'المعادي',
                'street' => 'شارع جديد',
                'address_details' => 'عمارة 20 الدور الثالث',
                'stories' => [
                    0 => [
                        'existing_order_id' => $first->id,
                        'story_id' => $replacement->id,
                        'child_name' => 'ليلى المعدلة',
                        'child_age' => 7,
                        'child_gender' => 'girl',
                        'interests' => 'العلوم',
                    ],
                    5 => [
                        'story_id' => $newStory->id,
                        'child_name' => 'سليم',
                        'child_age' => 8,
                        'child_gender' => 'boy',
                        'photos' => $this->photos('salim'),
                    ],
                ],
                'products' => [
                    $direct->id => ['quantity' => 2],
                    $addOn->id => ['quantity' => 1, 'linked_story_index' => 5],
                ],
                'discount_amount' => 75,
                'discount_reason' => 'خصم متابعة العميل',
                'payment_status' => 'partially_paid',
                'paid_amount' => 300,
                'payment_method' => 'انستاباي',
                'admin_notes' => 'ملاحظات معدلة',
                'change_reason' => 'طلب العميل استبدال قصة وإضافة قصة لطفل آخر.',
            ],
        );

        $response->assertRedirect();
        $first->refresh();
        $this->assertSame($firstNumber, $first->order_number);
        $this->assertSame($replacement->id, $first->story_id);
        $this->assertSame('ليلى المعدلة', $first->child_name);
        $this->assertSame($firstPhotos, $first->uploaded_photos);
        $this->assertSame('ولي أمر بعد التعديل', $first->parent_name);
        $this->assertSame('المعادي', data_get($first->delivery_details, 'city'));

        $this->assertSoftDeleted('orders', ['id' => $second->id]);
        $newOrder = Order::query()
            ->where('checkout_group_key', $groupKey)
            ->where('id', '!=', $first->id)
            ->firstOrFail();
        $this->assertSame($newStory->id, $newOrder->story_id);
        $this->assertSame('سليم', $newOrder->child_name);
        $this->assertCount(2, $newOrder->uploaded_photos ?? []);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $first->id,
            'item_type' => 'product',
            'product_id' => $direct->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $newOrder->id,
            'item_type' => 'product_add_on',
            'product_id' => $addOn->id,
            'quantity' => 1,
        ]);
        $this->assertSame(3, $direct->refresh()->stock_quantity);
        $this->assertSame(7, $addOn->refresh()->stock_quantity);

        $group = app(AdminOrderGroupService::class)->findByRepresentative($first->id);
        $this->assertSame(2, $group['story_count']);
        $this->assertSame(2, $group['product_quantity']);
        $this->assertSame(1, $group['add_on_quantity']);
        $this->assertSame(7_500, $group['discount_cents']);
        $this->assertSame('partially_paid', $group['payment_status']);
        $this->assertSame(30_000, $group['paid_amount_cents']);
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'checkout.full_order_updated',
        ]);
        $this->assertDatabaseHas('order_payment_events', [
            'checkout_group_key' => $groupKey,
            'actor_user_id' => $this->admin->id,
            'event_type' => 'payment_received',
            'source' => 'admin_full_order_update',
            'new_paid_amount_cents' => 30_000,
            'amount_delta_cents' => 30_000,
            'affects_collection_stats' => true,
        ]);
    }

    public function test_recalculating_a_fully_paid_total_during_order_edit_is_a_non_cash_adjustment(): void
    {
        [$first, $second] = $this->createCheckout();

        foreach ([$first, $second] as $order) {
            $order->forceFill([
                'payment_status' => 'paid_in_full',
                'paid_amount_cents' => 95_000,
                'payment_method' => 'انستاباي',
                'payment_updated_at' => now()->subDay(),
            ])->save();
        }

        $payload = $this->editBasePayload($first);
        $payload['payment_status'] = 'paid_in_full';
        $payload['payment_method'] = 'انستاباي';
        $payload['change_reason'] = 'إزالة المنتجات مع بقاء حالة الدفع كما هي.';

        $this->actingAs($this->admin)
            ->put(route('admin.orders.groups.update', $first->id), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('order_payment_events', [
            'checkout_group_key' => $first->checkout_group_key,
            'event_type' => 'payment_balance_adjusted',
            'source' => 'admin_full_order_update',
            'previous_paid_amount_cents' => 95_000,
            'new_paid_amount_cents' => 75_000,
            'amount_delta_cents' => -20_000,
            'affects_collection_stats' => false,
        ]);

        $dashboard = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(0, $dashboard['today']['payment_checkouts']);
        $this->assertSame(0, $dashboard['today']['payments_cents']);
    }

    public function test_new_story_requires_two_photos_but_existing_story_does_not(): void
    {
        [$first] = $this->createCheckout();
        $newStory = $this->story('قصة بلا صور كافية', 200);
        $payload = $this->editBasePayload($first);
        $payload['stories'][] = [
            'story_id' => $newStory->id,
            'child_name' => 'طفل جديد',
            'child_age' => 5,
            'child_gender' => 'boy',
            'photos' => [UploadedFile::fake()->image('one.jpg')],
        ];

        $this->actingAs($this->admin)
            ->from(route('admin.orders.groups.edit', $first->id))
            ->put(route('admin.orders.groups.update', $first->id), $payload)
            ->assertRedirect(route('admin.orders.groups.edit', $first->id))
            ->assertSessionHasErrors('stories.2.photos');

        $this->assertSame(2, Order::query()->where('checkout_group_key', $first->checkout_group_key)->count());
    }

    public function test_product_only_checkout_can_be_edited_with_the_same_form(): void
    {
        $product = Product::create([
            'name_ar' => 'كتاب أنشطة جاهز',
            'slug' => 'product-only-edit',
            'price_cents' => 12_000,
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 4,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'نسخة الطلب الأصلية',
            'sku' => 'ADMIN-ORIGINAL',
            'image' => 'store/products/variants/admin-original.jpg',
            'price_override_cents' => 12_000,
            'is_active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'HK-2026-PRODUCT',
            'checkout_group_key' => 'CHK-PRODUCT-ONLY',
            'parent_name' => 'عميل المنتج',
            'order_source' => 'phone',
            'status' => 'new',
            'uploaded_photos' => [],
            'delivery_details' => [
                'phone' => '01011112222',
                'delivery_country_id' => $this->country->id,
                'delivery_governorate_id' => $this->governorate->id,
                'country' => $this->country->name,
                'governorate' => $this->governorate->name,
                'city' => 'القاهرة',
                'street' => 'شارع قديم',
                'address_details' => 'تفاصيل قديمة',
                'delivery_fee' => 50,
                'subtotal' => 120,
                'total' => 170,
            ],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'title' => $product->name_ar.' — '.$variant->name_ar,
            'sku' => $variant->sku,
            'unit_price_cents' => 12_000,
            'quantity' => 1,
            'total_price_cents' => 12_000,
            'variant_snapshot' => ProductVariantSnapshot::make($product, $variant),
        ]);
        $product->decrement('stock_quantity');

        $variant->update([
            'name_ar' => 'نسخة تغيرت بعد الطلب',
            'sku' => 'ADMIN-CHANGED',
            'image' => 'store/products/variants/admin-changed.jpg',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.edit', $order->id))
            ->assertOk()
            ->assertSee('كتاب أنشطة جاهز');

        $this->actingAs($this->admin)->put(route('admin.orders.groups.update', $order->id), [
            'parent_name' => 'عميل المنتج المعدل',
            'phone' => '01033334444',
            'order_source' => 'in_person',
            'delivery_country_id' => $this->country->id,
            'delivery_governorate_id' => $this->governorate->id,
            'city' => 'الجيزة',
            'street' => 'شارع جديد',
            'address_details' => 'عنوان المنتج الجديد',
            'stories' => [],
            'products' => [$product->id => ['quantity' => 2, 'variant_id' => $variant->id]],
            'discount_amount' => 20,
            'discount_reason' => 'خصم منتجين',
            'payment_status' => 'unpaid',
            'change_reason' => 'العميل طلب زيادة كمية المنتج وتحديث العنوان.',
        ])->assertRedirect();

        $this->assertSame(1, Order::query()->where('checkout_group_key', 'CHK-PRODUCT-ONLY')->count());
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price_cents' => 12_000,
        ]);
        $this->assertSame(2, $product->refresh()->stock_quantity);
        $this->assertSame('الجيزة', data_get($order->refresh()->delivery_details, 'city'));
        $updatedItem = $order->items()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('نسخة الطلب الأصلية', $updatedItem->variant_snapshot['name_ar']);
        $this->assertSame('ADMIN-ORIGINAL', $updatedItem->variant_snapshot['sku']);
        $this->assertSame('store/products/variants/admin-original.jpg', $updatedItem->variant_snapshot['image']);
    }

    public function test_editing_a_personalized_product_order_preserves_its_child_snapshot_and_photos(): void
    {
        $product = Product::create([
            'name_ar' => 'استيكر المدرسة',
            'slug' => 'personalized-product-edit-preservation',
            'price_cents' => 20_000,
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => [
                'child_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الطفل كامل'],
                'school_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم المدرسة'],
                'class_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الفصل'],
                'photos' => ['enabled' => true, 'required' => true, 'min_files' => 2, 'max_files' => 3],
            ],
            'is_active' => true,
        ]);
        $photoPaths = [
            'orders/photos/HK-PERSONALIZED/child-1.jpg',
            'orders/photos/HK-PERSONALIZED/child-2.jpg',
        ];
        foreach ($photoPaths as $path) {
            Storage::disk('local')->put($path, 'photo');
        }
        $order = Order::create([
            'order_number' => 'HK-2026-PERSONALIZED',
            'checkout_group_key' => 'CHK-PERSONALIZED-PRODUCT',
            'parent_name' => 'ولي أمر المنتج',
            'child_name' => 'سليم محمد',
            'order_source' => 'whatsapp',
            'status' => 'new',
            'uploaded_photos' => $photoPaths,
            'delivery_details' => [
                'phone' => '01011112222',
                'delivery_country_id' => $this->country->id,
                'delivery_governorate_id' => $this->governorate->id,
                'country' => $this->country->name,
                'governorate' => $this->governorate->name,
                'city' => 'القاهرة',
                'street' => 'شارع قديم',
                'address_details' => 'تفاصيل قديمة',
                'delivery_fee' => 50,
                'subtotal' => 200,
                'total' => 250,
            ],
        ]);
        $snapshot = [
            'schema_version' => 1,
            'fields' => [
                'child_name' => 'سليم محمد',
                'school_name' => 'مدرسة الأمل',
                'class_name' => '3A',
                'photos' => $photoPaths,
            ],
            'uploaded_photos_count' => 2,
        ];
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 20_000,
            'quantity' => 1,
            'total_price_cents' => 20_000,
            'personalization_mode' => 'collect_child_details',
            'personalization_snapshot' => $snapshot,
        ]);

        $this->actingAs($this->admin)->put(route('admin.orders.groups.update', $order->id), [
            'parent_name' => 'ولي أمر المنتج بعد التعديل',
            'phone' => '01033334444',
            'order_source' => 'phone',
            'delivery_country_id' => $this->country->id,
            'delivery_governorate_id' => $this->governorate->id,
            'city' => 'الجيزة',
            'street' => 'شارع جديد',
            'address_details' => 'عنوان المنتج الجديد',
            'stories' => [],
            'products' => [$product->id => ['quantity' => 1]],
            'payment_status' => 'unpaid',
            'change_reason' => 'تحديث بيانات ولي الأمر والعنوان فقط.',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame($photoPaths, $order->uploaded_photos);
        $this->assertSame('سليم محمد', $order->child_name);
        $this->assertEquals($snapshot, $order->items()->where('product_id', $product->id)->firstOrFail()->personalization_snapshot);
        $this->assertSame(1, Order::query()->where('checkout_group_key', $order->checkout_group_key)->count());
    }

    public function test_admin_can_complete_missing_personalization_fields_and_photos_on_a_legacy_product_order(): void
    {
        $product = Product::create([
            'name_ar' => 'ستيكر المدرسة القديم',
            'slug' => 'legacy-personalized-product-completion',
            'price_cents' => 19_500,
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => [
                'child_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الطفل كامل'],
                'school_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم المدرسة'],
                'class_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الفصل'],
                'child_age' => ['enabled' => true, 'required' => true, 'label' => 'عمر الطفل'],
                'child_gender' => ['enabled' => true, 'required' => false, 'label' => 'جنس الطفل'],
                'photos' => ['enabled' => true, 'required' => true, 'min_files' => 2, 'max_files' => 3],
            ],
            'is_active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'HK-2026-LEGACY-PRODUCT',
            'checkout_group_key' => 'CHK-LEGACY-PRODUCT',
            'parent_name' => 'ولي أمر قديم',
            'child_name' => 'طفل محفوظ قديمًا',
            'child_age' => 7,
            'child_gender' => 'boy',
            'interests' => 'كرة القدم',
            'parent_notes' => 'بيانات وصلت قبل إضافة حقول المنتج',
            'order_source' => 'whatsapp',
            'status' => 'new',
            'uploaded_photos' => [],
            'delivery_details' => [
                'phone' => '01011112222',
                'delivery_country_id' => $this->country->id,
                'delivery_governorate_id' => $this->governorate->id,
                'country' => $this->country->name,
                'governorate' => $this->governorate->name,
                'city' => 'القاهرة',
                'street' => 'شارع قديم',
                'address_details' => 'تفاصيل قديمة',
                'delivery_fee' => 50,
                'subtotal' => 195,
                'total' => 245,
            ],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 19_500,
            'quantity' => 1,
            'total_price_cents' => 19_500,
            'personalization_mode' => 'collect_child_details',
            'personalization_snapshot' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.edit', $order->id));

        $this->assertSame(
            'طفل محفوظ قديمًا',
            data_get($response->viewData('initialProducts'), $product->id.'.units.0.personalization.child_name'),
        );
        $this->assertMatchesRegularExpression(
            '/name="products\['.$product->id.'\]\[units\]\[0\]\[personalization\]\[child_name\]" value="طفل محفوظ قديمًا"/u',
            $response->getContent(),
        );
        $response
            ->assertOk()
            ->assertSee('بيانات مستقلة لكل طفل')
            ->assertSee('اسم الطفل كامل')
            ->assertSee('اسم المدرسة')
            ->assertSee('اسم الفصل')
            ->assertSee('value="7" selected', false)
            ->assertSee('value="boy" selected', false);

        $this->actingAs($this->admin)->put(route('admin.orders.groups.update', $order->id), [
            'parent_name' => 'ولي أمر قديم',
            'phone' => '01011112222',
            'order_source' => 'whatsapp',
            'delivery_country_id' => $this->country->id,
            'delivery_governorate_id' => $this->governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع قديم',
            'address_details' => 'تفاصيل قديمة',
            'stories' => [],
            'products' => [
                $product->id => [
                    'quantity' => 1,
                    'personalization' => [
                        'child_name' => 'سليم محمد كامل',
                        'school_name' => 'مدرسة الأمل',
                        'class_name' => '3A',
                        'child_age' => 8,
                        'photos' => $this->photos('legacy-product'),
                    ],
                ],
            ],
            'payment_status' => 'unpaid',
            'change_reason' => 'استكمال بيانات وصور المنتج التي وصلت عبر واتساب.',
        ])->assertRedirect();

        $order->refresh();
        $item = $order->items()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('سليم محمد كامل', $order->child_name);
        $this->assertSame(8, $order->child_age);
        $this->assertCount(2, $order->uploaded_photos ?? []);
        $this->assertSame('سليم محمد كامل', $item->personalization_snapshot['child_name']);
        $this->assertSame('مدرسة الأمل', $item->personalization_snapshot['school_name']);
        $this->assertSame('3A', $item->personalization_snapshot['class_name']);
        $this->assertSame(2, $item->personalization_snapshot['uploaded_photos_count']);
        foreach ($order->uploaded_photos as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_admin_can_split_a_legacy_multi_quantity_sticker_into_child_specific_production_orders(): void
    {
        $product = Product::create([
            'name_ar' => 'ستيكر المدرسة لعدة أطفال',
            'slug' => 'split-legacy-multi-sticker',
            'price_cents' => 19_500,
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => [
                'child_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الطفل كامل'],
                'school_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم المدرسة'],
                'class_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الفصل'],
                'photos' => ['enabled' => true, 'required' => true, 'min_files' => 2, 'max_files' => 3],
            ],
            'production_prompt_template' => 'Sticker for {{child_full_name}} at {{school_name}}',
            'is_active' => true,
        ]);
        $existingPhotos = ['orders/photos/legacy-multi/one.jpg', 'orders/photos/legacy-multi/two.jpg'];
        foreach ($existingPhotos as $path) {
            Storage::disk('local')->put($path, 'photo');
        }
        $order = Order::create([
            'order_number' => 'HK-LEGACY-MULTI-STICKER',
            'checkout_group_key' => 'CHK-LEGACY-MULTI-STICKER',
            'parent_name' => 'ولي أمر الاستيكر',
            'child_name' => 'سليم أحمد',
            'order_source' => 'whatsapp',
            'status' => 'new',
            'uploaded_photos' => $existingPhotos,
            'delivery_details' => [
                'phone' => '01011112222',
                'delivery_country_id' => $this->country->id,
                'delivery_governorate_id' => $this->governorate->id,
                'country' => $this->country->name,
                'governorate' => $this->governorate->name,
                'city' => 'القاهرة', 'street' => 'شارع قديم', 'address_details' => 'تفاصيل قديمة',
                'delivery_fee' => 50, 'subtotal' => 585, 'total' => 635,
            ],
        ]);
        $item = $order->items()->create([
            'item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar,
            'unit_price_cents' => 19_500, 'quantity' => 3, 'total_price_cents' => 58_500,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => ['production_prompt_template' => $product->production_prompt_template],
            'personalization_snapshot' => [
                'child_name' => 'سليم أحمد', 'school_name' => 'مدرسة النور', 'class_name' => '3A',
                'uploaded_photos_count' => 2,
            ],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.edit', $order))
            ->assertOk()
            ->assertSee('الطفل ١')
            ->assertSee('الطفل ٢')
            ->assertSee('الطفل ٣');

        $this->actingAs($this->admin)->put(route('admin.orders.groups.update', $order), [
            'parent_name' => 'ولي أمر الاستيكر', 'phone' => '01011112222', 'order_source' => 'whatsapp',
            'delivery_country_id' => $this->country->id, 'delivery_governorate_id' => $this->governorate->id,
            'city' => 'القاهرة', 'street' => 'شارع قديم', 'address_details' => 'تفاصيل قديمة',
            'stories' => [],
            'products' => [$product->id => [
                'quantity' => 3,
                'units' => [
                    ['existing_order_id' => $order->id, 'personalization' => [
                        'child_name' => 'سليم أحمد', 'school_name' => 'مدرسة النور', 'class_name' => '3A',
                    ]],
                    ['personalization' => [
                        'child_name' => 'مريم أحمد', 'school_name' => 'مدرسة الأمل', 'class_name' => '2B',
                        'photos' => $this->photos('split-mariam'),
                    ]],
                    ['personalization' => [
                        'child_name' => 'عمر أحمد', 'school_name' => 'مدرسة المستقبل', 'class_name' => '1C',
                        'photos' => $this->photos('split-omar'),
                    ]],
                ],
            ]],
            'payment_status' => 'unpaid',
            'change_reason' => 'فصل النسخ القديمة إلى بيانات إنتاج مستقلة لكل طفل.',
        ])->assertRedirect();

        $orders = Order::query()->with('items')->where('checkout_group_key', $order->checkout_group_key)->orderBy('id')->get();
        $this->assertCount(3, $orders);
        $this->assertSame(['سليم أحمد', 'مريم أحمد', 'عمر أحمد'], $orders->pluck('child_name')->all());
        $this->assertSame([1, 1, 1], $orders->map(fn (Order $childOrder): int => $childOrder->items->sole()->quantity)->all());
        $this->assertSame([2, 2, 2], $orders->map(fn (Order $childOrder): int => count($childOrder->uploaded_photos ?? []))->all());
        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);

        $groupPage = $this->actingAs($this->admin)->get(route('admin.orders.groups.show', $orders->first()));
        foreach ($orders as $childOrder) {
            $item = $childOrder->items->sole();
            $groupPage
                ->assertSee($childOrder->child_name)
                ->assertSee('product-production-prompt-'.$item->id, false)
                ->assertDontSee('href="'.route('admin.orders.products.production', [$childOrder, $item]).'"', false);
        }
    }

    private function createCheckout(): array
    {
        $firstStory = $this->story('القصة الأولى', 400);
        $secondStory = $this->story('القصة الثانية', 300);
        $direct = Product::create([
            'name_ar' => 'كتاب متاهات',
            'slug' => 'edit-direct-maze',
            'price_cents' => 10_000,
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $addOn = Product::create([
            'name_ar' => 'ملصق مخصص',
            'slug' => 'edit-linked-poster',
            'price_cents' => 5_000,
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 8,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('admin.orders.store'), [
            'parent_name' => 'ولي الأمر',
            'phone' => '01012345678',
            'order_source' => 'whatsapp',
            'delivery_country_id' => $this->country->id,
            'delivery_governorate_id' => $this->governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع 1',
            'address_details' => 'الدور الأول',
            'stories' => [
                ['story_id' => $firstStory->id, 'child_name' => 'ليلى', 'child_age' => 6, 'child_gender' => 'girl', 'photos' => $this->photos('layla')],
                ['story_id' => $secondStory->id, 'child_name' => 'عمر', 'child_age' => 8, 'child_gender' => 'boy', 'photos' => $this->photos('omar')],
            ],
            'products' => [
                $direct->id => ['quantity' => 1],
                $addOn->id => ['quantity' => 2, 'linked_story_index' => 1],
            ],
            'discount_amount' => 0,
            'payment_status' => 'unpaid',
        ])->assertRedirect();

        $orders = Order::query()->orderBy('id')->get();

        return [$orders[0], $orders[1], $direct, $addOn];
    }

    private function editBasePayload(Order $first): array
    {
        $orders = Order::query()->where('checkout_group_key', $first->checkout_group_key)->orderBy('id')->get();

        return [
            'parent_name' => 'ولي الأمر',
            'phone' => '01012345678',
            'order_source' => 'whatsapp',
            'delivery_country_id' => $this->country->id,
            'delivery_governorate_id' => $this->governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع 1',
            'address_details' => 'الدور الأول',
            'stories' => $orders->map(fn (Order $order): array => [
                'existing_order_id' => $order->id,
                'story_id' => $order->story_id,
                'child_name' => $order->child_name,
                'child_age' => $order->child_age,
                'child_gender' => $order->child_gender,
            ])->all(),
            'discount_amount' => 0,
            'payment_status' => 'unpaid',
            'change_reason' => 'اختبار التحقق من الصور الجديدة.',
        ];
    }

    private function story(string $title, float $price): Story
    {
        return Story::create([
            'title' => $title,
            'slug' => 'edit-order-'.fake()->unique()->slug(3),
            'language' => 'ar',
            'gender' => 'both',
            'price' => $price,
            'active' => true,
        ]);
    }

    private function photos(string $prefix): array
    {
        return [
            UploadedFile::fake()->image($prefix.'-1.jpg', 900, 900),
            UploadedFile::fake()->image($prefix.'-2.jpg', 900, 900),
        ];
    }
}
