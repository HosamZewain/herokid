<?php

namespace Tests\Feature;

use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoSpeedJourneyAuditFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_content_pages_have_public_cache_headers(): void
    {
        $story = Story::create([
            'title' => 'المخترع العبقري',
            'slug' => 'little-inventor',
            'short_desc' => 'قصة قصيرة عن الاختراع.',
            'full_desc' => 'قصة مخصصة تجعل الطفل بطل مغامرة تعليمية.',
            'age_range' => '6-8',
            'language' => 'ar',
            'price' => 149,
            'active' => true,
        ]);

        $contactResponse = $this->get(route('contact'))->assertOk();
        $this->assertStringContainsString('public', (string) $contactResponse->headers->get('Cache-Control'));

        $storyResponse = $this->get(route('stories.show', $story->slug))->assertOk();
        $this->assertStringContainsString('public', (string) $storyResponse->headers->get('Cache-Control'));
    }

    public function test_public_pages_use_optimized_local_assets_and_no_empty_hash_links(): void
    {
        $story = Story::create([
            'title' => 'رحلة الغابة',
            'slug' => 'forest-trip',
            'short_desc' => 'رحلة لطيفة داخل الغابة.',
            'full_desc' => 'قصة مخصصة عن التعاون والخيال.',
            'age_range' => '4-6',
            'language' => 'ar',
            'price' => 149,
            'active' => true,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('/images/logo-192.png', false)
            ->assertDontSee('/images/logo.png', false)
            ->assertDontSee('href="#"', false)
            ->assertDontSee('images.unsplash.com', false)
            ->assertSee('aria-label="فتح القائمة"', false);

        $this->get(route('stories.index'))
            ->assertOk()
            ->assertDontSee('images.unsplash.com', false);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('/images/site/featured_generic.png', false)
            ->assertDontSee('images.unsplash.com', false)
            ->assertSee('for="child_name"', false)
            ->assertSee('for="child_age"', false)
            ->assertSee('for="child_gender"', false);

        $this->get(route('contact'))
            ->assertOk()
            ->assertDontSee('href="#"', false)
            ->assertSee('for="contact_name"', false)
            ->assertSee('for="contact_message"', false);
    }
}
