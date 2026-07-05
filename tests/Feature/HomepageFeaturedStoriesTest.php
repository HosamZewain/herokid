<?php

namespace Tests\Feature;

use App\Models\Story;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageFeaturedStoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_featured_story_section_uses_eight_active_stories_and_revalidates_cache(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Story::create([
                'title' => 'قصة الصفحة الرئيسية '.$i,
                'slug' => 'homepage-story-'.$i,
                'language' => 'ar',
                'gender' => 'both',
                'price' => 149,
                'active' => true,
            ]);
        }

        Story::create([
            'title' => 'قصة غير مفعلة',
            'slug' => 'inactive-homepage-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 149,
            'active' => false,
        ]);

        $response = $this->get(route('home'))
            ->assertOk()
            ->assertViewHas('featuredStories');

        $featuredStories = $response->viewData('featuredStories');

        $this->assertInstanceOf(Collection::class, $featuredStories);
        $this->assertCount(8, $featuredStories);
        $this->assertTrue($featuredStories->every(fn (Story $story) => $story->active));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }
}
