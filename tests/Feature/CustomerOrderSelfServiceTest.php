<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Services\Orders\CustomerOrderSelfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
            ->assertJsonPath('orders.0.status_label', 'طلب جديد')
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
