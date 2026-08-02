<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
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
        $this->assertSame(0.0, $report['summary']['total']);
        $this->assertSame(0.0, $report['summary']['discounts']);
        $this->assertSame(1, $report['operational_summary']['all_checkouts']);
        $this->assertSame(1, $report['operational_summary']['partially_paid_checkouts']);
        $this->assertSame('واتساب', $report['rows']->first()['source']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('واتساب')
            ->assertSee('خصم -');
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
}
