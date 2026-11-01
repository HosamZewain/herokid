<?php

namespace Tests\Feature;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\AutomationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
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

    public function test_pending_review_is_not_money_and_staff_can_record_real_payment_later(): void
    {
        [$first, $second] = $this->checkout();
        $this->actingAs($this->admin)->patch(route('admin.orders.groups.payment', $first->id), [
            'payment_status' => 'pending_review',
        ])->assertSessionHas('success');
        $this->assertSame('pending_review', $first->fresh()->payment_status);
        $this->assertSame(0, $first->fresh()->paid_amount_cents);
        $this->assertDatabaseHas('order_payment_events', ['new_status' => 'pending_review', 'amount_delta_cents' => 0, 'affects_collection_stats' => false]);
        $this->actingAs($this->admin)->patch(route('admin.orders.groups.payment', $first->id), [
            'payment_status' => 'partially_paid', 'paid_amount' => 200, 'payment_method' => 'انستاباي',
        ])->assertSessionHas('success');
        $this->assertSame(20000, $first->fresh()->paid_amount_cents);
        $this->assertSame(20000, $second->fresh()->paid_amount_cents);
        $this->assertDatabaseHas('order_payment_events', ['previous_status' => 'pending_review', 'amount_delta_cents' => 20000, 'affects_collection_stats' => true, 'actor_user_id' => $this->admin->id]);
    }

    public function test_pending_review_cannot_erase_an_existing_deposit_on_any_checkout_row(): void
    {
        [$first, $second] = $this->checkout();
        $second->updateQuietly(['paid_amount_cents' => 20000, 'payment_status' => 'partially_paid']);
        $this->actingAs($this->admin)->patch(route('admin.orders.groups.payment', $first->id), [
            'payment_status' => 'pending_review',
        ])->assertSessionHasErrors('payment_status');
        $this->assertSame(20000, $second->fresh()->paid_amount_cents);
        $this->assertSame('partially_paid', $second->fresh()->payment_status);
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
            ->get(route('admin.orders.show', $first))
            ->assertOk()
            ->assertSee('مدفوع جزئياً')
            ->assertSee('المتبقي عند الاستلام')
            ->assertSee('٣٥٠')
            ->assertSee("amount.disabled = value !== 'partially_paid'", false);

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
        $this->assertDatabaseHas('order_payment_events', [
            'checkout_group_key' => 'PAYMENT-GROUP',
            'order_id' => $first->id,
            'actor_user_id' => $this->admin->id,
            'event_type' => 'payment_received',
            'source' => 'admin_payment_update',
            'previous_paid_amount_cents' => 0,
            'new_paid_amount_cents' => 20_000,
            'amount_delta_cents' => 20_000,
            'affects_collection_stats' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $first))
            ->assertOk()
            ->assertSee('سجل الدفع المعتمد')
            ->assertSee('دفعة مستلمة')
            ->assertSee('تحديث الدفع من صفحة الطلب');
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

    public function test_saving_workflow_without_a_payment_change_does_not_create_a_payment_event(): void
    {
        [$first, $second] = $this->checkout();

        foreach ([$first, $second] as $order) {
            $order->forceFill([
                'payment_status' => 'partially_paid',
                'paid_amount_cents' => 20_000,
                'payment_method' => 'انستاباي',
                'payment_updated_at' => now()->subDay(),
            ])->save();
        }

        $originalPaymentTime = $first->refresh()->payment_updated_at;
        $eventsBefore = OrderPaymentEvent::query()->where('checkout_group_key', 'PAYMENT-GROUP')->count();

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.groups.workflow-statuses', $first->id), [
                'status' => 'new',
                'payment_status' => 'partially_paid',
                'paid_amount' => 200,
                'payment_method' => 'انستاباي',
                'printing_status' => 'printing_not_started',
                'shipping_status' => 'shipping_not_ready',
            ])
            ->assertRedirect();

        $this->assertTrue($first->refresh()->payment_updated_at->equalTo($originalPaymentTime));
        $this->assertDatabaseMissing('admin_activity_logs', [
            'action' => 'checkout.payment_updated',
            'subject_id' => $first->id,
        ]);
        $this->assertSame($eventsBefore, OrderPaymentEvent::query()->where('checkout_group_key', 'PAYMENT-GROUP')->count());
    }

    public function test_payment_reversal_is_recorded_with_a_signed_delta_and_ledger_rows_are_immutable(): void
    {
        [$first] = $this->checkout();

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.groups.payment', $first->id), [
                'payment_status' => 'partially_paid',
                'paid_amount' => 200,
                'payment_method' => 'انستاباي',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.groups.payment', $first->id), [
                'payment_status' => 'partially_paid',
                'paid_amount' => 100,
                'payment_method' => 'انستاباي',
            ])
            ->assertRedirect();

        $event = OrderPaymentEvent::query()
            ->where('checkout_group_key', 'PAYMENT-GROUP')
            ->where('event_type', 'payment_reversed')
            ->firstOrFail();

        $this->assertSame(20_000, $event->previous_paid_amount_cents);
        $this->assertSame(10_000, $event->new_paid_amount_cents);
        $this->assertSame(-10_000, $event->amount_delta_cents);

        $this->expectException(LogicException::class);
        $event->update(['amount_delta_cents' => 0]);
    }

    public function test_resaving_settled_status_after_discount_preserves_the_actual_overpayment(): void
    {
        [$first, $second] = $this->checkout();
        foreach ([$first, $second] as $order) {
            $order->forceFill(['paid_amount_cents' => 55000, 'payment_status' => 'paid_in_full',
                'payment_method' => 'انستاباي', 'discount_cents' => 5000])->saveQuietly();
        }
        $this->actingAs($this->admin)->patch(route('admin.orders.groups.payment', $first->id), [
            'payment_status' => 'paid_in_full', 'payment_method' => 'تحويل بنكي',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(55000, $first->refresh()->paid_amount_cents);
        $this->assertSame(55000, $second->refresh()->paid_amount_cents);
        $this->assertSame(0, OrderPaymentEvent::where('affects_collection_stats', true)->count());
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

    public function test_combined_form_records_payment_while_automation_owns_checkout(): void
    {
        [$first, $second] = $this->checkout();
        $this->reserveForAutomation($first);
        $values = $this->paymentForm($first);
        $before = [$first->fresh()->getAttributes(), $second->fresh()->getAttributes()];
        $eventsBefore = OrderPaymentEvent::count();

        $this->actingAs($this->admin)->patch(route('admin.orders.groups.workflow-statuses', $first), $values)
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        foreach ([$first->fresh(), $second->fresh()] as $i => $order) {
            $this->assertSame(20000, $order->paid_amount_cents);
            $this->assertSame($this->admin->id, $order->payment_updated_by_user_id);
            foreach (['status', 'printing_status', 'shipping_status'] as $column) {
                $this->assertSame($before[$i][$column] ?? null, $order->{$column});
            }
        }
        $this->assertTrue(app(AutomationAssignment::class)->owns($first));
        $this->assertDatabaseHas('order_payment_events', ['actor_user_id' => $this->admin->id, 'amount_delta_cents' => 20000]);
        $this->actingAs($this->admin)->patch(route('admin.orders.groups.workflow-statuses', $first), $values)
            ->assertSessionHasNoErrors();
        $this->assertSame($eventsBefore + 1, OrderPaymentEvent::count());
        $this->assertSame(0, $first->statusLogs()->count());
    }

    public function test_payment_on_mixed_story_product_statuses_preserves_each_status(): void
    {
        [$first, $second] = $this->checkout();
        $second->updateQuietly(['status' => 'new_confirmed']);
        $this->reserveForAutomation($first);
        $values = $this->paymentForm($first);
        $values['status'] = null;
        $this->actingAs($this->admin)->patch(route('admin.orders.groups.workflow-statuses', $first), $values)
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('new', $first->fresh()->status);
        $this->assertSame('new_confirmed', $second->fresh()->status);
        $this->assertSame(20000, $second->fresh()->paid_amount_cents);
    }

    public function test_combined_payment_and_production_change_is_rejected_atomically(): void
    {
        [$first, $second] = $this->checkout();
        $this->reserveForAutomation($first);
        $eventsBefore = OrderPaymentEvent::count();
        foreach (['status' => 'new_confirmed', 'printing_status' => 'completed', 'shipping_status' => 'shipped'] as $column => $changed) {
            $values = $this->paymentForm($first);
            $values[$column] = $changed;
            $this->actingAs($this->admin)->patch(route('admin.orders.groups.workflow-statuses', $first), $values)
                ->assertSessionHasErrors($column);
            $this->assertSame(0, $first->fresh()->paid_amount_cents);
            $this->assertSame(0, $second->fresh()->paid_amount_cents);
            $this->assertSame('new', $first->fresh()->status);
            $this->assertSame($eventsBefore, OrderPaymentEvent::count());
        }
    }

    private function reserveForAutomation(Order $order): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        CodexSceneRun::create([
            'id' => (string) Str::uuid(), 'order_id' => $order->id,
            'checkout_key' => $order->checkoutGroupKey(), 'requested_by' => $this->admin->id,
            'status' => 'running', 'active_slot' => 1, 'previous_status' => $order->status,
            'fingerprint' => hash('sha256', 'synthetic'), 'snapshot' => [], 'scenes' => [],
        ]);
        $this->assertTrue(app(AutomationAssignment::class)->owns($order));
    }

    private function paymentForm(Order $order): array
    {
        $group = app(AdminOrderGroupService::class)->findByRepresentative($order->id);

        return [
            'status' => $group['status'], 'printing_status' => $group['printing_status'],
            'shipping_status' => $group['shipping_status'], 'payment_status' => 'partially_paid',
            'paid_amount' => 200, 'payment_method' => 'انستاباي',
        ];
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
