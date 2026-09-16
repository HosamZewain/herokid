<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatusRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderMultiStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['A' => ['new'], 'B' => ['generating'], 'C' => ['preview_uploaded'], 'D' => ['printing', 'under_review'], 'E' => ['cancelled']] as $key => $statuses) {
            foreach ($statuses as $i => $status) {
                $order = Order::create([
                    'order_number' => "MULTI-$key-$i", 'checkout_group_key' => "MULTI-$key",
                    'parent_name' => "Filter $key", 'status' => $status, 'shipping_status' => 'not_ready',
                ]);
                $order->items()->create(['item_type' => 'product', 'title' => 'Filter fixture', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
            }
        }
    }

    private function listing(array $filters = [])
    {
        return $this->get(route('admin.orders.index', array_merge(['catalog_type' => 'all', 'lifecycle' => 'all'], $filters)));
    }

    public function test_selected_statuses_are_ored_and_statistics_use_the_same_filter(): void
    {
        $this->listing(['status' => ['new', 'generating']])->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->total() === 2 && collect($groups->items())->pluck('key')->sort()->values()->all() === ['MULTI-A', 'MULTI-B'])
            ->assertViewHas('stats', fn ($stats) => $stats['checkouts'] === 2)
            ->assertViewHas('selectedStatuses', ['new', 'generating'])
            ->assertSee('name="status[]"', false);
    }

    public function test_legacy_single_status_and_empty_selection_remain_supported(): void
    {
        $this->listing(['status' => 'new'])->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === 1);
        $this->listing(['status' => ''])->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === 5);
        $this->listing(['status' => []])->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === 5);
    }

    public function test_mixed_checkout_status_is_a_union_option_not_an_intersection(): void
    {
        $this->listing(['status' => ['mixed', 'new']])->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->total() === 2 && collect($groups->items())->pluck('key')->sort()->values()->all() === ['MULTI-A', 'MULTI-D']);
        $this->listing(['status' => 'mixed'])->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->total() === 1 && $groups->items()[0]['key'] === 'MULTI-D');
    }

    public function test_search_and_lifecycle_still_intersect_with_status_selection(): void
    {
        $this->listing(['status' => ['new', 'generating'], 'q' => 'MULTI-A'])->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->total() === 1);
        $this->listing(['status' => ['new', 'cancelled'], 'lifecycle' => 'active'])->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->total() === 1 && $groups->items()[0]['key'] === 'MULTI-A');
    }

    public function test_export_uses_the_multi_status_filter(): void
    {
        $response = $this->get(route('admin.orders.export', ['catalog_type' => 'all', 'lifecycle' => 'all', 'status' => ['new', 'generating']]))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('MULTI-A', $csv);
        $this->assertStringContainsString('MULTI-B', $csv);
        $this->assertStringNotContainsString('MULTI-C', $csv);
        $this->assertStringNotContainsString('MULTI-D', $csv);
    }

    public function test_invalid_and_nested_statuses_are_rejected(): void
    {
        foreach ([['invalid-status'], [['new']]] as $statuses) {
            $this->getJson(route('admin.orders.index', ['status' => $statuses]))
                ->assertUnprocessable()->assertJsonValidationErrors('status.0');
        }
    }

    public function test_duplicates_are_normalized_and_page_links_preserve_the_selection(): void
    {
        $this->listing(['status' => ['new', 'new', 'generating']])->assertOk()
            ->assertViewHas('selectedStatuses', ['new', 'generating'])
            ->assertViewHas('groups', function ($groups) {
                parse_str(parse_url($groups->url(2), PHP_URL_QUERY), $query);

                return $query['status'] === ['new', 'new', 'generating'];
            });
    }

    public function test_shipping_printing_and_payment_support_multiple_single_and_empty_values(): void
    {
        foreach (['shipping_status' => 'shipping', 'printing_status' => 'printing', 'payment_status' => 'payment'] as $field => $type) {
            $values = array_slice(OrderStatusRegistry::keys($type, false), 0, 3);
            Order::query()->update([$field => $values[2]]);
            Order::where('checkout_group_key', 'MULTI-A')->update([$field => $values[0]]);
            Order::where('checkout_group_key', 'MULTI-B')->update([$field => $values[1]]);
            $this->listing([$field => [$values[0], $values[1]]])->assertOk()
                ->assertViewHas('groups', fn ($groups) => $groups->total() === 2)
                ->assertViewHas('stats', fn ($stats) => $stats['checkouts'] === 2)
                ->assertSee('name="'.$field.'[]"', false);
            $this->listing([$field => $values[0]])->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === 1);
            $this->listing([$field => ''])->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === 5);
            $this->getJson(route('admin.orders.index', [$field => ['invalid-status']]))->assertUnprocessable();
        }
    }

    public function test_multiple_filters_intersect_and_export_keeps_that_intersection(): void
    {
        Order::query()->update(['payment_status' => 'unpaid']);
        Order::where('checkout_group_key', 'MULTI-B')->update(['payment_status' => 'paid_in_full']);
        $filters = ['status' => ['new', 'generating'], 'payment_status' => ['paid_in_full', 'partially_paid']];
        $this->listing($filters)->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === 1 && $groups->items()[0]['key'] === 'MULTI-B');
        $csv = $this->get(route('admin.orders.export', array_merge(['catalog_type' => 'all', 'lifecycle' => 'all'], $filters)))->assertOk()->streamedContent();
        $this->assertStringContainsString('MULTI-B', $csv);
        $this->assertStringNotContainsString('MULTI-A', $csv);
    }

    public function test_status_filters_use_visible_rows_instead_of_superseded_deleted_rows(): void
    {
        $cancelled = $this->fixtureOrder('VISIBLE-CANCELLED', 'cancelled');
        $finished = $this->fixtureOrder('VISIBLE-FINISHED', 'delivered');
        $finished->update([
            'payment_status' => 'paid_in_full',
            'printing_status' => 'completed',
            'shipping_status' => 'delivered',
        ]);

        $this->fixtureOrder($finished->checkout_group_key, 'ready_preview')->delete();
        $historicalOrder = $this->fixtureOrder($cancelled->checkout_group_key, 'ready_preview');
        $historicalOrder->delete();

        $fullyDeleted = $this->fixtureOrder('VISIBLE-DELETED', 'ready_preview');
        $fullyDeleted->delete();

        $this->listing(['q' => $historicalOrder->order_number])->assertOk()
            ->assertViewHas('groups', fn ($groups) => collect($groups->items())->pluck('key')->all() === ['VISIBLE-CANCELLED']);

        $matching = $this->listing(['status' => 'ready_preview'])->assertOk()->viewData('groups');
        $this->assertSame(['VISIBLE-DELETED'], collect($matching->items())->pluck('key')->all());

        $cancelledMatches = $this->listing(['lifecycle' => 'cancelled', 'status' => 'ready_preview'])
            ->assertOk()->viewData('groups');
        $this->assertSame(['VISIBLE-DELETED'], collect($cancelledMatches->items())->pluck('key')->all());

        $this->listing(['lifecycle' => 'finished', 'status' => 'ready_preview'])->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->total() === 0);
        $this->listing(['lifecycle' => 'active', 'status' => 'ready_preview'])->assertOk()
            ->assertViewHas('groups', fn ($groups) => $groups->total() === 0);
        $this->listing(['lifecycle' => 'cancelled', 'status' => 'cancelled'])->assertOk()
            ->assertViewHas('groups', fn ($groups) => collect($groups->items())->pluck('key')->contains('VISIBLE-CANCELLED'));
        $this->listing(['lifecycle' => 'finished'])->assertOk()
            ->assertViewHas('groups', fn ($groups) => collect($groups->items())->pluck('key')->all() === ['VISIBLE-FINISHED']);

        $csv = $this->get(route('admin.orders.export', [
            'catalog_type' => 'all', 'lifecycle' => 'all', 'status' => 'ready_preview',
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('VISIBLE-DELETED', $csv);
        $this->assertStringNotContainsString('VISIBLE-CANCELLED', $csv);
        $this->assertStringNotContainsString('VISIBLE-FINISHED', $csv);
    }

    public function test_every_lifecycle_has_a_visible_tab_and_current_indicator(): void
    {
        foreach (['all', 'active', 'finished', 'cancelled'] as $lifecycle) {
            $response = $this->listing(['lifecycle' => $lifecycle])->assertOk()
                ->assertSee('كل الطلبات')
                ->assertSee('الطلبات النشطة')
                ->assertSee('الطلبات المنتهية')
                ->assertSee('ملغاة / محذوفة');

            $this->assertMatchesRegularExpression(
                '/<a href="[^"]*lifecycle='.$lifecycle.'[^"]*"[^>]*aria-current="page"[^>]*>/u',
                $response->getContent(),
            );
        }
    }

    private function fixtureOrder(string $group, string $status): Order
    {
        $order = Order::create([
            'order_number' => $group.'-'.str()->random(6),
            'checkout_group_key' => $group,
            'parent_name' => 'Filter fixture',
            'status' => $status,
            'shipping_status' => 'not_ready',
        ]);
        $order->items()->create([
            'item_type' => 'product', 'title' => 'Filter fixture', 'quantity' => 1,
            'unit_price_cents' => 10000, 'total_price_cents' => 10000,
        ]);

        return $order;
    }
}
