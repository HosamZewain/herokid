<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderActivityTimelineService;
use App\Services\Orders\OrderEditAudit;
use App\Support\AdminActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderEditAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_field_diffs_keep_removals_long_values_and_replacement_photos_but_exclude_unrelated_secrets(): void
    {
        $order = Order::create(['order_number' => 'HK-AUDIT', 'checkout_group_key' => 'CHK-AUDIT',
            'parent_name' => 'اسم سابق', 'notes' => str_repeat('ملاحظة قديمة ', 300), 'status' => 'new',
            'uploaded_photos' => ['orders/photos/1/old.jpg'],
            'delivery_details' => ['street' => 'شارع سابق', 'phone' => '01012345678', 'delivery_fee' => 59, 'api_key' => 'never-log-this', 'checkout_session_id' => 'private-session']]);
        $service = app(OrderEditAudit::class);
        $before = $service->snapshot(Order::whereKey($order)->get());
        $order->forceFill(['notes' => null, 'uploaded_photos' => ['orders/photos/1/new.jpg'],
            'delivery_details' => ['phone' => '01012345678', 'delivery_fee' => 62, 'api_key' => 'another-secret']])->save();
        $after = $service->snapshot(Order::whereKey($order)->get());
        $changes = $service->changes($before, $after);
        $admin = User::factory()->create(['role' => 'admin']);
        AdminActivityLogger::log('checkout.full_order_updated', subject: $order, properties: [
            'field_changes_version' => 1, 'field_changes' => $changes, 'reason' => 'تصحيح بيانات الطلب.',
            'token' => 'must-be-filtered',
        ], admin: $admin);
        $log = AdminActivityLog::sole();
        $stored = collect($log->properties['field_changes'])->keyBy('field');
        $this->assertSame(str_repeat('ملاحظة قديمة ', 300), $stored['orders.'.$order->id.'.notes']['old']);
        $this->assertNull($stored['orders.'.$order->id.'.notes']['new']);
        $this->assertNull($stored['orders.'.$order->id.'.delivery.street']['new']);
        $this->assertSame(hash('sha256', 'orders/photos/1/old.jpg'), $stored['orders.'.$order->id.'.photos']['old'][0]['reference']);
        $this->assertSame(hash('sha256', 'orders/photos/1/new.jpg'), $stored['orders.'.$order->id.'.photos']['new'][0]['reference']);
        $this->assertArrayNotHasKey('orders.'.$order->id.'.parent_name', $stored->all());
        $json = json_encode($log->properties);
        foreach (['never-log-this', 'another-secret', 'private-session', 'must-be-filtered', 'orders/photos/1/old.jpg'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame([], $service->changes($after, $after));
        $event = app(OrderActivityTimelineService::class)->forGroup(['orders' => [$order]])['events']->firstWhere('id', $log->id);
        $money = $event['details']->first(fn ($detail) => str_contains($detail['label'], 'الشحن المستحق'));
        $this->assertSame(format_money(59), $money['old_value']);
        $this->assertSame(format_money(62), $money['new_value']);
        $this->assertSame(str_repeat('ملاحظة قديمة ', 300), $event['details']->first(fn ($detail) => str_contains($detail['label'], 'ملاحظات الفريق'))['old_value']);
    }

    public function test_drawer_escapes_exact_values_and_shows_every_change_with_before_and_after(): void
    {
        $changes = collect(range(1, 15))->map(fn ($i) => ['field' => 'field.'.$i, 'label' => 'حقل '.$i,
            'old' => 'قبل '.$i, 'new' => '<script>alert('.$i.')</script>', 'format' => 'text'])->all();
        $order = Order::create(['order_number' => 'HK-DRAWER', 'status' => 'new']);
        $admin = User::factory()->create(['role' => 'admin']);
        AdminActivityLogger::log('checkout.full_order_updated', subject: $order,
            properties: ['field_changes_version' => 1, 'field_changes' => $changes], admin: $admin);
        $activity = app(OrderActivityTimelineService::class)->forGroup(['orders' => [$order]]);
        $html = view('admin.orders._activity-drawer', ['orderActivity' => $activity])->render();
        $this->assertStringContainsString('حقل 15', $html);
        $this->assertStringContainsString('قبل: ', $html);
        $this->assertStringContainsString('بعد: ', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(15)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(15)</script>', $html);
    }
}
