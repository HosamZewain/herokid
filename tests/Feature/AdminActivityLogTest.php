<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_is_logged_without_credentials(): void
    {
        $admin = $this->admin();

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertRedirect();

        $log = AdminActivityLog::where('action', 'admin.login')->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(User::class, $log->subject_type);
        $this->assertSame($admin->id, $log->subject_id);
        $this->assertStringNotContainsString('password123', json_encode($log->properties));
    }

    public function test_admin_story_creation_is_logged_with_story_details(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.stories.store'), [
            'title' => 'قصة الاختبار',
            'slug' => 'test-story',
            'short_desc' => 'وصف قصير',
            'full_desc' => 'وصف كامل',
            'age_range' => '٤ - ٦ سنوات',
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'gender' => 'both',
            'price' => 149,
            'active' => 1,
        ])->assertRedirect(route('admin.stories.index'));

        $story = Story::where('slug', 'test-story')->firstOrFail();
        $log = AdminActivityLog::where('action', 'story.created')->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Story::class, $log->subject_type);
        $this->assertSame($story->id, $log->subject_id);
        $this->assertSame('قصة الاختبار', $log->properties['story']['title']);
    }

    public function test_order_view_and_status_update_are_logged_with_details(): void
    {
        $admin = $this->admin();
        $story = Story::create([
            'title' => 'قصة الطلب',
            'slug' => 'order-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 149,
            'active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'HK-TEST-001',
            'parent_name' => 'Parent',
            'story_id' => $story->id,
            'child_name' => 'رنا',
            'child_age' => 5,
            'child_gender' => 'girl',
            'language' => 'ar',
            'status' => 'new',
            'delivery_details' => [],
            'uploaded_photos' => [],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk();

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'order.viewed',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.update', $order), [
                'status' => 'under_review',
                'admin_notes' => 'تمت المراجعة',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $log = AdminActivityLog::where('action', 'order.status_updated')->firstOrFail();

        $this->assertSame('new', $log->properties['status']['old']);
        $this->assertSame('under_review', $log->properties['status']['new']);
        $this->assertTrue($log->properties['status']['changed']);
    }

    public function test_admin_can_review_activity_logs_and_details(): void
    {
        $admin = $this->admin();
        $log = AdminActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'settings.updated',
            'description' => 'تحديث إعدادات الموقع.',
            'route_name' => 'admin.settings.update',
            'method' => 'PUT',
            'url' => 'https://hero-kid.com/admin/settings',
            'ip_address' => '127.0.0.1',
            'properties' => [
                'changed_settings' => [
                    'site_name' => [
                        'old' => 'HeroKid',
                        'new' => 'HeroKid Story',
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('سجل نشاط الإدارة')
            ->assertSee('settings.updated')
            ->assertSee('تحديث إعدادات الموقع');

        $this->actingAs($admin)
            ->get(route('admin.activity-logs.show', $log))
            ->assertOk()
            ->assertSee('تفاصيل سجل النشاط')
            ->assertSee('changed_settings')
            ->assertSee('HeroKid Story');
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => 'password123',
            'role' => 'admin',
        ]);
    }
}
