<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_partial_payment_is_saved_for_the_whole_checkout_and_remaining_is_shown(): void
    {
        [$first, $second] = $this->checkout();

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.groups.payment', $first->id), [
                'payment_status' => 'partially_paid',
                'paid_amount' => 200,
                'payment_method' => 'انستاباي',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach ([$first->refresh(), $second->refresh()] as $order) {
            $this->assertSame('partially_paid', $order->payment_status);
            $this->assertSame(20_000, $order->paid_amount_cents);
            $this->assertSame('انستاباي', $order->payment_method);
            $this->assertSame(200, data_get($order->delivery_details, 'paid_amount'));
            $this->assertSame(350, data_get($order->delivery_details, 'remaining_amount'));
            $this->assertSame($this->admin->id, $order->payment_updated_by_user_id);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $first->id))
            ->assertOk()
            ->assertSee('مدفوع جزئياً')
            ->assertSee('المتبقي عند الاستلام')
            ->assertSee('٣٥٠');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['payment_status' => 'partially_paid']))
            ->assertOk()
            ->assertSee('PAYMENT-GROUP')
            ->assertSee('مدفوع جزئياً')
            ->assertSee('متبقي');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['payment_status' => 'paid_in_full']))
            ->assertOk()
            ->assertDontSee('PAYMENT-GROUP');

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'checkout.payment_updated',
            'subject_id' => $first->id,
        ]);
    }

    public function test_paid_without_shipping_and_paid_in_full_calculate_amounts_automatically(): void
    {
        [$first, $second] = $this->checkout();

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.groups.payment', $first->id), [
                'payment_status' => 'paid_without_shipping',
                'payment_method' => 'فودافون كاش',
            ])
            ->assertRedirect();

        $this->assertSame(50_000, $first->refresh()->paid_amount_cents);
        $this->assertSame(50_000, $second->refresh()->paid_amount_cents);

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.groups.payment', $first->id), [
                'payment_status' => 'paid_in_full',
                'payment_method' => 'تحويل بنكي',
            ])
            ->assertRedirect();

        $this->assertSame(55_000, $first->refresh()->paid_amount_cents);
        $this->assertSame(0, data_get($first->delivery_details, 'remaining_amount'));
    }

    public function test_partial_payment_requires_a_valid_amount_and_payment_method(): void
    {
        [$first] = $this->checkout();

        $this->actingAs($this->admin)
            ->from(route('admin.orders.groups.show', $first->id))
            ->patch(route('admin.orders.groups.payment', $first->id), [
                'payment_status' => 'partially_paid',
                'paid_amount' => 550,
            ])
            ->assertRedirect(route('admin.orders.groups.show', $first->id))
            ->assertSessionHasErrors('payment_method');

        $this->actingAs($this->admin)
            ->from(route('admin.orders.groups.show', $first->id))
            ->patch(route('admin.orders.groups.payment', $first->id), [
                'payment_status' => 'partially_paid',
                'paid_amount' => 550,
                'payment_method' => 'نقدي',
            ])
            ->assertRedirect(route('admin.orders.groups.show', $first->id))
            ->assertSessionHasErrors('paid_amount');

        $this->assertSame('unpaid', $first->refresh()->payment_status);
        $this->assertSame(0, $first->paid_amount_cents);
    }

    /** @return array{Order, Order} */
    private function checkout(): array
    {
        $story = Story::create([
            'title' => 'قصة الدفع',
            'slug' => 'payment-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 300,
            'active' => true,
        ]);

        $orders = collect([
            ['number' => 'PAY-1', 'child' => 'ليلى', 'price' => 30_000],
            ['number' => 'PAY-2', 'child' => 'عمر', 'price' => 20_000],
        ])->map(function (array $line) use ($story): Order {
            $order = Order::create([
                'order_number' => $line['number'],
                'checkout_group_key' => 'PAYMENT-GROUP',
                'parent_name' => 'ولي الأمر',
                'story_id' => $story->id,
                'child_name' => $line['child'],
                'child_age' => 6,
                'child_gender' => 'girl',
                'language' => 'ar',
                'delivery_details' => [
                    'checkout_group' => 'PAYMENT-GROUP',
                    'phone' => '01000000000',
                    'delivery_fee' => 50,
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
        });

        return [$orders[0], $orders[1]];
    }
}
