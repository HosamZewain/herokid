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
            'cover_image' => 'stories/forest-trip.jpg',
        ]);

        $versionedCoverUrl = $story->cover_url;

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
            ->assertSee('/images/site/featured_generic_herokid_v2.png', false)
            ->assertSee($versionedCoverUrl, false)
            ->assertSee('data-story-cover', false)
            ->assertSee('data-original-src="'.$versionedCoverUrl.'"', false)
            ->assertSee('window.HeroKidStoryCover?.handleError(this)', false)
            ->assertSee('property="og:image"', false)
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

    public function test_story_cover_cache_rules_exclude_mutable_story_uploads_from_immutable_caching(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('SetEnvIf Request_URI "^/storage/stories/" HEROKID_STORY_COVER=1', $htaccess);
        $this->assertStringContainsString('max-age=31536000, immutable" env=!HEROKID_STORY_COVER', $htaccess);
        $this->assertStringContainsString('max-age=300, must-revalidate" env=HEROKID_STORY_COVER', $htaccess);
    }
}
