<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_apply_a_fixed_discount_to_the_whole_checkout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $second] = $this->checkout();

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'fixed',
                'discount_value' => 50,
                'discount_mode' => 'add',
                'discount_reason' => 'تعويض العميل عن التأخير',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach ([$first->refresh(), $second->refresh()] as $order) {
            $this->assertSame(5_000, $order->discount_cents);
            $this->assertSame('تعويض العميل عن التأخير', $order->discount_reason);
            $this->assertSame(50, data_get($order->delivery_details, 'discount'));
            $this->assertSame(500, data_get($order->delivery_details, 'total'));
            $this->assertSame(500, data_get($order->delivery_details, 'remaining_amount'));
        }

        $group = app(AdminOrderGroupService::class)->findByRepresentative($first->id);
        $this->assertSame(50_000, $group['total_cents']);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'checkout.discount_updated',
            'subject_id' => $first->id,
        ]);
    }

    public function test_percentage_discount_can_replace_an_existing_discount(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $second] = $this->checkout(discountCents: 2_500);

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'discount_mode' => 'replace',
                'discount_reason' => 'خصم عشرة بالمائة',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(5_000, $first->refresh()->discount_cents);
        $this->assertSame(5_000, $second->refresh()->discount_cents);
        $this->assertSame('خصم عشرة بالمائة', $first->discount_reason);
        $this->assertSame(50_000, app(AdminOrderGroupService::class)->findByRepresentative($first->id)['total_cents']);
    }

    public function test_discount_never_reduces_delivery_fees(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $second] = $this->checkout();

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'percentage',
                'discount_value' => 100,
                'discount_mode' => 'replace',
                'discount_reason' => 'إعفاء كامل من قيمة المنتجات فقط',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $group = app(AdminOrderGroupService::class)->findByRepresentative($first->id);
        $this->assertSame(50_000, $first->refresh()->discount_cents);
        $this->assertSame(50_000, $second->refresh()->discount_cents);
        $this->assertSame(5_000, $group['delivery_cents']);
        $this->assertSame(5_000, $group['total_cents']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'fixed',
                'discount_value' => 501,
                'discount_mode' => 'replace',
                'discount_reason' => 'محاولة خصم أكبر من المنتجات',
            ])
            ->assertSessionHasErrors('discount_value');

        $this->assertSame(50_000, $first->refresh()->discount_cents);
    }

    public function test_discount_revalues_paid_order_without_recording_collection_or_refund_stats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $second] = $this->checkout(paymentStatus: 'paid_in_full', paidAmountCents: 55_000);

        $this->actingAs($admin)
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'fixed',
                'discount_value' => 50,
                'discount_mode' => 'replace',
                'discount_reason' => 'خصم بعد السداد الكامل',
            ])
            ->assertRedirect();

        foreach ([$first->refresh(), $second->refresh()] as $order) {
            $this->assertSame('paid_in_full', $order->payment_status);
            $this->assertSame(50_000, $order->paid_amount_cents);
            $this->assertSame(0, data_get($order->delivery_details, 'remaining_amount'));
        }

        $event = OrderPaymentEvent::query()
            ->where('checkout_group_key', 'DISCOUNT-GROUP')
            ->where('source', 'admin_discount_update')
            ->sole();
        $this->assertSame('discount_adjustment', $event->event_type);
        $this->assertSame(-5_000, $event->amount_delta_cents);
        $this->assertFalse($event->affects_collection_stats);
    }

    public function test_discount_requires_its_own_permission_and_form_is_hidden_without_it(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::query()->where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');
        [$first] = $this->checkout();

        $this->actingAs($limited)
            ->get(route('admin.orders.groups.show', $first))
            ->assertOk()
            ->assertDontSee('إضافة أو تعديل خصم');

        $this->actingAs($limited)
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'fixed',
                'discount_value' => 50,
                'discount_mode' => 'replace',
                'discount_reason' => 'محاولة غير مصرح بها',
            ])
            ->assertForbidden();

        $this->assertSame(0, $first->refresh()->discount_cents);
    }

    public function test_invalid_or_unreasoned_discount_does_not_change_the_checkout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first] = $this->checkout();

        $this->actingAs($admin)
            ->from(route('admin.orders.groups.show', $first))
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'percentage',
                'discount_value' => 101,
                'discount_mode' => 'replace',
                'discount_reason' => 'خصم غير صحيح',
            ])
            ->assertRedirect(route('admin.orders.groups.show', $first))
            ->assertSessionHasErrors('discount_value');

        $this->actingAs($admin)
            ->from(route('admin.orders.groups.show', $first))
            ->patch(route('admin.orders.groups.discount', $first), [
                'discount_type' => 'fixed',
                'discount_value' => 50,
                'discount_mode' => 'replace',
            ])
            ->assertSessionHasErrors('discount_reason');

        $this->assertSame(0, $first->refresh()->discount_cents);
    }

    /** @return array{Order, Order} */
    private function checkout(
        int $discountCents = 0,
        string $paymentStatus = 'unpaid',
        int $paidAmountCents = 0,
    ): array {
        $story = Story::create([
            'title' => 'قصة الخصم السريع',
            'slug' => 'quick-discount-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 300,
            'active' => true,
        ]);

        return collect([
            ['number' => 'DISCOUNT-1', 'child' => 'ليلى', 'price' => 30_000],
            ['number' => 'DISCOUNT-2', 'child' => 'عمر', 'price' => 20_000],
        ])->map(function (array $line) use ($story, $discountCents, $paymentStatus, $paidAmountCents): Order {
            $order = Order::create([
                'order_number' => $line['number'],
                'checkout_group_key' => 'DISCOUNT-GROUP',
                'parent_name' => 'ولي الأمر',
                'story_id' => $story->id,
                'child_name' => $line['child'],
                'child_age' => 6,
                'child_gender' => 'girl',
                'language' => 'ar',
                'discount_cents' => $discountCents,
                'discount_reason' => $discountCents > 0 ? 'خصم سابق' : null,
                'payment_status' => $paymentStatus,
                'paid_amount_cents' => $paidAmountCents,
                'payment_method' => $paidAmountCents > 0 ? 'انستاباي' : null,
                'delivery_details' => [
                    'checkout_group' => 'DISCOUNT-GROUP',
                    'phone' => '01000000000',
                    'delivery_fee' => 50,
                    'discount' => $discountCents / 100,
                    'total' => (55_000 - $discountCents) / 100,
                    'payment_status' => $paymentStatus,
                    'payment_method' => $paidAmountCents > 0 ? 'انستاباي' : null,
                    'paid_amount' => $paidAmountCents / 100,
                    'remaining_amount' => max(0, 55_000 - $discountCents - $paidAmountCents) / 100,
                ],
                'uploaded_photos' => [],
                'status' => 'new',
            ]);
            $order->items()->create([
                'item_type' => 'story',
                'story_id' => $story->id,
                'title' => $story->title,
                'unit_price_cents' => $line['price'],
                'quantity' => 1,
                'total_price_cents' => $line['price'],
            ]);

            return $order;
        })->all();
    }
}
