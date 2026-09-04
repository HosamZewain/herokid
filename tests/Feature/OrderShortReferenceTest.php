<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShortReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_receives_a_monthly_short_reference_without_changing_existing_identifiers(): void
    {
        $first = $this->createOrder('ORDER-ORIGINAL-1', 'CHK-ORIGINAL-GROUP', '2026-08-02 10:00:00');
        $sibling = $this->createOrder('ORDER-ORIGINAL-2', 'CHK-ORIGINAL-GROUP', '2026-08-02 10:01:00');
        $secondCheckout = $this->createOrder('ORDER-ORIGINAL-3', 'CHK-SECOND-GROUP', '2026-08-03 10:00:00');
        $septemberCheckout = $this->createOrder('ORDER-ORIGINAL-4', 'CHK-SEPTEMBER-GROUP', '2026-09-01 10:00:00');
        $nextAugustCheckout = $this->createOrder('ORDER-ORIGINAL-5', 'CHK-NEXT-AUGUST-GROUP', '2027-08-01 10:00:00');

        $this->assertSame('CHK-ORIGINAL-GROUP', $first->refresh()->checkout_group_key);
        $this->assertSame('ORDER-ORIGINAL-1', $first->order_number);
        $this->assertSame('HK08-1', $first->checkoutReference->short_reference);
        $this->assertSame('HK08-1', $sibling->checkoutReference->short_reference);
        $this->assertSame('HK08-2', $secondCheckout->checkoutReference->short_reference);
        $this->assertSame('HK09-1', $septemberCheckout->checkoutReference->short_reference);
        $this->assertSame('HK08-3', $nextAugustCheckout->checkoutReference->short_reference);
        $this->assertDatabaseCount('order_checkout_references', 4);
    }

    public function test_admin_can_display_search_export_and_confirm_deletion_with_the_short_reference(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->createOrder('ORDER-SEARCHABLE', 'CHK-SEARCHABLE-GROUP', '2026-08-04 10:00:00');
        $shortReference = $order->checkoutReference->short_reference;

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['q' => $shortReference]))
            ->assertOk()
            ->assertSee($shortReference)
            ->assertSee('CHK-SEARCHABLE-GROUP');

        $csv = $this->actingAs($admin)
            ->get(route('admin.orders.export', ['q' => $shortReference]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('المرجع المختصر', $csv);
        $this->assertStringContainsString($shortReference, $csv);
        $this->assertStringContainsString('CHK-SEARCHABLE-GROUP', $csv);

        $this->actingAs($admin)
            ->delete(route('admin.orders.groups.destroy', $order->id), [
                'deletion_reason' => 'طلب تجريبي لاختبار المرجع المختصر',
                'confirmation' => $shortReference,
            ])
            ->assertRedirect(route('admin.orders.index', ['view' => 'trash']));

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_customer_can_track_with_short_or_legacy_reference_and_equivalent_phone_formats(): void
    {
        $order = $this->createOrder('ORDER-TRACKABLE', 'CHK-TRACKABLE-GROUP', '2026-09-04 10:00:00');
        $shortReference = $order->checkoutReference->short_reference;

        $this->post(route('track.search'), [
            'order_number' => $shortReference,
            'phone' => '+201111111111',
        ])
            ->assertOk()
            ->assertSee($shortReference)
            ->assertDontSee('طلب رقم '.$order->order_number);

        $this->post(route('track.search'), [
            'order_number' => $order->order_number,
            'phone' => '01111111111',
        ])
            ->assertOk()
            ->assertSee($shortReference);
    }

    private function createOrder(string $orderNumber, string $checkoutGroup, string $createdAt): Order
    {
        $story = Story::create([
            'title' => 'قصة '.$orderNumber,
            'slug' => strtolower($orderNumber).'-'.uniqid(),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);

        return Order::create([
            'order_number' => $orderNumber,
            'checkout_group_key' => $checkoutGroup,
            'story_id' => $story->id,
            'parent_name' => 'ولي الأمر',
            'child_name' => 'طفل',
            'child_age' => 7,
            'child_gender' => 'boy',
            'language' => 'ar',
            'delivery_details' => [
                'checkout_group' => $checkoutGroup,
                'phone' => '01111111111',
            ],
            'uploaded_photos' => [],
            'status' => 'new',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
