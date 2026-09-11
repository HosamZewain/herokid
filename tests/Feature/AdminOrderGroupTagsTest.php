<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderTag;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderGroupTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_tags_for_the_whole_checkout_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $second] = $this->checkout('TAGGED-GROUP', ['HK-TAG-1', 'HK-TAG-2']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.tags', $first), [
                'tags' => 'عاجل، هدية, عاجل, VIP, vip',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $tags = $first->checkoutReference->fresh()->tags()->orderBy('normalized_name')->get();
        $this->assertSame(['VIP', 'عاجل', 'هدية'], $tags->pluck('name')->all());
        $this->assertSame(
            $tags->modelKeys(),
            $second->checkoutReference->fresh()->tags()->orderBy('normalized_name')->get()->modelKeys(),
        );
        $this->assertDatabaseCount('order_tags', 3);
        $this->assertDatabaseCount('order_checkout_reference_tag', 3);

        $activity = AdminActivityLog::query()->where('action', 'checkout.tags_updated')->sole();
        $this->assertSame($admin->id, $activity->user_id);
        $this->assertSame($first->id, $activity->subject_id);
        $this->assertSame([], $activity->properties['before']);
        $this->assertSame(['VIP', 'عاجل', 'هدية'], $activity->properties['after']);
    }

    public function test_tags_are_visible_and_can_be_cleared_from_the_checkout_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$order] = $this->checkout('VISIBLE-TAGS', ['HK-TAG-VISIBLE']);

        $this->actingAs($admin)->patch(route('admin.orders.groups.tags', $order), [
            'tags' => 'متابعة، طباعة خاصة',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSeeInOrder(['data-order-group-tags', 'data-admin-order-quick-search'], false)
            ->assertSee('علامات عملية الشراء')
            ->assertSee('متابعة')
            ->assertSee('طباعة خاصة')
            ->assertSee(route('admin.orders.groups.tags', $order), false);

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.tags', $order), ['tags' => ''])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('order_checkout_reference_tag', 0);
    }

    public function test_orders_can_be_searched_and_filtered_by_checkout_tag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$tagged] = $this->checkout('SEARCH-TAGGED', ['HK-TAG-SEARCH']);
        [$other] = $this->checkout('SEARCH-OTHER', ['HK-TAG-OTHER']);

        $this->actingAs($admin)->patch(route('admin.orders.groups.tags', $tagged), [
            'tags' => 'عميل مهم',
        ]);
        $tag = OrderTag::query()->where('normalized_name', 'عميل مهم')->sole();

        $search = $this->actingAs($admin)->get(route('admin.orders.index', ['q' => 'عميل مهم']))->assertOk();
        $search->assertSee('SEARCH-TAGGED')->assertDontSee('SEARCH-OTHER');
        $this->assertSame(1, $search->viewData('groups')->total());

        $filter = $this->actingAs($admin)->get(route('admin.orders.index', ['tag_id' => $tag->id]))->assertOk();
        $filter
            ->assertSee('SEARCH-TAGGED')
            ->assertDontSee('SEARCH-OTHER')
            ->assertSeeInOrder(['المحتويات', 'العلامات', 'الحالة', 'القيمة والدفع', 'آخر تحديث'])
            ->assertDontSee('>القيمة</th>', false)
            ->assertDontSee('>الدفع</th>', false);
        $this->assertSame(1, $filter->viewData('groups')->total());

        $report = $this->actingAs($admin)->get(route('admin.order-report.index', ['tag_id' => $tag->id]))->assertOk();
        $this->assertSame(1, $report->viewData('report')['summary']['checkouts']);
        $this->assertSame($tagged->checkoutGroupKey(), $report->viewData('report')['rows']->first()['key']);
        $this->assertNotSame($other->checkoutGroupKey(), $report->viewData('report')['rows']->first()['key']);
    }

    public function test_tag_updates_require_order_update_permission(): void
    {
        $viewOnly = User::factory()->create(['role' => 'admin']);
        $viewOnly->permissions()->sync(
            Permission::query()->where('key', 'orders.view')->pluck('id'),
        );
        [$order] = $this->checkout('TAG-PERMISSION', ['HK-TAG-PERMISSION']);

        $this->actingAs($viewOnly)
            ->patch(route('admin.orders.groups.tags', $order), ['tags' => 'غير مسموح'])
            ->assertForbidden();

        $this->assertDatabaseCount('order_tags', 0);
        $this->assertDatabaseCount('order_checkout_reference_tag', 0);
    }

    public function test_tag_count_and_length_are_validated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$order] = $this->checkout('TAG-VALIDATION', ['HK-TAG-VALIDATION']);

        $this->actingAs($admin)
            ->from(route('admin.orders.groups.show', $order))
            ->patch(route('admin.orders.groups.tags', $order), [
                'tags' => collect(range(1, 13))->map(fn (int $tag): string => 'علامة '.$tag)->implode('،'),
            ])
            ->assertRedirect(route('admin.orders.groups.show', $order))
            ->assertSessionHasErrors('tags');

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.tags', $order), [
                'tags' => str_repeat('أ', 41),
            ])
            ->assertSessionHasErrors('tags');

        $this->assertDatabaseCount('order_tags', 0);
    }

    /** @return array<int, Order> */
    private function checkout(string $groupKey, array $orderNumbers): array
    {
        $story = Story::query()->create([
            'title' => 'قصة العلامات '.$groupKey,
            'slug' => 'tag-story-'.strtolower($groupKey),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 299,
            'active' => true,
        ]);

        return collect($orderNumbers)
            ->map(fn (string $orderNumber): Order => Order::query()->create([
                'order_number' => $orderNumber,
                'checkout_group_key' => $groupKey,
                'parent_name' => 'ولي الأمر '.$groupKey,
                'story_id' => $story->id,
                'child_name' => 'طفل '.$groupKey,
                'child_age' => 7,
                'child_gender' => 'boy',
                'language' => 'ar',
                'delivery_details' => [
                    'checkout_group' => $groupKey,
                    'phone' => '201000000000',
                ],
                'status' => 'new',
            ]))
            ->each(fn (Order $order) => $order->items()->create([
                'item_type' => 'story',
                'story_id' => $story->id,
                'title' => $story->title,
                'unit_price_cents' => 29_900,
                'quantity' => 1,
                'total_price_cents' => 29_900,
            ]))
            ->all();
    }
}
