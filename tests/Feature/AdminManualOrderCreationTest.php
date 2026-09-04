<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Pricing\PackageAnalyticsService;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminManualOrderCreationTest extends TestCase
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

    public function test_create_page_requires_permission_and_explains_multi_story_order_sources(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');

        $this->actingAs($limited)->get(route('admin.orders.create'))->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('إضافة طلب')
            ->assertSee('إضافة قصة أخرى')
            ->assertSee('لنفس الطفل أو لأطفال مختلفين')
            ->assertSee('واتساب')
            ->assertSee('مكالمة هاتفية')
            ->assertSee('زيارة');
    }

    public function test_admin_can_create_one_checkout_with_multiple_stories_children_products_and_discount(): void
    {
        $firstStory = $this->story('مغامرة ليلى', 400);
        $secondStory = $this->story('رحلة عمر', 300);
        $direct = Product::create([
            'name_ar' => 'كتاب متاهات',
            'slug' => 'admin-direct-maze',
            'price_cents' => 10_000,
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $addOn = Product::create([
            'name_ar' => 'ملصق مخصص',
            'slug' => 'admin-linked-poster',
            'price_cents' => 5_000,
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 8,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.orders.store'), [
            'parent_name' => 'والدة ليلى وعمر',
            'phone' => '01012345678',
            'order_source' => 'whatsapp',
            'source_notes' => 'طلب من محادثة واتساب',
            'delivery_country_id' => $this->country->id,
            'delivery_governorate_id' => $this->governorate->id,
            'city' => 'مدينة نصر',
            'street' => 'شارع الاختبار',
            'address_details' => 'العمارة 10 الدور 2',
            'stories' => [
                0 => [
                    'story_id' => $firstStory->id,
                    'child_name' => 'ليلى',
                    'child_age' => 6,
                    'child_gender' => 'girl',
                    'photos' => $this->photos('layla'),
                ],
                2 => [
                    'story_id' => $secondStory->id,
                    'child_name' => 'عمر',
                    'child_age' => 8,
                    'child_gender' => 'boy',
                    'photos' => $this->photos('omar'),
                ],
            ],
            'products' => [
                $direct->id => ['quantity' => 1],
                $addOn->id => ['quantity' => 2, 'linked_story_index' => 2],
            ],
            'discount_amount' => 100,
            'discount_reason' => 'خصم طلب متعدد',
            'payment_status' => 'partially_paid',
            'paid_amount' => 300,
            'payment_method' => 'انستاباي',
            'admin_notes' => 'يرجى التأكد من مقاس الملصق.',
        ]);

        $response->assertRedirect();
        $orders = Order::query()->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(1, $orders->pluck('checkout_group_key')->unique()->count());
        $this->assertSame(['ليلى', 'عمر'], $orders->pluck('child_name')->all());
        $this->assertSame(['whatsapp'], $orders->pluck('order_source')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $orders->pluck('created_by_admin_id')->unique()->values()->all());
        $this->assertSame([2, 2], $orders->map(fn (Order $order): int => count($order->uploaded_photos ?? []))->all());
        $this->assertSame([10_000, 10_000], $orders->pluck('discount_cents')->all());
        $this->assertSame(['partially_paid'], $orders->pluck('payment_status')->unique()->values()->all());
        $this->assertSame([30_000], $orders->pluck('paid_amount_cents')->unique()->values()->all());
        $this->assertSame(['انستاباي'], $orders->pluck('payment_method')->unique()->values()->all());

        $first = $orders->first();
        $second = $orders->last();
        $this->assertDatabaseHas('order_items', [
            'order_id' => $first->id,
            'item_type' => 'product',
            'product_id' => $direct->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $second->id,
            'item_type' => 'product_add_on',
            'product_id' => $addOn->id,
            'quantity' => 2,
        ]);
        $this->assertSame(4, $direct->refresh()->stock_quantity);
        $this->assertSame(6, $addOn->refresh()->stock_quantity);

        foreach ($orders as $order) {
            foreach ($order->uploaded_photos as $path) {
                Storage::disk('local')->assertExists($path);
            }
        }

        $group = app(AdminOrderGroupService::class)->findByRepresentative($first->id);
        $this->assertSame(2, $group['story_count']);
        $this->assertSame(10_000, $group['discount_cents']);
        $this->assertSame(85_000, $group['total_cents']);
        $this->assertSame('whatsapp', $group['order_source']);
        $this->assertSame(30_000, $group['paid_amount_cents']);
        $this->assertSame(55_000, $group['remaining_amount_cents']);

        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'order.created_manually',
        ]);

        $filters = SalesReportFilters::fromRequest(Request::create('/admin/sales-report', 'GET', [
            'range' => 'today',
            'source' => 'whatsapp',
        ]));
        $report = app(SalesReportService::class)->report($filters);
        $this->assertSame(300.0, $report['summary']['total']);
        $this->assertSame(100.0, $report['summary']['discounts']);
        $this->assertSame(1, $report['operational_summary']['all_checkouts']);
        $this->assertSame(1, $report['operational_summary']['partially_paid_checkouts']);
        $this->assertSame(300.0, $report['operational_summary']['partially_paid_amount']);
        $this->assertSame('واتساب', $report['rows']->first()['source']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('واتساب')
            ->assertSee('خصم -');
    }

    public function test_admin_can_create_an_order_from_a_package_using_its_fixed_price_and_components(): void
    {
        $firstStory = $this->story('قصة الباقة الأولى', 400);
        $secondStory = $this->story('قصة الباقة الثانية', 300);
        $product = Product::create([
            'name_ar' => 'كتاب متاهات الباقة',
            'slug' => 'admin-package-maze',
            'price_cents' => 10_000,
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $package = PricingPackage::create([
            'name' => 'باقة قصتين ومتاهات',
            'slug' => 'admin-two-stories-maze',
            'price' => 700,
            'story_count' => 2,
            'active' => true,
            'applies_to_all_stories' => true,
        ]);
        $package->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('إضافة باقة')
            ->assertSee($package->name);

        $payload = $this->basePayload();
        $payload['pricing_package_id'] = $package->id;
        $payload['stories'] = [
            ['story_id' => $firstStory->id, 'child_name' => 'ليلى', 'child_age' => 6, 'child_gender' => 'girl', 'photos' => $this->photos('package-layla')],
            ['story_id' => $secondStory->id, 'child_name' => 'عمر', 'child_age' => 8, 'child_gender' => 'boy', 'photos' => $this->photos('package-omar')],
        ];
        $payload['discount_amount'] = 50;
        $payload['discount_reason'] = 'خصم إضافي للعميل';

        $this->actingAs($this->admin)
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect();

        $orders = Order::with('items')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(1, $orders->pluck('checkout_group_key')->unique()->count());
        $this->assertSame(80_000, $orders->flatMap->items->sum('total_price_cents'));
        $this->assertSame(15_000, $orders->first()->discount_cents);
        $this->assertStringContainsString('سعر باقة: '.$package->name, (string) $orders->first()->discount_reason);
        $this->assertStringContainsString('خصم إضافي للعميل', (string) $orders->first()->discount_reason);
        $this->assertSame(4, $product->fresh()->stock_quantity);

        $group = app(AdminOrderGroupService::class)->findByRepresentative($orders->first()->id);
        $this->assertSame(70_000, $group['total_cents']);
        $this->assertSame($package->id, data_get($orders->first()->items->first()->item_snapshot, 'package.id'));
        $this->assertSame(
            $package->id,
            data_get($orders->flatMap->items->firstWhere('product_id', $product->id)->item_snapshot, 'package.id'),
        );
        $this->assertSame(1, app(PackageAnalyticsService::class)->purchaseCounts(collect([$package->id]))[$package->id]);
    }

    public function test_admin_package_rejects_wrong_story_count_and_ineligible_story(): void
    {
        $eligibleStory = $this->story('قصة الباقة المتاحة', 349);
        $otherStory = $this->story('قصة خارج الباقة', 349);
        $package = PricingPackage::create([
            'name' => 'باقة محددة',
            'slug' => 'admin-restricted-package',
            'price' => 300,
            'story_count' => 1,
            'active' => true,
            'applies_to_all_stories' => false,
        ]);
        $package->eligibleStories()->sync([$eligibleStory->id]);

        $payload = $this->basePayload();
        $payload['pricing_package_id'] = $package->id;
        $payload['stories'] = [
            ['story_id' => $otherStory->id, 'child_name' => 'سليم', 'child_age' => 7, 'child_gender' => 'boy', 'photos' => $this->photos('restricted-story')],
        ];

        $this->actingAs($this->admin)
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect(route('admin.orders.create'))
            ->assertSessionHasErrors('stories');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_same_story_can_be_ordered_more_than_once_for_the_same_child(): void
    {
        $story = $this->story('قصة مكررة', 349);
        $payload = $this->basePayload();
        $payload['stories'] = [
            ['story_id' => $story->id, 'child_name' => 'مريم', 'child_age' => 5, 'child_gender' => 'girl', 'photos' => $this->photos('mariam-a')],
            ['story_id' => $story->id, 'child_name' => 'مريم', 'child_age' => 5, 'child_gender' => 'girl', 'photos' => $this->photos('mariam-b')],
        ];

        $this->actingAs($this->admin)->post(route('admin.orders.store'), $payload)->assertRedirect();

        $orders = Order::query()->get();
        $this->assertCount(2, $orders);
        $this->assertSame(1, $orders->pluck('checkout_group_key')->unique()->count());
        $this->assertSame([$story->id], $orders->pluck('story_id')->unique()->values()->all());
        $this->assertSame(['مريم'], $orders->pluck('child_name')->unique()->values()->all());
    }

    public function test_admin_can_create_product_only_order_with_configured_child_fields_and_photos(): void
    {
        $product = Product::create([
            'name_ar' => 'ستيكر المدرسة',
            'slug' => 'manual-school-sticker',
            'price_cents' => 19_500,
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
            'inventory_mode' => 'made_to_order',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.create'))
            ->assertOk()
            ->assertSee('يمكن إنشاء طلب منتجات فقط')
            ->assertSee('اسم الطفل كاملًا')
            ->assertSee('اسم المدرسة')
            ->assertSee('اسم الفصل / الكلاس');

        $payload = $this->basePayload();
        $payload['stories'] = [];
        $payload['products'] = [
            $product->id => [
                'quantity' => 1,
                'personalization' => [
                    'child_name' => 'سليم أحمد محمد',
                    'school_name' => 'مدرسة النور',
                    'class_name' => '3A',
                    'photos' => $this->photos('school-sticker'),
                ],
            ],
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order = Order::query()->with('items')->sole();
        $item = $order->items->sole();

        $this->assertNull($order->story_id);
        $this->assertSame('سليم أحمد محمد', $order->child_name);
        $this->assertNull($order->child_age);
        $this->assertNull($order->child_gender);
        $this->assertCount(2, $order->uploaded_photos);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('مدرسة النور', $item->personalization_snapshot['school_name']);
        $this->assertSame('3A', $item->personalization_snapshot['class_name']);
        $this->assertSame(2, $item->personalization_snapshot['uploaded_photos_count']);
        $this->assertSame(24_500, app(AdminOrderGroupService::class)->findByRepresentative($order->id)['total_cents']);

        foreach ($order->uploaded_photos as $path) {
            Storage::disk('local')->assertExists($path);
        }

        $groupPage = $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order));
        $groupPage
            ->assertOk()
            ->assertSee('صور الطفل المرفقة')
            ->assertSee(route('admin.orders.photo', [$order, 0]), false)
            ->assertSee(route('admin.orders.photo', [$order, 1]), false);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.photo', [$order, 0]))
            ->assertOk();

        $viewOnlyAdmin = User::factory()->create(['role' => 'admin']);
        $viewOnlyAdmin->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $viewOnlyAdmin->unsetRelation('permissions');
        $this->actingAs($viewOnlyAdmin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertDontSee(route('admin.orders.photo', [$order, 0]), false);
    }

    public function test_admin_can_create_multiple_sticker_units_for_different_children_in_one_checkout(): void
    {
        $product = Product::create([
            'name_ar' => 'ستيكر المدرسة لعدة أطفال',
            'slug' => 'manual-multi-child-sticker',
            'price_cents' => 19_500,
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
            'production_prompt_template' => 'Sticker for {{child_full_name}} at {{school_name}}',
            'inventory_mode' => 'made_to_order',
            'is_active' => true,
        ]);
        $payload = $this->basePayload();
        $payload['stories'] = [];
        $payload['products'] = [
            $product->id => [
                'quantity' => 3,
                'units' => [
                    ['personalization' => [
                        'child_name' => 'سليم أحمد', 'school_name' => 'مدرسة النور', 'class_name' => '3A',
                        'photos' => $this->photos('multi-sticker-salim'),
                    ]],
                    ['personalization' => [
                        'child_name' => 'مريم أحمد', 'school_name' => 'مدرسة الأمل', 'class_name' => '2B',
                        'photos' => $this->photos('multi-sticker-mariam'),
                    ]],
                    ['personalization' => [
                        'child_name' => 'عمر أحمد', 'school_name' => 'مدرسة المستقبل', 'class_name' => '1C',
                        'photos' => $this->photos('multi-sticker-omar'),
                    ]],
                ],
            ],
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect();

        $orders = Order::query()->with('items')->orderBy('id')->get();
        $this->assertCount(3, $orders);
        $this->assertSame(1, $orders->pluck('checkout_group_key')->unique()->count());
        $this->assertSame(['سليم أحمد', 'مريم أحمد', 'عمر أحمد'], $orders->pluck('child_name')->all());
        $this->assertSame([2, 2, 2], $orders->map(fn (Order $order): int => count($order->uploaded_photos ?? []))->all());
        $this->assertSame([1, 1, 1], $orders->map(fn (Order $order): int => $order->items->sole()->quantity)->all());

        $groupPage = $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $orders->first()));
        foreach ($orders as $order) {
            $item = $order->items->sole();
            $groupPage
                ->assertSee($order->child_name)
                ->assertSee('product-production-prompt-'.$item->id, false)
                ->assertDontSee('href="'.route('admin.orders.products.production', [$order, $item]).'"', false);
        }
    }

    public function test_admin_can_reuse_the_first_child_for_another_sticker_unit(): void
    {
        $product = Product::create([
            'name_ar' => 'ستيكر المدرسة مع تكرار الطفل',
            'slug' => 'manual-reused-child-sticker',
            'price_cents' => 19_500,
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
            'production_prompt_template' => 'Sticker for {{child_full_name}} at {{school_name}}',
            'inventory_mode' => 'made_to_order',
            'is_active' => true,
        ]);
        $payload = $this->basePayload();
        $payload['stories'] = [];
        $payload['products'] = [
            $product->id => [
                'quantity' => 2,
                'units' => [
                    ['personalization' => [
                        'child_name' => 'سليم أحمد', 'school_name' => 'مدرسة النور', 'class_name' => '3A',
                        'photos' => $this->photos('reused-sticker-child'),
                    ]],
                    ['reuse_first' => 1],
                ],
            ],
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $orders = Order::query()->with('items')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame(['سليم أحمد', 'سليم أحمد'], $orders->pluck('child_name')->all());
        $this->assertSame([2, 2], $orders->map(fn (Order $order): int => count($order->uploaded_photos ?? []))->all());
        $this->assertSame($orders[0]->uploaded_photos, $orders[1]->uploaded_photos);
        $this->assertSame(
            $orders[0]->items->sole()->personalization_snapshot['school_name'],
            $orders[1]->items->sole()->personalization_snapshot['school_name'],
        );
    }

    public function test_admin_can_create_regular_product_only_order_without_child_data(): void
    {
        $product = Product::create([
            'name_ar' => 'كتاب متاهات',
            'slug' => 'manual-product-only-maze',
            'price_cents' => 17_900,
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 4,
            'is_active' => true,
        ]);
        $payload = $this->basePayload();
        $payload['stories'] = [];
        $payload['products'] = [$product->id => ['quantity' => 2]];

        $this->actingAs($this->admin)
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect();

        $order = Order::query()->with('items')->sole();
        $item = $order->items->sole();

        $this->assertNull($order->story_id);
        $this->assertNull($order->child_name);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(35_800, $item->total_price_cents);
        $this->assertSame(2, $product->refresh()->stock_quantity);
        $this->assertSame(40_800, app(AdminOrderGroupService::class)->findByRepresentative($order->id)['total_cents']);
    }

    public function test_product_only_order_validates_its_configured_fields_and_requires_any_item(): void
    {
        $product = Product::create([
            'name_ar' => 'ستيكر المدرسة',
            'slug' => 'manual-school-sticker-validation',
            'price_cents' => 19_500,
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'collect_child_details',
            'personalization_fields' => $this->schoolStickerPersonalizationFields(),
            'inventory_mode' => 'made_to_order',
            'is_active' => true,
        ]);
        $payload = $this->basePayload();
        $payload['stories'] = [];
        $payload['products'] = [$product->id => ['quantity' => 1]];

        $this->actingAs($this->admin)
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect(route('admin.orders.create'))
            ->assertSessionHasErrors([
                "products.{$product->id}.personalization.child_name",
                "products.{$product->id}.personalization.school_name",
                "products.{$product->id}.personalization.class_name",
                "products.{$product->id}.personalization.photos",
            ]);

        $payload['products'] = [];
        $this->actingAs($this->admin)
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect(route('admin.orders.create'))
            ->assertSessionHasErrors('stories');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_each_story_requires_two_photos_and_failed_request_creates_nothing(): void
    {
        $story = $this->story('قصة الصور', 349);
        $payload = $this->basePayload();
        $payload['stories'] = [[
            'story_id' => $story->id,
            'child_name' => 'مريم',
            'child_age' => 5,
            'child_gender' => 'girl',
            'photos' => [UploadedFile::fake()->image('only-one.jpg')],
        ]];

        $this->actingAs($this->admin)
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect(route('admin.orders.create'))
            ->assertSessionHasErrors('stories.0.photos');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_discount_cannot_exceed_checkout_total(): void
    {
        $story = $this->story('قصة الخصم', 100);
        $payload = $this->basePayload();
        $payload['stories'] = [[
            'story_id' => $story->id,
            'child_name' => 'عمر',
            'child_age' => 7,
            'child_gender' => 'boy',
            'photos' => $this->photos('discount'),
        ]];
        $payload['discount_amount'] = 200;
        $payload['discount_reason'] = 'خصم الخصم المجمع';

        $this->actingAs($this->admin)
            ->from(route('admin.orders.create'))
            ->post(route('admin.orders.store'), $payload)
            ->assertRedirect(route('admin.orders.create'))
            ->assertSessionHasErrors('discount_amount');

        $this->assertDatabaseCount('orders', 0);
    }

    private function basePayload(): array
    {
        return [
            'parent_name' => 'ولي الأمر',
            'phone' => '01012345678',
            'order_source' => 'phone',
            'delivery_country_id' => $this->country->id,
            'delivery_governorate_id' => $this->governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع 1',
            'address_details' => 'الدور الأول',
            'discount_amount' => 0,
        ];
    }

    private function story(string $title, float $price): Story
    {
        return Story::create([
            'title' => $title,
            'slug' => 'admin-order-'.fake()->unique()->slug(3),
            'language' => 'ar',
            'gender' => 'both',
            'price' => $price,
            'active' => true,
        ]);
    }

    /** @return array<int, UploadedFile> */
    private function photos(string $prefix): array
    {
        return [
            UploadedFile::fake()->image($prefix.'-1.jpg', 900, 900),
            UploadedFile::fake()->image($prefix.'-2.jpg', 900, 900),
        ];
    }

    private function schoolStickerPersonalizationFields(): array
    {
        return [
            'version' => 1,
            'fields' => [
                'child_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الطفل كاملًا', 'type' => 'text'],
                'school_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم المدرسة', 'type' => 'text'],
                'class_name' => ['enabled' => true, 'required' => true, 'label' => 'اسم الفصل / الكلاس', 'type' => 'text'],
                'photos' => [
                    'enabled' => true,
                    'required' => true,
                    'label' => 'صور الطفل',
                    'type' => 'photos',
                    'min_files' => 2,
                    'max_files' => 3,
                ],
            ],
        ];
    }
}
