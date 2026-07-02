<?php

namespace Tests\Feature;

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
                'title' => 'قصة نشطة ' . $i,
                'slug' => 'active-story-' . $i,
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
            ->assertSee('href="' . route('admin.stories.edit', $target) . '"', false)
            ->assertSee('قصة قابلة للتعديل');
    }
}
