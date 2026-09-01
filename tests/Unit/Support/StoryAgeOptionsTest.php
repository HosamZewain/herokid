<?php

namespace Tests\Unit\Support;

use App\Support\StoryAgeOptions;
use PHPUnit\Framework\TestCase;

class StoryAgeOptionsTest extends TestCase
{
    public function test_personalization_ages_cover_two_through_sixteen_inclusively(): void
    {
        $ages = StoryAgeOptions::forPersonalization();

        $this->assertSame(range(2, 16), $ages);
        $this->assertCount(15, $ages);
        $this->assertNotContains(1, $ages);
        $this->assertNotContains(17, $ages);
    }
}
