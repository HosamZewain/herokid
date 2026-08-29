<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderGroupMergeAlias;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderGroupMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_merges_two_customer_checkouts_and_keeps_one_delivery_charge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = $this->order('CHECKOUT-TARGET', 'TARGET-ORDER', '01012345678', 34_900, 6_500, 10_000);
        $source = $this->order('CHECKOUT-SOURCE', 'SOURCE-ORDER', '01012345678', 19_500, 6_500, 5_000);
        $sourceShortReference = $source->checkoutReference()->value('short_reference');
        $targetShortReference = $target->checkoutReference()->value('short_reference');

        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $target))
            ->assertOk()
            ->assertSee('<title>'.$targetShortReference.' — '.config('app.name').'</title>', false)
            ->assertSee('دمج طلب آخر مع هذه العملية')
            ->assertSeeInOrder(['دمج طلب آخر مع هذه العملية', 'العودة إلى الطلبات'])
            ->assertSee(route('admin.orders.groups.merge', $target), false);

        $this->actingAs($admin)
            ->post(route('admin.orders.groups.merge', $target), [
                'source_reference' => $sourceShortReference,
                'merge_reason' => 'طلب ثانٍ لنفس العميل وطفل آخر',
                'confirm_primary_delivery' => '1',
            ])
            ->assertRedirect(route('admin.orders.groups.show', $target))
            ->assertSessionHas('success');

        $target->refresh();
        $source->refresh();

        $this->assertSame('CHECKOUT-TARGET', $source->checkout_group_key);
        $this->assertSame(2, Order::query()->where('checkout_group_key', 'CHECKOUT-TARGET')->count());
        $this->assertSame(34_900 + 19_500 + 6_500, (int) data_get($target->delivery_details, 'total') * 100);
        $this->assertSame(15_000, $target->paid_amount_cents);
        $this->assertSame(15_000, $source->paid_amount_cents);
        $this->assertSame(2, Order::query()->where('checkout_group_key', 'CHECKOUT-TARGET')->withCount('items')->get()->sum('items_count'));

        $alias = OrderGroupMergeAlias::query()->firstOrFail();
        $this->assertSame('CHECKOUT-SOURCE', $alias->source_checkout_group_key);
        $this->assertSame('CHECKOUT-TARGET', $alias->target_checkout_group_key);
        $this->assertSame(6_500, $alias->removed_delivery_fee_cents);
        $this->assertSame($sourceShortReference, $alias->source_short_reference);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'checkout.groups_merged',
            'subject_id' => $target->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.orders.index', [
            'catalog_type' => 'products',
            'q' => $sourceShortReference,
        ]));
        $this->assertSame(1, $response->viewData('groups')->total());
        $response->assertSee('CHECKOUT-TARGET');
    }

    public function test_merge_is_rejected_for_different_customers_or_started_shipping(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = $this->order('CHECKOUT-A', 'ORDER-A', '01012345678', 34_900, 6_500);
        $differentCustomer = $this->order('CHECKOUT-B', 'ORDER-B', '01199999999', 34_900, 6_500);

        $this->actingAs($admin)
            ->from(route('admin.orders.groups.show', $target))
            ->post(route('admin.orders.groups.merge', $target), [
                'source_reference' => $differentCustomer->checkoutReference()->value('short_reference'),
                'merge_reason' => 'محاولة دمج عميل مختلف',
                'confirm_primary_delivery' => '1',
            ])
            ->assertRedirect(route('admin.orders.groups.show', $target))
            ->assertSessionHasErrors('source_reference');

        $differentCustomer->update([
            'delivery_details' => array_merge($differentCustomer->delivery_details, ['phone' => '01012345678']),
            'shipping_status' => 'shipped',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.orders.groups.merge', $target), [
                'source_reference' => $differentCustomer->checkoutReference()->value('short_reference'),
                'merge_reason' => 'محاولة دمج طلب تم شحنه',
                'confirm_primary_delivery' => '1',
            ])
            ->assertSessionHasErrors('source_reference');

        $this->assertSame('CHECKOUT-B', $differentCustomer->refresh()->checkout_group_key);
        $this->assertDatabaseCount('order_group_merge_aliases', 0);
    }

    public function test_merge_rejects_an_overpayment_after_removing_duplicate_shipping(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = $this->order('CHECKOUT-PAID-A', 'ORDER-PAID-A', '01012345678', 10_000, 6_500, 16_500);
        $source = $this->order('CHECKOUT-PAID-B', 'ORDER-PAID-B', '01012345678', 10_000, 6_500, 16_500);

        $this->actingAs($admin)
            ->post(route('admin.orders.groups.merge', $target), [
                'source_reference' => $source->checkoutReference()->value('short_reference'),
                'merge_reason' => 'دمج طلبين مدفوعين بالكامل',
                'confirm_primary_delivery' => '1',
            ])
            ->assertSessionHasErrors('source_reference');

        $this->assertSame('CHECKOUT-PAID-B', $source->refresh()->checkout_group_key);
        $this->assertDatabaseCount('order_group_merge_aliases', 0);
    }

    public function test_merge_route_requires_orders_update_permission(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->permissions()->sync(Permission::query()->where('key', 'orders.view')->pluck('id'));
        $admin->unsetRelation('permissions');
        $target = $this->order('CHECKOUT-LIMITED-A', 'ORDER-LIMITED-A', '01012345678', 10_000, 0);
        $source = $this->order('CHECKOUT-LIMITED-B', 'ORDER-LIMITED-B', '01012345678', 10_000, 0);

        $this->actingAs($admin)
            ->post(route('admin.orders.groups.merge', $target), [
                'source_reference' => $source->checkoutReference()->value('short_reference'),
                'merge_reason' => 'محاولة بدون صلاحية تحديث',
                'confirm_primary_delivery' => '1',
            ])
            ->assertForbidden();
    }

    private function order(
        string $group,
        string $number,
        string $phone,
        int $itemCents,
        int $deliveryCents,
        int $paidCents = 0,
    ): Order {
        $order = Order::query()->create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'parent_name' => 'ولي الأمر',
            'delivery_details' => [
                'checkout_group' => $group,
                'phone' => $phone,
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'street' => 'شارع الاختبار',
                'delivery_fee' => $deliveryCents / 100,
                'total' => ($itemCents + $deliveryCents) / 100,
            ],
            'uploaded_photos' => [],
            'status' => 'new',
            'payment_status' => $paidCents > 0 ? 'partially_paid' : 'unpaid',
            'paid_amount_cents' => $paidCents,
            'payment_method' => $paidCents > 0 ? 'instapay' : null,
            'printing_status' => 'not_started',
            'shipping_status' => 'not_ready',
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'title' => 'منتج '.$number,
            'unit_price_cents' => $itemCents,
            'quantity' => 1,
            'total_price_cents' => $itemCents,
        ]);

        return $order->load('checkoutReference');
    }
}
