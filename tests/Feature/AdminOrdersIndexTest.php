<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrdersIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_number_parent_name_and_story_title_link_to_admin_pages_from_orders_index(): void
    {
        [$admin, $customer, $story, $order] = $this->linkedOrderFixture();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('href="' . route('admin.orders.show', $order) . '"', false)
            ->assertSee('#HK-2026-LINKS1')
            ->assertSee('href="' . route('admin.customers.show', 'user-' . $customer->id) . '"', false)
            ->assertSee('Customer Parent')
            ->assertSee('href="' . route('admin.stories.edit', $story) . '"', false)
            ->assertSee('رحلة القمر قبل النوم');
    }

    public function test_order_details_links_order_parent_and_story_to_admin_pages(): void
    {
        [$admin, $customer, $story, $order] = $this->linkedOrderFixture();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('href="' . route('admin.orders.show', $order) . '"', false)
            ->assertSee('#HK-2026-LINKS1')
            ->assertSee('href="' . route('admin.customers.show', 'user-' . $customer->id) . '"', false)
            ->assertSee('Customer Parent')
            ->assertSee('href="' . route('admin.stories.edit', $story) . '"', false)
            ->assertSee('رحلة القمر قبل النوم');
    }

    private function linkedOrderFixture(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);
        $customer = User::factory()->create([
            'role' => 'customer',
            'name' => 'Customer Parent',
            'phone' => '201000000000',
        ]);
        $story = Story::create([
            'title' => 'رحلة القمر قبل النوم',
            'slug' => 'moon-bedtime-trip',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 299,
            'active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'HK-2026-LINKS1',
            'user_id' => $customer->id,
            'parent_name' => 'Customer Parent',
            'story_id' => $story->id,
            'child_name' => 'مراد',
            'child_age' => 3,
            'child_gender' => 'boy',
            'language' => 'ar',
            'status' => 'new',
            'delivery_details' => [
                'phone' => '201000000000',
            ],
            'uploaded_photos' => [],
        ]);

        return [$admin, $customer, $story, $order];
    }
}
