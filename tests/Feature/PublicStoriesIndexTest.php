<?php

namespace Tests\Feature;

use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class PublicStoriesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_stories_index_defaults_to_twenty_stories_per_page(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            Story::create([
                'title' => 'قصة عامة '.$i,
                'slug' => 'public-story-'.$i,
                'language' => 'ar',
                'gender' => 'both',
                'price' => 149,
                'active' => true,
            ]);
        }

        $response = $this->get(route('stories.index'))
            ->assertOk()
            ->assertViewIs('front.shop.index')
            ->assertViewHas('items');

        $stories = $response->viewData('items');

        $this->assertInstanceOf(LengthAwarePaginator::class, $stories);
        $this->assertSame(20, $stories->perPage());
        $this->assertCount(20, $stories->items());
    }

    public function test_public_stories_index_still_allows_explicit_page_size(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Story::create([
                'title' => 'قصة مخصصة '.$i,
                'slug' => 'custom-page-size-story-'.$i,
                'language' => 'ar',
                'gender' => 'both',
                'price' => 149,
                'active' => true,
            ]);
        }

        $response = $this->get(route('stories.index', ['per_page' => 12]))
            ->assertOk()
            ->assertViewIs('front.shop.index')
            ->assertViewHas('items');

        $stories = $response->viewData('items');

        $this->assertSame(12, $stories->perPage());
        $this->assertCount(12, $stories->items());
    }
}
