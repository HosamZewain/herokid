<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_dashboard_uses_whatsapp_url_from_admin_settings(): void
    {
        Cache::forget('site_settings');

        Setting::create([
            'key' => 'whatsapp_url',
            'value' => 'https://wa.me/209999999999',
        ]);

        $user = User::factory()->create();
        $story = Story::create([
            'title' => 'قصة الاختبار',
            'slug' => 'dashboard-whatsapp-story',
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 149,
            'active' => true,
        ]);

        Order::create([
            'order_number' => 'HK-2026-WA001',
            'user_id' => $user->id,
            'parent_name' => $user->name,
            'story_id' => $story->id,
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '201000000000'],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('https://wa.me/209999999999?text=', false)
            ->assertDontSee('https://wa.me/201000000000', false);
    }
}
