<?php

namespace Tests\Unit;

use App\Models\Story;
use App\Support\Seo;
use App\Support\StoryCover;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class StoryCoverUrlTest extends TestCase
{
    public function test_local_story_cover_has_a_stable_updated_at_version(): void
    {
        $story = new Story(['cover_image' => 'stories/example.jpg']);
        $story->updated_at = CarbonImmutable::createFromTimestamp(1787400000);

        $this->assertSame(
            Seo::imageUrl('/storage/stories/example.jpg').'?v=1787400000',
            $story->cover_url,
        );
    }

    public function test_changing_updated_at_changes_the_local_cover_version(): void
    {
        $story = new Story(['cover_image' => 'stories/example.jpg']);
        $story->updated_at = CarbonImmutable::createFromTimestamp(1787400000);
        $firstUrl = $story->cover_url;

        $story->updated_at = CarbonImmutable::createFromTimestamp(1787400300);

        $this->assertNotSame($firstUrl, $story->cover_url);
        $this->assertStringEndsWith('?v=1787400300', $story->cover_url);
    }

    public function test_external_cover_urls_are_not_modified(): void
    {
        $externalUrl = 'https://images.example.com/story.jpg?size=large';
        $story = new Story(['cover_image' => $externalUrl]);
        $story->updated_at = CarbonImmutable::createFromTimestamp(1787400000);

        $this->assertSame($externalUrl, $story->cover_url);
    }

    public function test_version_query_is_added_without_losing_existing_query_or_fragment(): void
    {
        $url = StoryCover::versionedUrl(
            'https://hero-kid.com/storage/stories/example.jpg?width=640#cover',
            1787400000,
        );

        $this->assertSame(
            'https://hero-kid.com/storage/stories/example.jpg?width=640&v=1787400000#cover',
            $url,
        );
    }
}
