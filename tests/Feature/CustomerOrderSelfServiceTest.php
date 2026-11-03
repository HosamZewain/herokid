<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\CodexSceneRun;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\OrderShippingMergeRequest;
use App\Models\Permission;
use App\Models\RoboDeskConversationSetting;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\CustomerOrderSelfService;
use App\Services\Orders\OrderAutomationSummary;
use App\Services\RoboDesk\OrderConfirmationService;
use App\Services\RoboDesk\OrderConfirmationTransport;
use App\Services\RoboDesk\RoboDeskOutbox;
use App\Support\OrderStatusRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerOrderSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_requires_matching_reference_and_phone_then_grants_temporary_access(): void
    {
        $order = $this->order('ACCESS', '01012345678');
        $reference = $order->checkoutReference->short_reference;

        $this->get(route('track.show', $reference))->assertNotFound();

        $this->post(route('track.search'), [
            'order_number' => $reference,
            'phone' => '01099999999',
        ])->assertRedirect()->assertSessionHas('error');

        $this->post(route('track.search'), [
            'order_number' => strtolower($reference),
            'phone' => '+201012345678',
        ])->assertOk()
            ->assertSee($reference)
            ->assertSee('حالة الدفع')
            ->assertSee('تعديل الطلب')
            ->assertDontSee('ملاحظة داخلية سرية');

        $this->get(route('track.show', $reference))->assertOk()->assertSee($reference);
    }

    public function test_customer_can_update_an_unstarted_checkout_and_child_data(): void
    {
        $first = $this->order('EDIT-A', '01012345678', 'new', 'الطفل الأول');
        $second = $this->order('EDIT-B', '01012345678', 'new', 'الطفل الثاني', $first->checkout_group_key);
        $reference = $first->checkoutReference->short_reference;
        [$country, $governorate] = $this->delivery();

        $this->post(route('track.search'), ['order_number' => $reference, 'phone' => '01012345678'])->assertOk();
        $this->get(route('track.edit', $reference))->assertOk()->assertSee('الطفل الأول')->assertSee('الطفل الثاني');

        $this->put(route('track.update', $reference), [
            'parent_name' => 'ولي الأمر المعدل',
            'phone' => '01087654321',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'مدينة نصر',
            'street' => 'شارع جديد',
            'address_details' => 'الدور الثاني',
            'children' => [
                $first->id => ['child_name' => 'الطفل الأول الجديد', 'child_age' => 7, 'child_gender' => 'boy'],
                $second->id => ['child_name' => 'الطفل الثاني الجديد', 'child_age' => 9, 'child_gender' => 'girl'],
            ],
        ])->assertRedirect(route('track.show', $reference))->assertSessionHas('success');

        $this->assertSame('ولي الأمر المعدل', $first->fresh()->parent_name);
        $this->assertSame('01087654321', data_get($second->fresh()->delivery_details, 'phone'));
        $this->assertSame('الطفل الأول الجديد', $first->fresh()->child_name);
        $this->assertSame('الطفل الثاني الجديد', $second->fresh()->child_name);
        $this->assertSame('الطفل الأول الجديد', data_get($first->items()->first()->personalization_snapshot, 'child_name'));
    }

    public function test_customer_can_cancel_all_records_of_an_unpaid_unstarted_checkout(): void
    {
        $first = $this->order('CANCEL-A', '01012345678');
        $second = $this->order('CANCEL-B', '01012345678', 'new', null, $first->checkout_group_key);
        $reference = $first->checkoutReference->short_reference;

        $this->post(route('track.search'), ['order_number' => $reference, 'phone' => '01012345678'])->assertOk();
        $this->post(route('track.cancel', $reference), ['confirm_cancel' => '1'])
            ->assertRedirect(route('track.show', $reference));

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->get(route('track.show', $reference))->assertOk()->assertDontSee('تعديل الطلب');
    }

    public function test_started_checkout_only_allows_parent_notes_to_be_updated(): void
    {
        $order = $this->order('LOCKED', '01012345678', 'generating', 'طفل تحت الإنتاج');
        $order->update(['payment_status' => 'partially_paid', 'paid_amount_cents' => 10_000]);
        $reference = $order->checkoutReference->short_reference;

        $this->post(route('track.search'), ['order_number' => $reference, 'phone' => '01012345678'])
            ->assertOk()
            ->assertDontSee('تعديل الطلب')
            ->assertSee('تحديث ملاحظات ولي الأمر')
            ->assertDontSee('إلغاء الطلب');
        $this->get(route('track.edit', $reference))
            ->assertOk()
            ->assertDontSee('بيانات ولي الأمر والتوصيل')
            ->assertSee('ملاحظات ولي الأمر');
        $this->put(route('track.update', $reference), [
            'children' => [
                $order->id => ['parent_notes' => 'يرجى استخدام اللون الأزرق في التصميم.'],
            ],
        ])->assertRedirect(route('track.show', $reference));

        $order->refresh();
        $this->assertSame('يرجى استخدام اللون الأزرق في التصميم.', $order->parent_notes);
        $this->assertSame('يرجى استخدام اللون الأزرق في التصميم.', data_get($order->items()->first()->personalization_snapshot, 'parent_notes'));
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'notes' => 'قام العميل بتحديث ملاحظات ولي الأمر من صفحة المتابعة بعد بدء التنفيذ.',
        ]);
        $this->post(route('track.cancel', $reference), ['confirm_cancel' => '1'])
            ->assertSessionHasErrors('order');

        $order->update(['shipping_status' => 'delivered']);
        $this->get(route('track.show', $reference))->assertOk()->assertDontSee('تحديث ملاحظات ولي الأمر');
        $this->get(route('track.edit', $reference))->assertForbidden();
    }

    public function test_checkout_phone_lookup_returns_only_active_orders_and_allowed_actions(): void
    {
        $active = $this->order('ACTIVE', '01012345678');
        $this->order('DONE', '01012345678', 'delivered');
        $this->order('OTHER', '01000000000');

        $this->postJson(route('checkout.active-orders'), ['phone' => '+201012345678'])
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.reference', $active->checkoutReference->short_reference)
            ->assertJsonPath('orders.0.status_label', OrderStatusRegistry::label('order', 'new'))
            ->assertJsonPath('orders.0.can_cancel', true)
            ->assertJsonPath('orders.0.can_merge', true);
    }

    public function test_customer_merge_decision_combines_checkouts_and_keeps_one_delivery_fee(): void
    {
        $previous = $this->order('PREVIOUS', '01012345678');
        $new = $this->order('NEW', '01012345678');
        $previousReference = $previous->checkoutReference->short_reference;
        $newKey = $new->checkout_group_key;
        $request = Request::create('/checkout', 'POST');
        $request->setLaravelSession($this->app['session.store']);

        $message = app(CustomerOrderSelfService::class)->applyCheckoutDecision(
            $request,
            $new,
            'merge',
            $previousReference,
            '01012345678',
        );

        $this->assertStringContainsString('تم دمج الطلبين', $message);
        $this->assertSame([$newKey], Order::query()->pluck('checkout_group_key')->unique()->values()->all());
        $this->assertSame(45_000, (int) data_get($new->fresh()->delivery_details, 'total') * 100);
    }

    private function enableConfirmations(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['queue.default' => 'redis', 'order_process.confirmation_owner' => 'legacy',
            'robodesk.confirmation.enabled' => true, 'robodesk.confirmation.contract_ready' => true,
            'robodesk.confirmation.after_order_id' => 0, 'robodesk.confirmation.audience' => 'all_new', 'robodesk.confirmation_only' => true]);
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'test-only', 'conversation_limit' => 50]);
    }

    private function mergeCustomer(Order $new, Order $previous): void
    {
        $request = Request::create('/checkout', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        app(CustomerOrderSelfService::class)->applyCheckoutDecision($request, $new, 'merge', $previous->checkoutReference->short_reference, '01012345678');
    }

    public function test_customer_can_merge_confirmation_only_and_only_new_combined_confirmation_is_sent(): void
    {
        $this->enableConfirmations();
        $previous = $this->order('CONFIRM-OLD', '01012345678');
        $new = $this->order('CONFIRM-NEW', '01012345678');
        $old = RoboDeskIntegrationEvent::where('checkout_group_key', $previous->checkoutGroupKey())->where('event_type', 'order.pending_confirmation')->firstOrFail();
        $old->update(['status' => 'succeeded', 'attempts' => 1, 'response_payload' => ['message_id' => 'old-wamid']]);
        $current = RoboDeskIntegrationEvent::where('checkout_group_key', $new->checkoutGroupKey())->where('event_type', 'order.pending_confirmation')->firstOrFail();
        $this->assertTrue(app(AutomationAssignment::class)->owns($previous));
        $this->assertTrue(app(AutomationAssignment::class)->owns($new));
        $this->mergeCustomer($new, $previous);
        $this->assertSame($new->checkoutGroupKey(), $previous->fresh()->checkoutGroupKey());
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('CHK-CUSTOMER-CONFIRM-OLD', $old->fresh()->checkout_group_key);
        $this->assertSame('old-wamid', $old->fresh()->response_payload['message_id']);
        $this->assertSame($new->checkoutGroupKey(), data_get($old->fresh()->payload, 'confirmation.superseded_by_checkout'));
        $this->assertFalse(app(OrderConfirmationService::class)->canSend($old->fresh()));
        $conversation = WhatsAppConversation::create(['account_key' => 'primary', 'phone_hash' => hash('sha256', '+201012345678'), 'phone' => '+201012345678']);
        $reply = WhatsAppConversationMessage::create(['conversation_id' => $conversation->id, 'remote_hash' => hash('sha256', 'late-reply'),
            'remote_id' => 'late-reply', 'direction' => 'inbound', 'kind' => 'text', 'body' => 'تأكيد الطلب', 'sent_at' => now(), 'fingerprint' => str_repeat('b', 64)]);
        app(OrderConfirmationService::class)->confirmPolledMessage($old->fresh(), $reply, '201012345678', 'reply');
        $this->assertNull($reply->fresh()->confirmation_event_id);
        $this->assertSame('new', $previous->fresh()->status);

        $this->mock(OrderConfirmationTransport::class, function ($mock) use ($new) {
            $mock->shouldReceive('send')->once()->withArgs(fn ($data) => $data['checkout_reference'] === $new->checkoutGroupKey()
                && count($data['order_numbers']) === 2 && $data['amounts']['delivery'] === 50 && $data['amounts']['total'] === 450)
                ->andReturn(['message_id' => 'combined-wamid', 'interaction_id' => 'synthetic']);
        });
        app()->call([new SendOrderConfirmationJob($old->id), 'handle']);
        app()->call([new SendOrderConfirmationJob($current->id), 'handle']);
        app()->call([new SendOrderConfirmationJob($current->id), 'handle']);
        $this->assertSame('succeeded', $current->fresh()->status);
        $this->assertSame(0, $new->fresh()->paid_amount_cents);
        $this->assertSame('new', $previous->fresh()->status);
    }

    public function test_pending_old_confirmation_is_retired_and_cannot_be_released_after_customer_merge(): void
    {
        $this->enableConfirmations();
        $previous = $this->order('PENDING-OLD', '01012345678');
        $new = $this->order('PENDING-NEW', '01012345678');
        $old = RoboDeskIntegrationEvent::where('checkout_group_key', $previous->checkoutGroupKey())->where('event_type', 'order.pending_confirmation')->firstOrFail();
        $this->mergeCustomer($new, $previous);
        $this->assertSame('cancelled', $old->fresh()->status);
        try {
            app(RoboDeskOutbox::class)->release($old->fresh());
            $this->fail('A retired confirmation must not be re-sent');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_customer_merge_does_not_touch_inflight_or_uncertain_confirmation(): void
    {
        $this->enableConfirmations();
        foreach (['processing', 'unknown'] as $status) {
            $previous = $this->order('BUSY-OLD-'.$status, '01012345678');
            $new = $this->order('BUSY-NEW-'.$status, '01012345678');
            $old = RoboDeskIntegrationEvent::where('checkout_group_key', $previous->checkoutGroupKey())->where('event_type', 'order.pending_confirmation')->firstOrFail();
            $old->update(['status' => $status, 'attempts' => 1]);
            $this->mergeCustomer($new, $previous);
            $this->assertDatabaseHas('order_shipping_merge_requests', ['target_order_id' => $new->id, 'source_order_id' => $previous->id, 'status' => 'pending']);
            $this->assertSame($status, $old->fresh()->status);
            $this->assertNotSame($new->checkoutGroupKey(), $previous->fresh()->checkoutGroupKey());
        }
        $this->assertDatabaseCount('order_group_merge_aliases', 0);
    }

    public function test_customer_merge_does_not_bypass_production_reservation_or_different_customer(): void
    {
        $this->enableConfirmations();
        $previous = $this->order('WORK-OLD', '01012345678');
        $new = $this->order('WORK-NEW', '01012345678');
        CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $previous->id,
            'checkout_key' => $previous->checkoutGroupKey(), 'production_kind' => 'story', 'scene_count' => 13,
            'status' => 'running', 'previous_status' => 'new_confirmed', 'active_slot' => 1, 'source' => 'automatic', 'protocol' => 1, 'snapshot' => [], 'fingerprint' => str_repeat('a', 64)]);
        $this->mergeCustomer($new, $previous);
        $this->assertDatabaseHas('order_shipping_merge_requests', ['target_order_id' => $new->id, 'status' => 'pending']);
        $this->assertSame('running', CodexSceneRun::sole()->status);
        $this->assertDatabaseCount('order_group_merge_aliases', 0);
        $this->assertNotSame($new->checkoutGroupKey(), $previous->fresh()->checkoutGroupKey());
        $other = $this->order('OTHER', '01199999999');
        try {
            $this->mergeCustomer($new, $other);
            $this->fail('Different customer must remain inaccessible');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('previous_order_action', $e->errors());
        }
    }

    public function test_progressed_checkout_accepts_new_order_with_auditable_shipping_review_and_keeps_prices(): void
    {
        $this->enableConfirmations();
        Http::preventStrayRequests();
        Queue::fake();
        $previous = $this->order('PREVIEW-OLD', '01012345678', 'preview_uploaded');
        $new = $this->order('PREVIEW-NEW', '01012345678');
        $before = $previous->getAttributes();
        $this->post(route('checkout.active-orders'), ['phone' => '01012345678'])->assertOk()->assertJsonFragment(['can_request_shipping_merge' => true]);
        $this->mergeCustomer($new, $previous);
        $this->mergeCustomer($new, $previous);
        $review = OrderShippingMergeRequest::sole();
        $this->assertSame($before, $previous->fresh()->getAttributes());
        $this->assertSame(50, data_get($new->fresh()->delivery_details, 'delivery_fee'));
        $this->assertDatabaseCount('order_admin_notes', 2);
        $this->assertDatabaseCount('order_group_merge_aliases', 0);
        $summary = app(OrderAutomationSummary::class)->forKeys([$previous->checkoutGroupKey(), $new->checkoutGroupKey()]);
        foreach ($summary as $row) {
            $this->assertStringContainsString('ضم الشحن', json_encode($row, JSON_UNESCAPED_UNICODE));
        }
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.view_all'])->pluck('id'));
        $this->actingAs($actor);
        $this->get(route('admin.orders.groups.show', $new))->assertOk()->assertSee('طلب العميل ضم الشحن')->assertSee($previous->checkoutReference->short_reference);
        $url = route('admin.orders.groups.shipping-merge.resolve', [$new, $review]);
        $this->post($url, ['resolution' => ''])->assertSessionHasErrors('resolution');
        $this->assertSame('pending', $review->fresh()->status);
        $unrelated = $this->order('UNRELATED-REVIEW', '01199999999');
        $this->post(route('admin.orders.groups.shipping-merge.resolve', [$unrelated, $review]), ['resolution' => 'يجب ألا يسمح بهذا'])->assertNotFound();
        $actor->permissions()->detach(Permission::where('key', 'orders.view_all')->value('id'));
        $this->actingAs($actor->fresh());
        $this->post($url, ['resolution' => 'قرار بدون صلاحية'])->assertForbidden();
        $actor->permissions()->attach(Permission::where('key', 'orders.view_all')->value('id'));
        $this->actingAs($actor->fresh());
        $this->post($url, ['resolution' => 'تم التواصل مع العميل وتأكيد الشحن منفصلًا'])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $review->fresh()->status);
        $this->assertSame($actor->id, $review->fresh()->resolved_by);
        $this->assertSame(50, data_get($new->fresh()->delivery_details, 'delivery_fee'));
        Http::assertNothingSent();
    }

    private function order(
        string $suffix,
        string $phone,
        string $status = 'new',
        ?string $childName = null,
        ?string $groupKey = null,
    ): Order {
        $order = Order::create([
            'order_number' => 'HK-CUSTOMER-'.$suffix,
            'checkout_group_key' => $groupKey ?: 'CHK-CUSTOMER-'.$suffix,
            'parent_name' => 'ولي الأمر',
            'child_name' => $childName,
            'child_age' => $childName ? 6 : null,
            'child_gender' => $childName ? 'boy' : null,
            'status' => $status,
            'payment_status' => 'unpaid',
            'printing_status' => 'not_started',
            'shipping_status' => 'not_ready',
            'delivery_details' => [
                'phone' => $phone,
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'القاهرة',
                'street' => 'شارع الاختبار',
                'address_details' => 'الدور الأول',
                'address' => 'شارع الاختبار - الدور الأول',
                'delivery_fee' => 50,
                'subtotal' => 200,
                'total' => 250,
            ],
            'uploaded_photos' => [],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'title' => 'منتج اختبار',
            'unit_price_cents' => 20_000,
            'quantity' => 1,
            'total_price_cents' => 20_000,
            'personalization_mode' => $childName ? 'collect_child_details' : 'none',
            'personalization_snapshot' => $childName ? ['child_name' => $childName, 'child_age' => 6, 'child_gender' => 'boy'] : [],
        ]);
        $order->statusLogs()->create(['status' => $status, 'notes' => 'ملاحظة داخلية سرية']);

        return $order->fresh(['checkoutReference', 'items', 'statusLogs']);
    }

    /** @return array{DeliveryCountry, DeliveryGovernorate} */
    private function delivery(): array
    {
        $country = DeliveryCountry::query()->where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::query()
            ->where('delivery_country_id', $country->id)
            ->where('name', 'القاهرة')
            ->firstOrFail();
        $country->update(['active' => true]);
        $governorate->update(['active' => true]);

        return [$country, $governorate];
    }
}
