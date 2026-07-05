<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStoriesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_story_list_shows_catalog_counts_views_gender_and_edit_title_link(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        for ($i = 1; $i <= 15; $i++) {
            Story::create([
                'title' => 'قصة نشطة '.$i,
                'slug' => 'active-story-'.$i,
                'language' => 'ar',
                'gender' => 'both',
                'price' => 299,
                'active' => true,
            ]);
        }

        Story::create([
            'title' => 'قصة معطلة',
            'slug' => 'inactive-story',
            'language' => 'ar',
            'gender' => 'boy',
            'price' => 299,
            'active' => false,
        ]);

        $target = Story::create([
            'title' => 'قصة قابلة للتعديل',
            'slug' => 'editable-story',
            'language' => 'ar',
            'gender' => 'girl',
            'price' => 299,
            'active' => true,
        ]);

        $this->get(route('stories.show', $target->slug))->assertOk();
        $this->get(route('stories.show', $target->slug))->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.stories.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['إجمالي القصص', '17', 'نشطة', '16', 'معطلة', '1'])
            ->assertSee('مشاهدات')
            ->assertDontSee('مرفقات')
            ->assertSee('بنت')
            ->assertSee('2')
            ->assertSee('href="'.route('admin.stories.edit', $target).'"', false)
            ->assertSee('قصة قابلة للتعديل');
    }

    public function test_admin_story_list_can_sort_by_views_and_order_count(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $fewViews = Story::create([
            'title' => 'قصة مشاهدة واحدة',
            'slug' => 'one-view-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 299,
            'active' => true,
        ]);
        $manyViews = Story::create([
            'title' => 'قصة مشاهدات كثيرة',
            'slug' => 'many-views-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 299,
            'active' => true,
        ]);
        $manyOrders = Story::create([
            'title' => 'قصة طلبات كثيرة',
            'slug' => 'many-orders-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 299,
            'active' => true,
        ]);

        $this->get(route('stories.show', $fewViews->slug))->assertOk();
        $this->get(route('stories.show', $manyViews->slug))->assertOk();
        $this->get(route('stories.show', $manyViews->slug))->assertOk();
        $this->get(route('stories.show', $manyViews->slug))->assertOk();

        $this->orderForStory($fewViews, 'HK-2026-SORT01');
        $this->orderForStory($manyOrders, 'HK-2026-SORT02');
        $this->orderForStory($manyOrders, 'HK-2026-SORT03');

        $this->actingAs($admin)
            ->get(route('admin.stories.index', ['sort' => 'views', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('sort=views', false)
            ->assertSee('direction=asc', false)
            ->assertSeeTextInOrder([
                'قصة مشاهدات كثيرة',
                'قصة مشاهدة واحدة',
                'قصة طلبات كثيرة',
            ]);

        $this->actingAs($admin)
            ->get(route('admin.stories.index', ['sort' => 'orders', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('sort=orders', false)
            ->assertSee('direction=asc', false)
            ->assertSeeTextInOrder([
                'قصة طلبات كثيرة',
                'قصة مشاهدة واحدة',
                'قصة مشاهدات كثيرة',
            ]);
    }

    private function orderForStory(Story $story, string $orderNumber): Order
    {
        return Order::create([
            'order_number' => $orderNumber,
            'parent_name' => 'Parent Name',
            'story_id' => $story->id,
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '201000000000'],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
    }
}
